import { parseArgs } from 'node:util';
import { loadConfig, ConfigError } from '../config.ts';
import { createLogger } from '../logger.ts';
import { openDatabase } from '../db.ts';
import { createSqliteAuthStore, AuthStoreCorruptError } from '../auth-store.ts';
import type { AuthStore } from '../auth-store.ts';
import { SessionStore } from '../session-store.ts';
import { normalizePhone } from '../phone.ts';
import { WhatsAppConnection, DEFAULT_BROWSER } from '../connection.ts';
import { createPairingSession, isRetryablePairingError } from '../pairing.ts';

/**
 * `npm run pair -- --phone 62...`
 *
 * CLI-only pairing (spec: no dashboard pairing endpoint in v1).
 *
 * Guarantees:
 *  - the pairing code is written to the operator's terminal exactly once and is
 *    never logged, persisted, or echoed by any other code path;
 *  - a code is printed only after WhatsApp acknowledged the companion_hello IQ,
 *    so a rejected request never shows an unusable code;
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

  // Indirection so the connection (created first) can report into the pairing
  // flow (created second) without a circular construction order.
  const pairingEvents: { connected: () => void; loggedOut: () => void } = {
    connected: () => {},
    loggedOut: () => {},
  };

  const connection = new WhatsAppConnection({
    auth: auth.state,
    saveCreds: auth.saveCreds,
    session,
    logger,
    browser: DEFAULT_BROWSER,
    reconnectBaseMs: config.reconnectBaseMs,
    reconnectMaxMs: config.reconnectMaxMs,
    events: {
      connected: () => pairingEvents.connected(),
      disconnected: () => {},
      loggedOut: () => pairingEvents.loggedOut(),
      receipt: () => {},
    },
  });

  const pairing = createPairingSession({
    timeoutSeconds,
    stdout: (text) => process.stdout.write(text),
    stderr: (text) => process.stderr.write(text),
    connect: () => connection.connect(),
    requestPairingCode: () => requestPairingCodeWithRetry(connection, phone.e164),
    stop: async () => {
      await connection.stop();
      try {
        db.close();
      } catch {
        // already closed
      }
    },
  });

  pairingEvents.connected = pairing.events.connected;
  pairingEvents.loggedOut = pairing.events.loggedOut;

  return pairing.run();
}

const code = await main();
process.exitCode = code;

/**
 * Retries only when the pairing handshake was interrupted before anything
 * reached WhatsApp (a socket swap/reconnect). A server rejection is final:
 * retrying it would burn WhatsApp's pairing attempts and re-print a code that
 * cannot link.
 */
async function requestPairingCodeWithRetry(
  connection: WhatsAppConnection,
  phoneE164: string,
  attempts = 8,
  delayMs = 1_000,
): Promise<string> {
  let lastError: unknown;
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    try {
      return await connection.requestPairingCode(phoneE164);
    } catch (error) {
      lastError = error;
      if (!isRetryablePairingError(error)) throw error;
      await new Promise((resolve) => setTimeout(resolve, delayMs));
    }
  }
  throw lastError;
}
