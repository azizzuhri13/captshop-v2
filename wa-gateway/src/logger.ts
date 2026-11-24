/**
 * Sanitized structured logger.
 *
 * Rule from the spec: message text, internal keys, auth credentials, Signal
 * keys, QR strings and pairing codes must never reach a log sink. Two layers
 * enforce that:
 *
 *  1. Redaction by key name (deny-list), applied recursively to every payload.
 *  2. Redaction by value (a scrubber) for anything that still looks like a
 *     secret: long base64 blobs, PEM blocks, bearer tokens.
 *
 * Callers additionally pass only safe scalars (ids, statuses, error codes) —
 * the redaction here is defence in depth, not a licence to log payloads.
 */

export const LOG_LEVELS = ['silent', 'error', 'warn', 'info', 'debug'] as const;
export type LogLevel = (typeof LOG_LEVELS)[number];

const LEVEL_RANK: Record<LogLevel, number> = {
  silent: 0,
  error: 1,
  warn: 2,
  info: 3,
  debug: 4,
};

const REDACTED = '[redacted]';

/**
 * Key-name deny list. Matching is substring-based on the lowercased key so
 * `creds.signedIdentityKey`, `noiseKey` and `pairingCode` all collapse.
 */
const DENY_KEY_FRAGMENTS = [
  'key',
  'secret',
  'token',
  'password',
  'passwd',
  'pin',
  'otp',
  'credential',
  'creds',
  'auth',
  'qr',
  'pairing',
  'signature',
  'private',
  'session',
  'text',
  'body',
  'message_body',
  'noise',
  'identity',
  'prekey',
  'pre_key',
  'advsecret',
  'apikey',
  'authorization',
];

/** Keys whose *values* are structural and safe even though the name looks risky. */
const ALLOW_KEYS = new Set([
  'idempotency_key_hash',
  'key_id',
  'keystore',
  'auth_required',
  'auth_ok',
  'session_state',
  'session_status',
  'message_id',
  'messages',
]);

/** Anything matching this looks like opaque key material and gets scrubbed. */
const OPAQUE_BLOB = /^[A-Za-z0-9+/=_-]{80,}$/;
const PEM_BLOCK = /-----BEGIN [A-Z ]+-----/;
const BEARER = /\b(Bearer|Basic)\s+[A-Za-z0-9._~+/=-]{8,}/gi;

export function isSecretKey(key: string): boolean {
  const lower = key.toLowerCase();
  if (ALLOW_KEYS.has(lower)) return false;
  return DENY_KEY_FRAGMENTS.some((frag) => lower.includes(frag));
}

/** Scrubs free-form strings for material that should never be persisted. */
export function scrubString(value: string): string {
  if (PEM_BLOCK.test(value)) return REDACTED;
  if (OPAQUE_BLOB.test(value)) return REDACTED;
  return value.replace(BEARER, (_m, scheme: string) => `${scheme} ${REDACTED}`);
}

export function redact(value: unknown, depth = 0): unknown {
  if (depth > 6) return REDACTED;
  if (value === null || value === undefined) return value;
  if (typeof value === 'string') return scrubString(value);
  if (typeof value === 'number' || typeof value === 'boolean') return value;
  if (typeof value === 'bigint') return value.toString();
  if (value instanceof Date) return value.toISOString();
  if (value instanceof Error) {
    return {
      name: value.name,
      // Error messages can embed tokens (e.g. failed URL with key); scrub them.
      message: scrubString(value.message),
    };
  }
  if (Buffer.isBuffer(value) || value instanceof Uint8Array) return REDACTED;
  if (Array.isArray(value)) {
    return value.slice(0, 50).map((entry) => redact(entry, depth + 1));
  }
  if (typeof value === 'object') {
    const out: Record<string, unknown> = {};
    for (const [key, entry] of Object.entries(value as Record<string, unknown>)) {
      out[key] = isSecretKey(key) ? REDACTED : redact(entry, depth + 1);
    }
    return out;
  }
  return REDACTED;
}

export type LogRecord = {
  time: string;
  level: Exclude<LogLevel, 'silent'>;
  msg: string;
  [field: string]: unknown;
};

export type LogSink = (line: string) => void;

const defaultSink: LogSink = (line) => {
  process.stdout.write(`${line}\n`);
};

export type Logger = {
  level: LogLevel;
  error(msg: string, fields?: Record<string, unknown>): void;
  warn(msg: string, fields?: Record<string, unknown>): void;
  info(msg: string, fields?: Record<string, unknown>): void;
  debug(msg: string, fields?: Record<string, unknown>): void;
  child(bindings: Record<string, unknown>): Logger;
  /** Test hook: every record that passed the level filter, already redacted. */
  records(): LogRecord[];
};

export type LoggerOptions = {
  level?: string;
  sink?: LogSink;
  now?: () => Date;
  /** Keep an in-memory copy of emitted records (tests assert on it). */
  capture?: boolean;
};

function normalizeLevel(level: string): LogLevel {
  const lower = level.toLowerCase();
  return (LOG_LEVELS as readonly string[]).includes(lower) ? (lower as LogLevel) : 'info';
}

export function createLogger(options: LoggerOptions = {}): Logger {
  const level = normalizeLevel(options.level ?? 'info');
  const sink = options.sink ?? defaultSink;
  const now = options.now ?? (() => new Date());
  const capture = options.capture ?? false;
  const buffer: LogRecord[] = [];

  const make = (bound: Record<string, unknown>): Logger => {
    const emit = (recordLevel: Exclude<LogLevel, 'silent'>, msg: string, fields?: Record<string, unknown>) => {
      if (LEVEL_RANK[recordLevel] > LEVEL_RANK[level]) return;
      const record = redact({
        ...bound,
        ...(fields ?? {}),
        time: now().toISOString(),
        level: recordLevel,
        msg,
      }) as LogRecord;
      if (capture) buffer.push(record);
      sink(JSON.stringify(record));
    };

    return {
      level,
      error: (msg, fields) => emit('error', msg, fields),
      warn: (msg, fields) => emit('warn', msg, fields),
      info: (msg, fields) => emit('info', msg, fields),
      debug: (msg, fields) => emit('debug', msg, fields),
      child: (bindings) => make({ ...bound, ...bindings }),
      records: () => buffer.slice(),
    };
  };

  return make({});
}

/** A logger that discards everything — used where a sink is required but unwanted. */
export const nullLogger: Logger = createLogger({ level: 'silent', sink: () => {} });
