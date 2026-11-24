import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { openDatabase } from '../src/db.ts';
import { createSqliteAuthStore } from '../src/auth-store.ts';

const ROOT = resolve(import.meta.dirname, '..');

/**
 * CLI contract tests.
 *
 * These run the real entrypoints as child processes so they exercise argument
 * parsing, exit codes and stdout/stderr exactly as an operator would see them.
 * Pairing itself is never performed (no network, no real number): every case
 * here must be refused before any WhatsApp contact.
 */
function runCli(
  script: 'pair' | 'status',
  args: string[],
  env: Record<string, string>,
): { status: number | null; stdout: string; stderr: string } {
  const result = spawnSync(process.execPath, [join('dist', 'src', 'cli', `${script}.js`), ...args], {
    cwd: ROOT,
    encoding: 'utf8',
    timeout: 30_000,
    env: { ...process.env, ...env },
  });
  return { status: result.status, stdout: result.stdout ?? '', stderr: result.stderr ?? '' };
}

function withEnv(): { dir: string; env: Record<string, string> } {
  const dir = mkdtempSync(join(tmpdir(), 'wa-cli-test-'));
  return {
    dir,
    env: {
      WHATSAPP_GATEWAY_KEY: 'cli-test-key-0123456789abcdef',
      WHATSAPP_GATEWAY_DB: join(dir, 'gateway.db'),
      WHATSAPP_GATEWAY_LOG_LEVEL: 'silent',
    },
  };
}

describe('pair CLI', () => {
  test('requires --phone', () => {
    const { dir, env } = withEnv();
    try {
      const result = runCli('pair', [], env);
      assert.equal(result.status, 64);
      assert.ok(result.stderr.includes('usage'));
      assert.equal(result.stdout, '', 'nothing is printed on stdout for a usage error');
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  test('rejects a phone without a country code or with formatting', () => {
    const { dir, env } = withEnv();
    try {
      for (const phone of ['08123456789', '62812-3456-789', '+628123456789', 'abcdefghij']) {
        const result = runCli('pair', ['--phone', phone], env);
        assert.equal(result.status, 64, `${phone} must be a usage error`);
        assert.equal(result.stdout, '', 'no pairing code is requested');
      }
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  test('refuses to pair when a session is already registered, and does not delete it', () => {
    const { dir, env } = withEnv();
    try {
      // Seed a registered session directly.
      const db = openDatabase(env.WHATSAPP_GATEWAY_DB as string);
      const store = createSqliteAuthStore(db);
      store.state.creds.registered = true;
      void store.saveCreds();
      db.prepare(`INSERT INTO auth_keys (type, id, data, updated_at) VALUES ('session','abc','{}',1)`).run();
      db.close();

      const result = runCli('pair', ['--phone', '628123456789'], env);
      assert.equal(result.status, 1, 'refuses with a failure exit code');
      assert.ok(result.stderr.includes('already registered'));
      assert.equal(result.stdout, '', 'no pairing code is printed');

      // The existing session must be untouched.
      const after = openDatabase(env.WHATSAPP_GATEWAY_DB as string);
      const keys = after.prepare('SELECT COUNT(*) AS n FROM auth_keys').get() as { n: number };
      const creds = createSqliteAuthStore(after).isRegistered();
      after.close();
      assert.equal(keys.n, 1, 'auth keys are preserved');
      assert.equal(creds, true, 'registration state is preserved');
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  test('has no reset/force flag: an unknown option is a usage error', () => {
    const { dir, env } = withEnv();
    try {
      for (const flag of ['--force-reset', '--reset', '--logout']) {
        const result = runCli('pair', ['--phone', '628123456789', flag], env);
        assert.notEqual(result.status, 0, `${flag} must not be accepted`);
        assert.equal(result.stdout, '');
      }
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  test('refuses to start without a configured internal key', () => {
    const dir = mkdtempSync(join(tmpdir(), 'wa-cli-test-'));
    try {
      const result = runCli('pair', ['--phone', '628123456789'], {
        WHATSAPP_GATEWAY_DB: join(dir, 'gateway.db'),
        WHATSAPP_GATEWAY_KEY: '',
        WHATSAPP_GATEWAY_LOG_LEVEL: 'silent',
      });
      assert.equal(result.status, 78, 'EX_CONFIG');
      assert.ok(result.stderr.includes('WHATSAPP_GATEWAY_KEY'));
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  test('fails closed on corrupt auth material and never pairs over it', () => {
    const { dir, env } = withEnv();
    const corrupt = '{"creds":"definitely-not-valid';
    try {
      const db = openDatabase(env.WHATSAPP_GATEWAY_DB as string);
      db.prepare(`INSERT INTO auth_creds (id, data, updated_at) VALUES (1, ?, ?)`).run(corrupt, 1);
      db.close();

      const result = runCli('pair', ['--phone', '628123456789'], env);
      assert.equal(result.status, 78, 'EX_CONFIG');
      assert.ok(result.stderr.includes('AUTH_STORE_CORRUPT'));
      assert.ok(!result.stderr.includes(corrupt), 'the corrupt payload is never printed');
      assert.equal(result.stdout, '', 'no pairing code is requested');

      // The corrupt row survives untouched.
      const after = openDatabase(env.WHATSAPP_GATEWAY_DB as string);
      const row = after.prepare(`SELECT data FROM auth_creds WHERE id = 1`).get() as { data: string };
      after.close();
      assert.equal(row.data, corrupt);
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
});

describe('status CLI', () => {
  test('reports an unpaired gateway and prints no secrets', () => {
    const { dir, env } = withEnv();
    try {
      // Create the database first.
      openDatabase(env.WHATSAPP_GATEWAY_DB as string).close();

      const result = runCli('status', [], env);
      assert.equal(result.status, 0);
      assert.ok(result.stdout.includes('session state    : unpaired'));
      assert.ok(result.stdout.includes('registered       : no'));
      assert.ok(result.stdout.includes('ACTION REQUIRED'));
      assert.equal(result.stderr, '');

      // The internal key must never appear in operator output.
      assert.ok(!result.stdout.includes(env.WHATSAPP_GATEWAY_KEY as string));
      assert.ok(!result.stderr.includes(env.WHATSAPP_GATEWAY_KEY as string));
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  test('reports a registered, logged-out session with queue counts and a masked sender', () => {
    const { dir, env } = withEnv();
    try {
      const db = openDatabase(env.WHATSAPP_GATEWAY_DB as string);
      const store = createSqliteAuthStore(db);
      store.state.creds.registered = true;
      void store.saveCreds();
      db.prepare(
        `UPDATE session_state SET state='logged_out', sender_jid=?, sender_display=?, last_disconnect_code=401 WHERE id=1`,
      ).run('628123456789@s.whatsapp.net', '628123456789');
      db.prepare(
        `INSERT INTO messages (id,idempotency_key,payload_hash,to_e164,body_text,status,attempts,max_attempts,created_at,updated_at)
         VALUES ('m1','transaction:INV-1:event:created','h','628123456789','secret text','queued',0,3,1,1)`,
      ).run();
      db.close();

      const result = runCli('status', [], env);
      assert.equal(result.status, 0);
      assert.ok(result.stdout.includes('logged_out'));
      assert.ok(result.stdout.includes('registered       : yes'));
      assert.ok(result.stdout.includes('queued         : 1'));
      assert.ok(result.stdout.includes('ACTION REQUIRED'));
      // Sender is masked, and the message body never appears.
      assert.ok(!result.stdout.includes('628123456789'), 'full sender number is not printed');
      assert.ok(result.stdout.includes('62812****789'), 'masked sender is printed');
      assert.ok(!result.stdout.includes('secret text'), 'message body is never printed');
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
});

describe('package wiring', () => {
  test('no runtime script depends on a TypeScript loader', () => {
    const pkg = JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8')) as {
      scripts: Record<string, string>;
      dependencies: Record<string, string>;
      devDependencies: Record<string, string>;
    };
    for (const [name, command] of Object.entries(pkg.scripts)) {
      assert.ok(!command.includes('tsx'), `script '${name}' must not require tsx`);
      assert.ok(!command.includes('ts-node'), `script '${name}' must not require ts-node`);
    }
    // Runtime entrypoints run compiled JS.
    assert.ok(pkg.scripts.start?.includes('dist/'), 'start must run the compiled build');
    assert.ok(pkg.scripts.pair?.includes('dist/'), 'pair must run the compiled build');
    assert.ok(pkg.scripts.status?.includes('dist/'), 'status must run the compiled build');
    assert.ok(pkg.scripts.build, 'a build script must exist');
    assert.ok(pkg.scripts.typecheck, 'a typecheck script must exist');
    assert.equal(pkg.dependencies.tsx, undefined, 'tsx is not a runtime dependency');
  });

  test('compiled CLI entrypoints exist after a build', () => {
    for (const file of ['index.js', 'cli/pair.js', 'cli/status.js']) {
      const path = join(ROOT, 'dist', 'src', file);
      assert.doesNotThrow(() => readFileSync(path), `${path} should exist (run npm run build)`);
    }
  });
});
