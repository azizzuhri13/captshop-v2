import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomBytes } from 'node:crypto';
import { createLogger } from '../src/logger.ts';
import { createGateway } from '../src/app.ts';
import { openDatabase } from '../src/db.ts';
import { createFakeSocket } from '../src/connection.ts';
import type { SocketLike } from '../src/connection.ts';
import { testConfig } from './helpers.ts';

/**
 * Restart / recovery behaviour. These construct the gateway twice against the
 * same SQLite file, which is the only way to prove the spec's "restart retains
 * auth state" and "graceful shutdown returns claimed work" gates.
 */
describe('restart persistence', () => {
  test('a paired session survives a full process restart', async () => {
    const dir = mkdtempSync(join(tmpdir(), 'wa-restart-test-'));
    const dbPath = join(dir, 'gateway.db');
    const config = testConfig(dbPath);
    const logger = createLogger({ level: 'silent', sink: () => {} });

    try {
      // --- first boot: pair, then shut down ---
      const socketA = createFakeSocket();
      const gatewayA = createGateway(config, {
        logger,
        socketFactory: async (): Promise<SocketLike> => socketA,
        disableWorker: true,
        autoConnect: true,
      });
      await gatewayA.start();

      const noisePrivate = randomBytes(32);
      gatewayA.auth.state.creds.registered = true;
      gatewayA.auth.state.creds.me = { id: '628111222333@s.whatsapp.net', name: 'Sender' } as never;
      (gatewayA.auth.state.creds as unknown as Record<string, unknown>).noiseKey = {
        private: new Uint8Array(noisePrivate),
        public: new Uint8Array(noisePrivate),
      };
      await gatewayA.auth.state.keys.set({
        session: { '628999@s.whatsapp.net': new Uint8Array(randomBytes(64)) },
        'pre-key': { '1': { private: new Uint8Array(randomBytes(32)), public: new Uint8Array(randomBytes(32)) } },
      });
      await gatewayA.auth.saveCreds();

      socketA.emitConnection({ connection: 'open' });
      await new Promise((resolve) => setTimeout(resolve, 30));
      assert.equal(gatewayA.connection.isConnected(), true);

      await gatewayA.shutdown();

      // --- second boot: same file, fresh process semantics ---
      const socketB = createFakeSocket();
      const gatewayB = createGateway(config, {
        logger,
        socketFactory: async (): Promise<SocketLike> => socketB,
        disableWorker: true,
        autoConnect: true,
      });
      await gatewayB.start();

      // Auth material is present without any re-pairing.
      assert.equal(gatewayB.auth.isRegistered(), true);
      const creds = gatewayB.auth.readCreds();
      assert.equal(creds.me?.id, '628111222333@s.whatsapp.net');
      assert.deepEqual(Buffer.from(creds.noiseKey.private), noisePrivate);

      const sessions = await gatewayB.auth.state.keys.get('session', ['628999@s.whatsapp.net']);
      assert.ok(sessions['628999@s.whatsapp.net'], 'signal session survived the restart');

      // The sender identity is also remembered for status reporting.
      assert.equal(gatewayB.session.get().sender_jid, '628111222333@s.whatsapp.net');

      await gatewayB.shutdown();
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  test('a message accepted while the gateway was down still sends after restart', async () => {
    const dir = mkdtempSync(join(tmpdir(), 'wa-restart-test-'));
    const dbPath = join(dir, 'gateway.db');
    const config = testConfig(dbPath);
    const logger = createLogger({ level: 'silent', sink: () => {} });

    try {
      // Queue a message with the gateway stopped by writing through a gateway
      // that never connects, then shut it down.
      const socketA = createFakeSocket();
      const gatewayA = createGateway(config, {
        logger,
        socketFactory: async (): Promise<SocketLike> => socketA,
        disableWorker: true,
        autoConnect: true,
      });
      await gatewayA.start();

      const enqueued = gatewayA.outbox.enqueue({
        idempotencyKey: 'transaction:INV-RESTART:event:created',
        to: '628123456789',
        text: 'Terkirim setelah restart.',
        invoiceId: 'INV-RESTART',
        event: 'created',
        source: 'captshop-laravel',
        maxAttempts: 3,
      });
      assert.equal(enqueued.outcome, 'created');
      await gatewayA.shutdown();

      // Second boot: the worker must find and deliver the pre-existing message.
      const socketB = createFakeSocket();
      const gatewayB = createGateway(config, {
        logger,
        socketFactory: async (): Promise<SocketLike> => socketB,
        disableWorker: true,
        autoConnect: true,
      });
      await gatewayB.start();

      gatewayB.auth.state.creds.registered = true;
      gatewayB.auth.state.creds.me = { id: '628111222333@s.whatsapp.net', name: 'Sender' } as never;
      socketB.emitConnection({ connection: 'open' });
      await new Promise((resolve) => setTimeout(resolve, 30));

      await gatewayB.worker.tick();

      const row = gatewayB.outbox.getById(enqueued.message.id)!;
      assert.equal(row.status, 'sent');
      assert.equal(socketB.sent.length, 1);
      assert.equal(socketB.sent[0]!.text, 'Terkirim setelah restart.');

      await gatewayB.shutdown();
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  test('a claim abandoned by a crashed worker is recovered on boot', async () => {
    const dir = mkdtempSync(join(tmpdir(), 'wa-restart-test-'));
    const dbPath = join(dir, 'gateway.db');
    const config = testConfig(dbPath, { staleClaimMs: 50 });
    const logger = createLogger({ level: 'silent', sink: () => {} });

    try {
      // Simulate a crash: claim a message and never complete it.
      const db = openDatabase(dbPath);
      const crashed = createGateway(
        { ...config, port: 0 },
        { logger, socketFactory: async () => createFakeSocket(), disableWorker: true, autoConnect: false, db },
      );
      const enqueued = crashed.outbox.enqueue({
        idempotencyKey: 'transaction:INV-CRASH:event:paid',
        to: '628123456789',
        text: 'Dipulihkan.',
        invoiceId: 'INV-CRASH',
        event: 'paid',
        source: 'captshop-laravel',
        maxAttempts: 3,
      });
      const claimed = crashed.outbox.claimDue(10);
      assert.equal(claimed.length, 1);
      assert.equal(crashed.outbox.getById(enqueued.message.id)?.status, 'sending');

      // A stale claim is only recoverable once its lease expires.
      await new Promise((resolve) => setTimeout(resolve, 120));

      // New process boot: the worker's startup recovery requeues the orphan.
      const socketB = createFakeSocket();
      const gatewayB = createGateway(config, {
        logger,
        socketFactory: async (): Promise<SocketLike> => socketB,
        disableWorker: true,
        autoConnect: true,
      });
      await gatewayB.start();

      const recovered = gatewayB.worker.recoverStaleClaims();
      assert.equal(recovered, 1, 'the abandoned claim is requeued');
      assert.equal(gatewayB.outbox.getById(enqueued.message.id)?.status, 'failed_retryable');

      gatewayB.auth.state.creds.registered = true;
      gatewayB.auth.state.creds.me = { id: '628111222333@s.whatsapp.net', name: 'Sender' } as never;
      socketB.emitConnection({ connection: 'open' });
      await new Promise((resolve) => setTimeout(resolve, 30));
      await gatewayB.worker.tick();

      assert.equal(gatewayB.outbox.getById(enqueued.message.id)?.status, 'sent');
      assert.equal(socketB.sent.length, 1);

      await gatewayB.shutdown();
      // The first gateway shared the db handle; close it explicitly.
      try {
        db.close();
      } catch {
        // already closed
      }
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
});
