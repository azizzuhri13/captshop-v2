import type {
  AuthenticationCreds,
  AuthenticationState,
  SignalDataTypeMap,
  SignalKeyStore,
} from 'baileys';
import { BufferJSON, initAuthCreds, proto } from 'baileys';
import type { Db } from './db.ts';
import { withImmediateTransaction } from './db.ts';

/**
 * SQLite-backed Baileys auth state.
 *
 * Replaces `useMultiFileAuthState`, which is documented by Baileys itself as
 * unsuitable for production: it writes one JSON file per Signal key (thousands
 * of files, no atomicity across keys) and leaves the material in whatever
 * directory the operator chose.
 *
 * Guarantees here:
 *  - `set()` is a single IMMEDIATE transaction: either every key in the batch
 *    lands or none do, so Baileys never sees a half-applied session update.
 *  - Writes are synchronous (better-sqlite3) and complete before `set()`
 *    returns, so "persist before returning" holds without extra fsync logic —
 *    the connection runs `synchronous = FULL`.
 *  - `saveCreds()` upserts the single creds row in the same durable manner.
 *  - Nothing here logs key material.
 */

type AuthKeyRow = { data: string };

/**
 * Raised when persisted auth material cannot be read.
 *
 * The message is a fixed, sanitized code — never the stored payload — so it can
 * be surfaced to an operator and logged without leaking key material.
 */
export class AuthStoreCorruptError extends Error {
  override name = 'AuthStoreCorruptError';
  readonly code = 'AUTH_STORE_CORRUPT';

  constructor(detail?: string) {
    super(
      'AUTH_STORE_CORRUPT: persisted WhatsApp auth state could not be read. ' +
        'Refusing to start so the existing session is not overwritten. ' +
        'Restore the database from backup or re-pair under explicit operator review.',
    );
    // Keep the underlying reason for debugging without exposing row contents.
    if (detail) this.cause = detail;
  }
}

export type AuthStore = {
  state: AuthenticationState;
  saveCreds: () => Promise<void>;
  /** True when creds indicate a completed pairing. */
  isRegistered: () => boolean;
  /** Reads creds without mutating them. */
  readCreds: () => AuthenticationCreds;
};

function serialize(value: unknown): string {
  return JSON.stringify(value, BufferJSON.replacer);
}

function deserialize<T>(raw: string): T {
  return JSON.parse(raw, BufferJSON.reviver) as T;
}

/**
 * Note: this store intentionally exposes no clear/reset API. v1 has no session
 * reset path (spec excludes dashboard pairing/logout/session reset), so nothing
 * in the running service can delete auth material.
 */
export function createSqliteAuthStore(db: Db): AuthStore {
  const selectCreds = db.prepare(`SELECT data FROM auth_creds WHERE id = 1`);
  const upsertCreds = db.prepare(
    `INSERT INTO auth_creds (id, data, updated_at) VALUES (1, ?, ?)
     ON CONFLICT(id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at`,
  );
  const selectKey = db.prepare(`SELECT data FROM auth_keys WHERE type = ? AND id = ?`);
  const upsertKey = db.prepare(
    `INSERT INTO auth_keys (type, id, data, updated_at) VALUES (?, ?, ?, ?)
     ON CONFLICT(type, id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at`,
  );
  const deleteKey = db.prepare(`DELETE FROM auth_keys WHERE type = ? AND id = ?`);

  const loadCreds = (): AuthenticationCreds => {
    const row = selectCreds.get() as AuthKeyRow | undefined;
    if (!row) return initAuthCreds();
    try {
      const parsed = deserialize<AuthenticationCreds>(row.data);
      // A structurally wrong row is as dangerous as unparseable JSON: pairing
      // code would silently start from a fresh identity.
      if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
        throw new AuthStoreCorruptError('creds row did not deserialize to an object');
      }
      return parsed;
    } catch (error) {
      // Fail closed. Silently re-initialising would mask corruption and could
      // overwrite live session material on the next saveCreds(), effectively
      // forcing an accidental re-pair. The stored row is left untouched.
      throw new AuthStoreCorruptError(
        error instanceof Error ? error.message : 'creds row could not be deserialized',
      );
    }
  };

  const creds = loadCreds();
  // Keep the live object identity: Baileys mutates `creds` in place and relies
  // on `saveCreds()` seeing those mutations.
  const liveCreds = creds;

  const keys: SignalKeyStore = {
    get: async <T extends keyof SignalDataTypeMap>(type: T, ids: string[]) => {
      const result: { [id: string]: SignalDataTypeMap[T] } = {};
      const rows = db.transaction((wanted: string[]) => {
        const found: Array<[string, string]> = [];
        for (const id of wanted) {
          const row = selectKey.get(type, id) as AuthKeyRow | undefined;
          if (row) found.push([id, row.data]);
        }
        return found;
      })(ids);

      for (const [id, raw] of rows) {
        let value: unknown;
        try {
          value = deserialize(raw);
        } catch {
          continue; // skip an unreadable key instead of poisoning the whole batch
        }
        if (type === 'app-state-sync-key' && value) {
          value = proto.Message.AppStateSyncKeyData.fromObject(value as object);
        }
        result[id] = value as SignalDataTypeMap[T];
      }
      return result;
    },

    set: async (data) => {
      withImmediateTransaction(db, () => {
        const now = Date.now();
        for (const [type, byId] of Object.entries(data)) {
          if (!byId) continue;
          for (const [id, value] of Object.entries(byId as Record<string, unknown>)) {
            if (value === null || value === undefined) {
              deleteKey.run(type, id);
            } else {
              upsertKey.run(type, id, serialize(value), now);
            }
          }
        }
      });
    },
  };

  return {
    state: { creds: liveCreds, keys },
    saveCreds: async () => {
      upsertCreds.run(serialize(liveCreds), Date.now());
    },
    isRegistered: () => liveCreds.registered === true,
    readCreds: () => liveCreds,
  };
}
