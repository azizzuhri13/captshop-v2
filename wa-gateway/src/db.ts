import Database from 'better-sqlite3';
import { chmodSync } from 'node:fs';

export type Db = Database.Database;

/**
 * Schema v1.
 *
 * Three concerns live in one SQLite file so a single `BEGIN IMMEDIATE` can
 * cover an outbox claim and its side effects, and so the auth store is
 * physically outside any webroot by construction (the operator points
 * WHATSAPP_GATEWAY_DB at a private path).
 *
 *  - messages:       durable outbox
 *  - auth_creds:     single-row Baileys AuthenticationCreds (JSON, BufferJSON)
 *  - auth_keys:      Baileys SignalKeyStore (type/id -> JSON)
 *  - session_state:  singleton gateway/session status, survives restarts
 */
const SCHEMA = `
CREATE TABLE IF NOT EXISTS schema_meta (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS messages (
  id                  TEXT PRIMARY KEY,
  idempotency_key     TEXT NOT NULL UNIQUE,
  payload_hash        TEXT NOT NULL,
  to_e164             TEXT NOT NULL,
  body_text           TEXT NOT NULL,
  invoice_id          TEXT,
  event               TEXT,
  source              TEXT,
  status              TEXT NOT NULL,
  attempts            INTEGER NOT NULL DEFAULT 0,
  max_attempts        INTEGER NOT NULL,
  next_attempt_at     INTEGER,
  claimed_at          INTEGER,
  claim_token         TEXT,
  baileys_message_id  TEXT,
  last_error_code     TEXT,
  last_error_at       INTEGER,
  created_at          INTEGER NOT NULL,
  updated_at          INTEGER NOT NULL,
  sent_at             INTEGER,
  delivered_at        INTEGER,
  read_at             INTEGER,
  CONSTRAINT messages_status_chk CHECK (status IN (
    'queued','sending','sent','delivered','read','failed_retryable','failed_terminal'
  ))
);

CREATE INDEX IF NOT EXISTS idx_messages_due
  ON messages (status, next_attempt_at);
CREATE INDEX IF NOT EXISTS idx_messages_status
  ON messages (status);
CREATE INDEX IF NOT EXISTS idx_messages_invoice
  ON messages (invoice_id);

CREATE TABLE IF NOT EXISTS auth_creds (
  id         INTEGER PRIMARY KEY CHECK (id = 1),
  data       TEXT NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS auth_keys (
  type       TEXT NOT NULL,
  id         TEXT NOT NULL,
  data       TEXT NOT NULL,
  updated_at INTEGER NOT NULL,
  PRIMARY KEY (type, id)
);

CREATE TABLE IF NOT EXISTS session_state (
  id                     INTEGER PRIMARY KEY CHECK (id = 1),
  state                  TEXT NOT NULL,
  sender_jid             TEXT,
  sender_display         TEXT,
  last_connected_at      INTEGER,
  last_disconnected_at   INTEGER,
  last_disconnect_code   INTEGER,
  updated_at             INTEGER NOT NULL,
  CONSTRAINT session_state_chk CHECK (state IN (
    'unpaired','connecting','connected','logged_out','degraded'
  ))
);
`;

export const SCHEMA_VERSION = 1;

/**
 * Opens (and migrates) the gateway database. `fileMustBePrivate` chmods the
 * file to 0600 after creation so auth keys are not world-readable.
 */
export function openDatabase(path: string, options: { fileMustBePrivate?: boolean } = {}): Db {
  const db = new Database(path);
  db.pragma('journal_mode = WAL');
  db.pragma('synchronous = FULL'); // auth state must survive a power cut
  db.pragma('foreign_keys = ON');
  db.pragma('busy_timeout = 5000');

  db.exec(SCHEMA);

  const current = db
    .prepare(`SELECT value FROM schema_meta WHERE key = 'version'`)
    .get() as { value: string } | undefined;
  if (current === undefined) {
    db.prepare(`INSERT INTO schema_meta (key, value) VALUES ('version', ?)`).run(String(SCHEMA_VERSION));
  } else if (Number.parseInt(current.value, 10) > SCHEMA_VERSION) {
    throw new Error(
      `database schema ${current.value} is newer than this build (${SCHEMA_VERSION}); refusing to run`,
    );
  }

  db.prepare(
    `INSERT INTO session_state (id, state, updated_at) VALUES (1, 'unpaired', ?)
     ON CONFLICT(id) DO NOTHING`,
  ).run(Date.now());

  if (options.fileMustBePrivate !== false && path !== ':memory:') {
    try {
      chmodSync(path, 0o600);
    } catch {
      // Non-fatal: some filesystems (or a read-only mount) reject chmod.
    }
  }

  return db;
}

/** Runs `fn` inside a single write transaction (IMMEDIATE = no upgrade deadlocks). */
export function withImmediateTransaction<T>(db: Db, fn: () => T): T {
  const run = db.transaction(fn);
  return run.immediate();
}
