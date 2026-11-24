import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

export type GatewayConfig = {
  /** Bind address. Always loopback; the gateway is not a public service. */
  host: string;
  port: number;
  /** Shared secret Laravel sends in X-Internal-Key. Never logged, never echoed. */
  internalKey: string;
  /** SQLite file holding both the outbox and the Baileys auth state. */
  dbPath: string;
  /** Max delivery attempts before a message becomes failed_terminal. */
  maxAttempts: number;
  /** Base delay for exponential backoff, in ms. */
  retryBaseMs: number;
  /** Backoff ceiling, in ms. */
  retryMaxMs: number;
  /** Backoff jitter ratio (0 = deterministic, 1 = +/-100%). */
  retryJitter: number;
  /** How often the outbox worker polls for work, in ms. */
  workerIntervalMs: number;
  /** A `sending` row older than this is assumed abandoned and requeued. */
  staleClaimMs: number;
  /** Reconnect backoff for the Baileys socket, in ms. */
  reconnectBaseMs: number;
  reconnectMaxMs: number;
  /** Log level: silent | error | warn | info | debug. */
  logLevel: string;
};

export const LOOPBACK_HOSTS = new Set(['127.0.0.1', '::1', 'localhost']);

export class ConfigError extends Error {
  override name = 'ConfigError';
}

function intFromEnv(name: string, fallback: number, env: NodeJS.ProcessEnv): number {
  const raw = env[name];
  if (raw === undefined || raw === '') return fallback;
  const parsed = Number.parseInt(raw, 10);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    throw new ConfigError(`${name} must be a positive integer`);
  }
  return parsed;
}

function floatFromEnv(name: string, fallback: number, env: NodeJS.ProcessEnv): number {
  const raw = env[name];
  if (raw === undefined || raw === '') return fallback;
  const parsed = Number.parseFloat(raw);
  if (!Number.isFinite(parsed) || parsed < 0) {
    throw new ConfigError(`${name} must be a non-negative number`);
  }
  return parsed;
}

/**
 * Builds the runtime config. Throws ConfigError rather than silently defaulting
 * a missing internal key: an unauthenticated localhost endpoint is still an
 * unauthenticated endpoint.
 *
 * The bind address is loopback-only with no opt-out. The spec is explicit that
 * the gateway binds `127.0.0.1` and must not assume a public Nginx route, so a
 * public bind is a configuration error rather than a tunable.
 */
export function loadConfig(env: NodeJS.ProcessEnv = process.env): GatewayConfig {
  const internalKey = env.WHATSAPP_GATEWAY_KEY ?? '';
  if (internalKey.length < 16) {
    throw new ConfigError('WHATSAPP_GATEWAY_KEY is required and must be at least 16 characters');
  }

  const host = env.WHATSAPP_GATEWAY_HOST ?? '127.0.0.1';
  if (!LOOPBACK_HOSTS.has(host)) {
    throw new ConfigError(
      `WHATSAPP_GATEWAY_HOST must be a loopback address (${[...LOOPBACK_HOSTS].join(', ')}); ` +
        'the gateway is never exposed publicly',
    );
  }

  const dbPath = resolve(env.WHATSAPP_GATEWAY_DB ?? './data/gateway.db');
  mkdirSync(dirname(dbPath), { recursive: true, mode: 0o700 });

  return {
    host,
    port: intFromEnv('WHATSAPP_GATEWAY_PORT', 3213, env),
    internalKey,
    dbPath,
    maxAttempts: intFromEnv('WHATSAPP_GATEWAY_MAX_ATTEMPTS', 8, env),
    retryBaseMs: intFromEnv('WHATSAPP_GATEWAY_RETRY_BASE_MS', 2_000, env),
    retryMaxMs: intFromEnv('WHATSAPP_GATEWAY_RETRY_MAX_MS', 300_000, env),
    retryJitter: floatFromEnv('WHATSAPP_GATEWAY_RETRY_JITTER', 0.2, env),
    workerIntervalMs: intFromEnv('WHATSAPP_GATEWAY_WORKER_INTERVAL_MS', 500, env),
    staleClaimMs: intFromEnv('WHATSAPP_GATEWAY_STALE_CLAIM_MS', 120_000, env),
    reconnectBaseMs: intFromEnv('WHATSAPP_GATEWAY_RECONNECT_BASE_MS', 2_000, env),
    reconnectMaxMs: intFromEnv('WHATSAPP_GATEWAY_RECONNECT_MAX_MS', 60_000, env),
    logLevel: env.WHATSAPP_GATEWAY_LOG_LEVEL ?? 'info',
  };
}
