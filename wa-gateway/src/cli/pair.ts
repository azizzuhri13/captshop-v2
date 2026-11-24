import { parseArgs } from 'node:util';
import { loadConfig, ConfigError } from '../config.ts';
import { createLogger } from '../logger.ts';
import { openDatabase } from '../db.ts';
import { createSqliteAuthStore, AuthStoreCorruptError } from '../auth-store.ts';
import type { AuthStore } from '../auth-store.ts';
import { SessionStore } from '../session-store.ts';
import { normalizePhone } from '../phone.ts';
import { WhatsAppConnection } from '../connection.ts';

/**
 * `npm run pair -- --phone 62...`
 *
 * CLI-only pairing (spec: no dashboard pairing endpoint in v1).
 *
 * Guarantees:
 *  - the pairing code is written to the operator's terminal exactly once and is
 *    never logged, persisted, or echoed by any other code path;
 *  - the phone must be digits-only with a country code (62...);
 *  - an already-registered session is refused outright. v1 has no reset path:
 *    discarding a live session is destructive and out of scope (spec excludes
 *    dashboard pairing/logout/session reset). A maintenance command can be
 *    added later under its own review.
 */
async function main(): Promise<number> {
  let values: { phone?: string; 'timeout-seconds'?: string };
  try {
    ({ values } = parseArgs({
      options: {
        phone: { type: 'string' },
        'timeout-seconds': { type: 'string', default: '180' },
      },
      allowPositionals: false,
      strict: true,
    }));
  } catch (error) {
    // Unknown options (e.g. a requested --reset/--force) are a usage error.
    // There is deliberately no destructive flag: v1 has no session reset.
    const message = error instanceof Error ? error.message : 'invalid arguments';
    process.stderr.write(`error: ${message}\nusage: npm run pair -- --phone 62XXXXXXXXX\n`);
    return 64; // EX_USAGE
  }

  const rawPhone = values.phone;
  if (!rawPhone) {
    process.stderr.write('usage: npm run pair -- --phone 62XXXXXXXXX\n');
    return 64; // EX_USAGE
  }

  // Pairing input must be digits-only with country code — no formatting.
  if (!/^62[0-9]{7,13}$/.test(rawPhone)) {
    process.stderr.write('error: --phone must be digits only with country code, e.g. 628123456789\n');
    return 64;
  }

  const phone = normalizePhone(rawPhone);
  if (!phone.ok) {
    process.stderr.write(`error: ${phone.reason}\n`);
    return 64;
  }

  let config;
  try {
    config = loadConfig();
  } catch (error) {
    if (error instanceof ConfigError) {
      process.stderr.write(`config error: ${error.message}\n`);
      return 78;
    }
    throw error;
  }

  const logger = createLogger({ level: config.logLevel, sink: () => {} });
  const db = openDatabase(config.dbPath);
  const session = new SessionStore(db);
  // Never clears existing auth: this CLI only ever *adds* a pairing.
  let auth: AuthStore;
  try {
    auth = createSqliteAuthStore(db);
  } catch (error) {
    db.close();
    if (error instanceof AuthStoreCorruptError) {
      // Fail closed: pairing over corrupt material would silently create a new
      // identity and orphan the stored session.
      process.stderr.write(`fatal: ${error.code}\n${error.message}\n`);
      return 78; // EX_CONFIG
    }
    throw error;
  }

  if (auth.isRegistered()) {
    process.stderr.write(
      'error: a WhatsApp session is already registered for this gateway.\n' +
        '       v1 has no re-pair/reset command. Removing the session is a\n' +
        '       destructive maintenance action and is deliberately not exposed here.\n',
    );
    db.close();
    return 1;
  }

  const timeoutSeconds = Math.max(30, Number.parseInt(values['timeout-seconds'] ?? '180', 10) || 180);

  const connection = new WhatsAppConnection({
    auth: auth.state,
    saveCreds: auth.saveCreds,
    session,
    logger,
    reconnectBaseMs: config.reconnectBaseMs,
    reconnectMaxMs: config.reconnectMaxMs,
    events: {
      connected: () => {
        process.stdout.write('\npaired: session is connected and credentials are stored.\n');
        cleanup(0);
      },
      disconnected: () => {},
      loggedOut: () => {
        process.stderr.write('\npairing failed: WhatsApp rejected the session (logged out).\n');
        cleanup(1);
      },
      receipt: () => {},
    },
  });

  let done = false;
  const cleanup = (code: number): void => {
    if (done) return;
    done = true;
    void connection
      .stop()
      .catch(() => {})
      .then(() => {
        try {
          db.close();
        } catch {
          // ignore
        }
        process.exit(code);
      });
  };

  const timer = setTimeout(() => {
    process.stderr.write(`\npairing timed out after ${timeoutSeconds}s; no session was registered.\n`);
    cleanup(1);
  }, timeoutSeconds * 1000);
  timer.unref?.();

  process.stdout.write('requesting pairing code...\n');
  await connection.connect();

  // The socket needs a moment to open before a code can be requested.
  const code = await withRetry(() => connection.requestPairingCode(phone.e164), 8, 1_000).catch(
    (error: unknown) => {
      const message = error instanceof Error ? error.message : 'unknown error';
      process.stderr.write(`error: could not obtain a pairing code (${message})\n`);
      return null;
    },
  );

  if (code === null) {
    cleanup(1);
    return 1;
  }

  // The only place the code is ever printed. Not logged, not persisted.
  process.stdout.write(
    `\n  pairing code: ${code}\n\n` +
      '  Enter it in WhatsApp > Linked devices > Link with phone number.\n' +
      '  The code is shown once and is not stored anywhere.\n\n',
  );

  // Keep the process alive until connected/timeout; cleanup() exits.
  await new Promise<void>(() => {});
  return 0;
}

async function withRetry<T>(fn: () => Promise<T>, attempts: number, delayMs: number): Promise<T> {
  let lastError: unknown;
  for (let i = 0; i < attempts; i += 1) {
    try {
      return await fn();
    } catch (error) {
      lastError = error;
      await new Promise((resolve) => setTimeout(resolve, delayMs));
    }
  }
  throw lastError;
}

const code = await main();
process.exitCode = code;
