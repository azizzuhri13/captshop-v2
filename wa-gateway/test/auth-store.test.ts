import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomBytes } from 'node:crypto';
import { openDatabase } from '../src/db.ts';
import { createSqliteAuthStore, AuthStoreCorruptError } from '../src/auth-store.ts';
import { createGateway } from '../src/app.ts';
import { createLogger } from '../src/logger.ts';
import { testConfig } from './helpers.ts';

const tempDb = (): { path: string; dir: string } => {
  const dir = mkdtempSync(join(tmpdir(), 'wa-auth-test-'));
  return { path: join(dir, 'auth.db'), dir };
};

describe('SQLite auth store', () => {
  test('creds roundtrip preserves binary key material and scalar fields', async () => {
    const { path, dir } = tempDb();
    try {
      const db = openDatabase(path);
      const store = createSqliteAuthStore(db);

      const creds = store.state.creds;
      // Baileys' types mark these readonly, but the library mutates creds in
      // place at runtime; mirror that here.
      const mutable = creds as unknown as Record<string, unknown>;
      const noisePrivate = randomBytes(32);
      const identityPublic = randomBytes(32);
      const signature = randomBytes(64);

      mutable.noiseKey = {
        private: new Uint8Array(noisePrivate),
        public: new Uint8Array(noisePrivate),
      };
      mutable.signedIdentityKey = {
        private: new Uint8Array(identityPublic),
        public: new Uint8Array(identityPublic),
      };
      mutable.signedPreKey = {
        keyPair: { private: new Uint8Array(identityPublic), public: new Uint8Array(identityPublic) },
        signature: new Uint8Array(signature),
        keyId: 7,
      };
      mutable.registrationId = 4242;
      mutable.advSecretKey = 'adv-secret-value';
      mutable.registered = true;
      mutable.me = { id: '628111222333@s.whatsapp.net', name: 'Sender' };

      await store.saveCreds();
      db.close();

      // Fresh connection: simulates a process restart.
      const db2 = openDatabase(path);
      const store2 = createSqliteAuthStore(db2);
      const reloaded = store2.state.creds;

      assert.equal(reloaded.registrationId, 4242);
      assert.equal(reloaded.advSecretKey, 'adv-secret-value');
      assert.equal(reloaded.registered, true);
      assert.equal(reloaded.me?.id, '628111222333@s.whatsapp.net');

      // Binary material must come back as bytes, not as a base64 string.
      const noise = reloaded.noiseKey.private;
      assert.ok(noise instanceof Uint8Array, 'noiseKey.private should be a byte array');
      assert.deepEqual(Buffer.from(noise), noisePrivate);

      const sig = reloaded.signedPreKey.signature;
      assert.deepEqual(Buffer.from(sig), signature);
      assert.equal(reloaded.signedPreKey.keyId, 7);

      db2.close();
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  test('representative Signal key types roundtrip and survive a restart', async () => {
    const { path, dir } = tempDb();
    try {
      const db = openDatabase(path);
      const store = createSqliteAuthStore(db);

      const preKeyPublic = randomBytes(32);
      const sessionBytes = randomBytes(96);
      const identityKey = randomBytes(32);

      await store.state.keys.set({
        'pre-key': {
          '1': { private: new Uint8Array(preKeyPublic), public: new Uint8Array(preKeyPublic) },
        },
        session: { '628111@s.whatsapp.net': new Uint8Array(sessionBytes) },
        'identity-key': { '628111@s.whatsapp.net': new Uint8Array(identityKey) },
        'sender-key-memory': { 'group@g.us': { '628111@s.whatsapp.net': true } },
        'lid-mapping': { '628111@s.whatsapp.net': '99887766554433' },
      });
      db.close();

      const db2 = openDatabase(path);
      const store2 = createSqliteAuthStore(db2);

      const preKeys = await store2.state.keys.get('pre-key', ['1', '2']);
      assert.ok(preKeys['1'], 'pre-key 1 should exist');
      assert.deepEqual(Buffer.from(preKeys['1']!.private), preKeyPublic);
      assert.equal(preKeys['2'], undefined, 'missing keys must be absent, not undefined entries');

      const sessions = await store2.state.keys.get('session', ['628111@s.whatsapp.net']);
      assert.deepEqual(Buffer.from(sessions['628111@s.whatsapp.net']!), sessionBytes);

      const identity = await store2.state.keys.get('identity-key', ['628111@s.whatsapp.net']);
      assert.deepEqual(Buffer.from(identity['628111@s.whatsapp.net']!), identityKey);

      const memory = await store2.state.keys.get('sender-key-memory', ['group@g.us']);
      assert.deepEqual(memory['group@g.us'], { '628111@s.whatsapp.net': true });

      const lid = await store2.state.keys.get('lid-mapping', ['628111@s.whatsapp.net']);
      assert.equal(lid['628111@s.whatsapp.net'], '99887766554433');

      db2.close();
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  test('setting a key to null deletes it', async () => {
    const { path, dir } = tempDb();
    try {
      const db = openDatabase(path);
      const store = createSqliteAuthStore(db);

      await store.state.keys.set({ session: { abc: new Uint8Array(randomBytes(16)) } });
      let rows = await store.state.keys.get('session', ['abc']);
      assert.ok(rows['abc']);

      await store.state.keys.set({ session: { abc: null } });
      rows = await store.state.keys.get('session', ['abc']);
      assert.equal(rows['abc'], undefined);

      db.close();
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  test('a batch set is atomic: a throwing batch leaves prior state intact', async () => {
    const { path, dir } = tempDb();
    try {
      const db = openDatabase(path);
      const store = createSqliteAuthStore(db);

      await store.state.keys.set({ session: { keep: new Uint8Array(randomBytes(8)) } });

      // A cyclic structure makes serialization throw mid-batch, after the first
      // key would have been written by a non-transactional implementation.
      const cyclic: Record<string, unknown> = {};
      cyclic.self = cyclic;

      await assert.rejects(async () => {
        await store.state.keys.set({
          session: { first: new Uint8Array(randomBytes(8)) },
          'pre-key': { '99': cyclic as never },
        });
      });

      const sessions = await store.state.keys.get('session', ['first', 'keep']);
      assert.equal(sessions['first'], undefined, 'partial batch must not be committed');
      assert.ok(sessions['keep'], 'pre-existing key must survive');

      db.close();
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  test('isRegistered reflects the persisted creds', async () => {
    const { path, dir } = tempDb();
    try {
      const db = openDatabase(path);
      const store = createSqliteAuthStore(db);
      assert.equal(store.isRegistered(), false);

      store.state.creds.registered = true;
      await store.saveCreds();
      assert.equal(store.isRegistered(), true);

      db.close();

      const db2 = openDatabase(path);
      const store2 = createSqliteAuthStore(db2);
      assert.equal(store2.isRegistered(), true);
      db2.close();
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
});

describe('corrupt auth material fails closed', () => {
  const CORRUPT_ROWS = [
    { label: 'truncated JSON', data: '{"noiseKey":{"private":"AAAA"' },
    { label: 'not JSON at all', data: 'this is not json' },
    { label: 'JSON null', data: 'null' },
    { label: 'JSON array', data: '[]' },
    { label: 'JSON string', data: '"creds"' },
    { label: 'JSON number', data: '42' },
  ];

  for (const { label, data } of CORRUPT_ROWS) {
    test(`store refuses to open with ${label} in the creds row`, () => {
      const { path, dir } = tempDb();
      try {
        const db = openDatabase(path);
        db.prepare(`INSERT INTO auth_creds (id, data, updated_at) VALUES (1, ?, ?)`).run(data, 1);
        db.close();

        const db2 = openDatabase(path);
        assert.throws(
          () => createSqliteAuthStore(db2),
          (error: unknown) => {
            assert.ok(error instanceof AuthStoreCorruptError, 'a typed corruption error is raised');
            assert.equal(error.code, 'AUTH_STORE_CORRUPT');
            // The message is a fixed code: no stored payload, no key material.
            assert.ok(error.message.includes('AUTH_STORE_CORRUPT'));
            assert.ok(!error.message.includes(data), 'the corrupt payload is not echoed');
            return true;
          },
        );

        // The corrupt row must be left exactly as it was — a store that
        // "repaired" it would silently destroy a recoverable session.
        const row = db2.prepare(`SELECT data, updated_at FROM auth_creds WHERE id = 1`).get() as {
          data: string;
          updated_at: number;
        };
        assert.equal(row.data, data, 'stored creds are not overwritten');
        assert.equal(row.updated_at, 1, 'stored creds are not rewritten');

        // No fresh creds were materialised behind the operator's back.
        const count = db2.prepare(`SELECT COUNT(*) AS n FROM auth_creds`).get() as { n: number };
        assert.equal(count.n, 1, 'no additional creds row is created');
        db2.close();
      } finally {
        rmSync(dir, { recursive: true, force: true });
      }
    });
  }

  test('the gateway refuses to start against a corrupt auth store', async () => {
    const { path, dir } = tempDb();
    const corrupt = '{"creds":"definitely-not-valid';
    try {
      const seed = openDatabase(path);
      seed.prepare(`INSERT INTO auth_creds (id, data, updated_at) VALUES (1, ?, ?)`).run(corrupt, 1);
      seed.close();

      // createGateway is the single composition path used by index.ts, the CLIs
      // and the tests, so throwing here is what makes startup fail closed.
      assert.throws(
        () =>
          createGateway(testConfig(path), {
            logger: createLogger({ level: 'silent', sink: () => {} }),
            disableWorker: true,
            autoConnect: false,
          }),
        AuthStoreCorruptError,
      );

      const after = openDatabase(path);
      const row = after.prepare(`SELECT data FROM auth_creds WHERE id = 1`).get() as { data: string };
      assert.equal(row.data, corrupt, 'startup failure does not touch the corrupt row');
      after.close();
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  test('an empty auth store still initialises fresh creds (no false positive)', () => {
    const { path, dir } = tempDb();
    try {
      const db = openDatabase(path);
      const store = createSqliteAuthStore(db);
      assert.equal(store.isRegistered(), false);
      // Fresh creds are only persisted when Baileys asks for them.
      const count = db.prepare(`SELECT COUNT(*) AS n FROM auth_creds`).get() as { n: number };
      assert.equal(count.n, 0, 'a first boot writes nothing until saveCreds()');
      db.close();
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
});
