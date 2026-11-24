import { loadConfig, ConfigError } from '../config.ts';
import { openDatabase } from '../db.ts';
import { Outbox } from '../outbox.ts';
import { SessionStore } from '../session-store.ts';
import { createSqliteAuthStore, AuthStoreCorruptError } from '../auth-store.ts';
import { maskPhone } from '../phone.ts';

/**
 * `npm run status`
 *
 * Read-only operator view. Reads the SQLite file directly (no HTTP, no
 * internal key) so it works while the gateway is down, which is exactly when
 * an operator needs it. Prints no secrets: sender is masked, auth material is
 * reported only as "present/absent".
 */
function main(): number {
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

  const db = openDatabase(config.dbPath);
  const session = new SessionStore(db);
  const outbox = new Outbox(db);
  let auth;
  try {
    auth = createSqliteAuthStore(db);
  } catch (error) {
    if (error instanceof AuthStoreCorruptError) {
      // Report the sanitized code and stop. Never repair, never print payload.
      db.close();
      process.stderr.write(
        `fatal: ${error.code}\n` +
          '  Persisted WhatsApp auth state could not be read. The stored session\n' +
          '  was left untouched. Restore from backup or re-pair under explicit review.\n',
      );
      return 78; // EX_CONFIG
    }
    throw error;
  }

  const row = session.get();
  const counts = outbox.counts();
  const creds = auth.readCreds();

  const iso = (ms: number | null): string => (ms === null ? '-' : new Date(ms).toISOString());

  const lines = [
    'CAPTSHOP WhatsApp gateway status',
    `  database         : ${config.dbPath}`,
    `  bind             : ${config.host}:${config.port}`,
    '',
    `  session state    : ${row.state}`,
    `  registered       : ${auth.isRegistered() ? 'yes' : 'no'}`,
    `  sender           : ${row.sender_display ? maskPhone(row.sender_display) : '-'}`,
    `  last connected   : ${iso(row.last_connected_at)}`,
    `  last disconnect  : ${iso(row.last_disconnected_at)}${row.last_disconnect_code === null ? '' : ` (code ${row.last_disconnect_code})`}`,
    `  next pre-key id  : ${creds.nextPreKeyId}`,
    '',
    '  outbox',
    `    queued         : ${counts.queued}`,
    `    sending        : ${counts.sending}`,
    `    retryable      : ${counts.retryable}`,
    `    failed terminal: ${counts.terminal}`,
    `    sent           : ${counts.sent}`,
    `    delivered      : ${counts.delivered}`,
    `    read           : ${counts.read}`,
  ];

  if (row.state === 'logged_out') {
    lines.push('', '  ACTION REQUIRED: session was logged out; run `npm run pair -- --phone 62...` again.');
  } else if (!auth.isRegistered()) {
    lines.push('', '  ACTION REQUIRED: not paired; run `npm run pair -- --phone 62...`.');
  }

  process.stdout.write(`${lines.join('\n')}\n`);
  db.close();
  return 0;
}

process.exitCode = main();
