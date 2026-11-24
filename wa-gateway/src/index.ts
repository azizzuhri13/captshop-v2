import { loadConfig, ConfigError } from './config.ts';
import { createLogger } from './logger.ts';
import { createGateway } from './app.ts';
import { AuthStoreCorruptError } from './auth-store.ts';

/**
 * Gateway entrypoint.
 *
 * Binds loopback only (enforced by loadConfig) and refuses to start without an
 * internal key. Pairing is a separate CLI (`npm run pair`) so this process can
 * run unattended without ever holding a pairing code.
 */
async function main(): Promise<void> {
  let config;
  try {
    config = loadConfig();
  } catch (error) {
    if (error instanceof ConfigError) {
      process.stderr.write(`config error: ${error.message}\n`);
      process.exit(78); // EX_CONFIG
    }
    throw error;
  }

  const logger = createLogger({ level: config.logLevel });
  let gateway;
  try {
    gateway = createGateway(config);
  } catch (error) {
    if (error instanceof AuthStoreCorruptError) {
      // Fail closed with the sanitized code only: no payload, no key material,
      // no stack trace. The stored auth row is untouched for manual recovery.
      logger.error('gateway.auth_store_corrupt', { code: error.code });
      process.stderr.write(`fatal: ${error.code}\n${error.message}\n`);
      process.exit(78); // EX_CONFIG
    }
    throw error;
  }

  let bound: { host: string; port: number };
  try {
    bound = await gateway.start();
  } catch (error) {
    // A bind failure (port already in use, permission denied) is an operator
    // problem, not a crash: report the errno code and exit cleanly instead of
    // dumping a stack trace. Never print the bound address' contents beyond
    // what config already shows.
    const code =
      error && typeof error === 'object' && typeof (error as { code?: unknown }).code === 'string'
        ? (error as { code: string }).code
        : 'LISTEN_FAILED';
    logger.error('gateway.listen_failed', { error_code: code, host: config.host, port: config.port });
    process.stderr.write(
      `fatal: could not listen on ${config.host}:${config.port} (${code}). ` +
        'Another process may already be using that port.\n',
    );
    await gateway.shutdown().catch(() => {});
    process.exit(74); // EX_IOERR
  }

  const { host, port } = bound;
  logger.info('gateway.started', { host, port, pid: process.pid });

  const session = gateway.session.get();
  if (session.state !== 'connected') {
    logger.warn('gateway.session_not_connected', {
      session_state: session.state,
      hint: 'run `npm run pair -- --phone 62...` to establish the WhatsApp session',
    });
  }

  let stopping = false;
  const stop = (signal: string) => {
    if (stopping) return;
    stopping = true;
    logger.info('gateway.signal_received', { signal });
    void gateway
      .shutdown()
      .then(() => process.exit(0))
      .catch((error: unknown) => {
        logger.error('gateway.shutdown_failed', { error });
        process.exit(1);
      });
  };

  process.on('SIGINT', () => stop('SIGINT'));
  process.on('SIGTERM', () => stop('SIGTERM'));

  process.on('unhandledRejection', (reason) => {
    logger.error('gateway.unhandled_rejection', { error: reason });
  });
  process.on('uncaughtException', (error) => {
    logger.error('gateway.uncaught_exception', { error });
    stop('uncaughtException');
  });
}

await main();
