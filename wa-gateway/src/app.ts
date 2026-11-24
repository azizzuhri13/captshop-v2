import type { Server } from 'node:http';
import type { GatewayConfig } from './config.ts';
import { createLogger } from './logger.ts';
import type { Logger } from './logger.ts';
import { openDatabase } from './db.ts';
import type { Db } from './db.ts';
import { createSqliteAuthStore } from './auth-store.ts';
import type { AuthStore } from './auth-store.ts';
import { Outbox } from './outbox.ts';
import { SessionStore } from './session-store.ts';
import { WhatsAppConnection } from './connection.ts';
import type { SocketFactory } from './connection.ts';
import { OutboxWorker } from './worker.ts';
import { createGatewayServer } from './server.ts';

export type Gateway = {
  db: Db;
  auth: AuthStore;
  outbox: Outbox;
  session: SessionStore;
  connection: WhatsAppConnection;
  worker: OutboxWorker;
  server: Server;
  logger: Logger;
  start: () => Promise<{ host: string; port: number }>;
  shutdown: () => Promise<void>;
};

export type GatewayOverrides = {
  logger?: Logger;
  socketFactory?: SocketFactory;
  /** Skip the outbox worker interval (tests drive `worker.tick()` directly). */
  disableWorker?: boolean;
  /** Connect to WhatsApp on start. Off in tests. */
  autoConnect?: boolean;
  db?: Db;
};

/**
 * Wires the gateway. Everything is constructed here so index.ts, the CLI and
 * tests share exactly one composition path — no test-only bootstrapping that
 * could hide a wiring bug in production.
 */
export function createGateway(config: GatewayConfig, overrides: GatewayOverrides = {}): Gateway {
  const logger =
    overrides.logger ?? createLogger({ level: config.logLevel, capture: false });
  const db = overrides.db ?? openDatabase(config.dbPath);
  const auth = createSqliteAuthStore(db);
  const outbox = new Outbox(db);
  const session = new SessionStore(db);

  const connection = new WhatsAppConnection({
    auth: auth.state,
    saveCreds: auth.saveCreds,
    session,
    logger: logger.child({ component: 'connection' }),
    reconnectBaseMs: config.reconnectBaseMs,
    reconnectMaxMs: config.reconnectMaxMs,
    ...(overrides.socketFactory ? { socketFactory: overrides.socketFactory } : {}),
    events: {
      connected: () => {
        // A reconnect is the moment queued work becomes sendable; nudge the
        // worker instead of waiting for the next interval.
        if (!overrides.disableWorker) void worker.tick();
      },
      disconnected: () => {},
      loggedOut: () => {
        // Spec: stop retrying on logout and surface a re-pair requirement.
        const terminal = outbox.failAllPending('SESSION_LOGGED_OUT');
        const inFlight = outbox.failInFlight('SESSION_LOGGED_OUT');
        logger.warn('gateway.logged_out_retries_stopped', { terminal, in_flight: inFlight });
      },
      receipt: (baileysMessageId, status) => {
        outbox.applyReceipt(baileysMessageId, status);
      },
    },
  });

  const worker = new OutboxWorker({
    outbox,
    logger: logger.child({ component: 'worker' }),
    getSender: () => (connection.isConnected() ? (jid, text) => connection.sendText(jid, text) : null),
    backoff: { baseMs: config.retryBaseMs, maxMs: config.retryMaxMs, jitter: config.retryJitter },
    batchSize: 10,
    intervalMs: config.workerIntervalMs,
    staleClaimMs: config.staleClaimMs,
    sendTimeoutMs: 60_000,
  });

  const server = createGatewayServer({
    outbox,
    session,
    logger: logger.child({ component: 'http' }),
    internalKey: config.internalKey,
    isConnected: () => connection.isConnected(),
    isRegistered: () => auth.isRegistered(),
    maxAttempts: config.maxAttempts,
  });

  let shuttingDown = false;

  const start = async (): Promise<{ host: string; port: number }> => {
    await new Promise<void>((resolve, reject) => {
      const onError = (error: Error) => {
        server.off('error', onError);
        reject(error);
      };
      server.once('error', onError);
      server.listen(config.port, config.host, () => {
        server.off('error', onError);
        resolve();
      });
    });

    const address = server.address();
    const port = typeof address === 'object' && address !== null ? address.port : config.port;
    logger.info('gateway.listening', { host: config.host, port });

    if (!overrides.disableWorker) worker.start();
    if (overrides.autoConnect !== false) {
      await connection.connect();
    }
    return { host: config.host, port };
  };

  const shutdown = async (): Promise<void> => {
    if (shuttingDown) return;
    shuttingDown = true;
    logger.info('gateway.shutdown_begin');

    await worker.stop();
    // Return claimed work to the retryable pool *before* closing the socket so
    // nothing is left stranded in `sending`.
    worker.releaseClaims();

    await connection.stop();

    await new Promise<void>((resolve) => {
      server.close(() => resolve());
      // Force-close keep-alive sockets so shutdown is not held open by a client.
      server.closeIdleConnections?.();
      setTimeout(() => {
        server.closeAllConnections?.();
        resolve();
      }, 3_000).unref?.();
    });

    try {
      db.close();
    } catch {
      // Already closed.
    }
    logger.info('gateway.shutdown_complete');
  };

  return { db, auth, outbox, session, connection, worker, server, logger, start, shutdown };
}
