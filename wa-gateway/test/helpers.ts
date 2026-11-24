import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import type { AddressInfo } from 'node:net';
import { createLogger } from '../src/logger.ts';
import type { Logger, LogRecord } from '../src/logger.ts';
import type { GatewayConfig } from '../src/config.ts';
import { createGateway } from '../src/app.ts';
import type { Gateway } from '../src/app.ts';
import type { SocketLike, ConnectionUpdate } from '../src/connection.ts';
import { createFakeSocket } from '../src/connection.ts';

export const TEST_KEY = 'test-internal-key-0123456789abcdef';

export function testConfig(dbPath: string, overrides: Partial<GatewayConfig> = {}): GatewayConfig {
  return {
    host: '127.0.0.1',
    port: 0,
    internalKey: TEST_KEY,
    dbPath,
    maxAttempts: 5,
    retryBaseMs: 10,
    retryMaxMs: 100,
    retryJitter: 0,
    workerIntervalMs: 60_000,
    staleClaimMs: 60_000,
    reconnectBaseMs: 10,
    reconnectMaxMs: 50,
    logLevel: 'debug',
    ...overrides,
  };
}

type FakeSocket = ReturnType<typeof createFakeSocket>;

export type TestHarness = {
  dir: string;
  dbPath: string;
  gateway: Gateway;
  logger: Logger;
  records: () => LogRecord[];
  logs: () => string;
  /** Every socket the factory has produced, in creation order. */
  sockets: FakeSocket[];
  /** The socket the connection is currently using (latest created). */
  socket: () => FakeSocket;
  baseUrl: () => string;
  close: () => Promise<void>;
};

/**
 * Boots a full gateway against a temp SQLite file with a fake Baileys socket.
 *
 * The factory creates a *new* fake socket per call, mirroring real Baileys
 * (which builds a fresh socket object on every reconnect). Reusing one object
 * would silently hide reconnect bugs.
 *
 * Port 0 lets the OS pick a free loopback port so tests never collide.
 */
export async function startHarness(
  options: { config?: Partial<GatewayConfig> } = {},
): Promise<TestHarness> {
  const dir = mkdtempSync(join(tmpdir(), 'wa-gateway-test-'));
  const dbPath = join(dir, 'gateway.db');
  const config = testConfig(dbPath, options.config ?? {});

  const logger = createLogger({ level: 'debug', capture: true, sink: () => {} });
  const sockets: FakeSocket[] = [];

  const gateway = createGateway(config, {
    logger,
    socketFactory: async (): Promise<SocketLike> => {
      const socket = createFakeSocket();
      sockets.push(socket);
      return socket;
    },
    disableWorker: true,
    // The injected factory never touches the network, so connecting is safe and
    // necessary: it is what registers the socket's event listeners.
    autoConnect: true,
  });

  const { port } = await gateway.start();

  const currentSocket = (): FakeSocket => {
    const socket = sockets[sockets.length - 1];
    if (!socket) throw new Error('no fake socket has been created yet');
    return socket;
  };

  return {
    dir,
    dbPath,
    gateway,
    logger,
    records: () => logger.records(),
    logs: () => logger.records().map((r) => JSON.stringify(r)).join('\n'),
    sockets,
    socket: currentSocket,
    baseUrl: () => `http://127.0.0.1:${port}`,
    close: async () => {
      await gateway.shutdown();
      rmSync(dir, { recursive: true, force: true });
    },
  };
}

/** Marks the current fake socket as open so the gateway believes it can send. */
export function markConnected(harness: TestHarness, jid = '628111222333@s.whatsapp.net'): void {
  harness.gateway.auth.state.creds.registered = true;
  harness.gateway.auth.state.creds.me = { id: jid, name: 'Test Sender' } as never;
  harness.socket().emitConnection({ connection: 'open' } satisfies ConnectionUpdate);
}

export function markDisconnected(harness: TestHarness, code = 428): void {
  harness.socket().emitConnection({
    connection: 'close',
    lastDisconnect: { error: Object.assign(new Error('connection closed'), { output: { statusCode: code } }) },
  } satisfies ConnectionUpdate);
}

export function markLoggedOut(harness: TestHarness): void {
  harness.socket().emitConnection({
    connection: 'close',
    lastDisconnect: { error: Object.assign(new Error('logged out'), { output: { statusCode: 401 } }) },
  } satisfies ConnectionUpdate);
}

/**
 * Drives the *current* socket open until the gateway reports connected.
 *
 * Reconnects are scheduled asynchronously and replace the socket object, so a
 * single emit can land on a socket that is about to be discarded (the
 * connection ignores events from superseded sockets). This waits for a socket
 * the helper has not driven yet, then opens it.
 */
export async function awaitReconnected(
  harness: TestHarness,
  jid = '628111222333@s.whatsapp.net',
  timeoutMs = 3_000,
): Promise<void> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (harness.gateway.connection.isConnected()) return;
    const current = harness.socket();
    if (!drivenSockets.has(current)) {
      drivenSockets.add(current);
      markConnected(harness, jid);
    }
    await new Promise((resolve) => setTimeout(resolve, 20));
  }
  throw new Error('timed out waiting for the gateway to reconnect');
}

/** Sockets this helper has already emitted a connection update on. */
const drivenSockets = new WeakSet<object>();

export type ApiResponse<T = Record<string, unknown>> = {
  status: number;
  body: T;
  headers: Headers;
};

export async function postText(
  harness: TestHarness,
  payload: unknown,
  options: { idempotencyKey?: string; key?: string | null; rawBody?: string } = {},
): Promise<ApiResponse> {
  const headers: Record<string, string> = { 'content-type': 'application/json' };
  if (options.key !== null) headers['x-internal-key'] = options.key ?? TEST_KEY;
  if (options.idempotencyKey !== undefined) headers['idempotency-key'] = options.idempotencyKey;

  const res = await fetch(`${harness.baseUrl()}/v1/messages/text`, {
    method: 'POST',
    headers,
    body: options.rawBody ?? JSON.stringify(payload),
  });
  return { status: res.status, body: (await res.json()) as Record<string, unknown>, headers: res.headers };
}

export async function getJson(
  harness: TestHarness,
  path: string,
  options: { key?: string | null } = {},
): Promise<ApiResponse> {
  const headers: Record<string, string> = {};
  if (options.key !== null) headers['x-internal-key'] = options.key ?? TEST_KEY;
  const res = await fetch(`${harness.baseUrl()}${path}`, { headers });
  return { status: res.status, body: (await res.json()) as Record<string, unknown>, headers: res.headers };
}

export const validPayload = (overrides: Record<string, unknown> = {}): Record<string, unknown> => ({
  to: '628123456789',
  text: 'Pesanan INV-1001 sudah dibuat.',
  metadata: { invoice_id: 'INV-1001', event: 'created', source: 'captshop-laravel' },
  ...overrides,
});

export const keyFor = (invoice: string, event: string): string => `transaction:${invoice}:event:${event}`;

/** Waits until `predicate` is true, or throws after `timeoutMs`. */
export async function waitFor(
  predicate: () => boolean | Promise<boolean>,
  timeoutMs = 3_000,
  label = 'condition',
): Promise<void> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (await predicate()) return;
    await new Promise((resolve) => setTimeout(resolve, 10));
  }
  throw new Error(`timed out waiting for ${label}`);
}

export type { AddressInfo };
