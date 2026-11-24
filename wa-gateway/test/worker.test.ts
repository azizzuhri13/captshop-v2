import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import type { TestHarness } from './helpers.ts';
import { openDatabase } from '../src/db.ts';
import {
  startHarness,
  postText,
  validPayload,
  keyFor,
  markConnected,
  markDisconnected,
  markLoggedOut,
  awaitReconnected,
  waitFor,
} from './helpers.ts';

/** Re-drives the socket until connected (reconnects replace the socket object). */
async function ensureConnected(harness: TestHarness): Promise<void> {
  if (harness.gateway.connection.isConnected()) return;
  await awaitReconnected(harness);
}

describe('outbox worker delivery', () => {
  test('a message queued while disconnected is sent after reconnect', async () => {
    const harness = await startHarness();
    try {
      // Accept while disconnected: this must succeed and stay queued.
      const accepted = await postText(harness, validPayload({ metadata: { invoice_id: 'INV-W1', event: 'created' } }), {
        idempotencyKey: keyFor('INV-W1', 'created'),
      });
      assert.equal(accepted.status, 202);
      assert.equal(accepted.body.status, 'queued');
      assert.equal(harness.gateway.outbox.counts().queued, 1);

      // Worker has nothing to do without a socket.
      await harness.gateway.worker.tick();
      assert.equal(harness.gateway.outbox.counts().queued, 1, 'still queued while disconnected');
      assert.equal(harness.gateway.outbox.counts().sending, 0, 'no claim without a sender');

      // Reconnect, then the worker delivers.
      await ensureConnected(harness);
      await harness.gateway.worker.tick();

      const row = harness.gateway.outbox.getById(accepted.body.message_id as string)!;
      assert.equal(row.status, 'sent');
      assert.equal(row.baileys_message_id, 'BAE-1');
      assert.equal(row.attempts, 1);

      const sent = harness.socket().sent;
      assert.equal(sent.length, 1, 'exactly one send call');
      assert.equal(sent[0]!.jid, '628123456789@s.whatsapp.net');
      assert.equal(sent[0]!.text, 'Pesanan INV-1001 sudah dibuat.');
    } finally {
      await harness.close();
    }
  });

  test('10 identical API calls produce exactly one WhatsApp send', async () => {
    const harness = await startHarness();
    try {
      await ensureConnected(harness);
      const payload = validPayload({
        text: 'Sekali saja.',
        metadata: { invoice_id: 'INV-W2', event: 'paid', source: 'captshop-laravel' },
      });
      const key = keyFor('INV-W2', 'paid');

      const results = await Promise.all(Array.from({ length: 10 }, () => postText(harness, payload, { idempotencyKey: key })));
      assert.ok(results.every((r) => r.status === 202 || r.status === 200));

      // Drain the outbox.
      for (let i = 0; i < 3; i += 1) await harness.gateway.worker.tick();

      assert.equal(harness.socket().sent.length, 1, 'one physical send for ten requests');
      const counts = harness.gateway.outbox.counts();
      assert.equal(counts.sent, 1);
      assert.equal(counts.queued, 0);
    } finally {
      await harness.close();
    }
  });

  test('a transient failure is retried with backoff and then succeeds', async () => {
    const harness = await startHarness();
    try {
      await ensureConnected(harness);
      const accepted = await postText(harness, validPayload({ metadata: { invoice_id: 'INV-W3', event: 'created' } }), {
        idempotencyKey: keyFor('INV-W3', 'created'),
      });
      const id = accepted.body.message_id as string;

      // First attempt fails with a transient error.
      harness.socket().failNextSend(Object.assign(new Error('connection closed'), { code: 'ECONNRESET' }));
      await harness.gateway.worker.tick();

      let row = harness.gateway.outbox.getById(id)!;
      assert.equal(row.status, 'failed_retryable');
      assert.equal(row.last_error_code, 'ECONNRESET');
      assert.equal(row.attempts, 1);
      assert.ok(row.next_attempt_at !== null, 'a retry slot is scheduled');

      // Not claimable before the backoff elapses (base is 10ms in tests, so use
      // an explicit clock in the past to prove the ordering).
      assert.equal(harness.gateway.outbox.claimDue(10, (row.next_attempt_at ?? 0) - 1).length, 0);

      // After the backoff, the retry succeeds.
      await waitFor(() => Date.now() >= (row.next_attempt_at ?? 0), 2_000, 'backoff elapsed');
      await harness.gateway.worker.tick();

      row = harness.gateway.outbox.getById(id)!;
      assert.equal(row.status, 'sent');
      assert.equal(row.attempts, 2);
      assert.equal(harness.socket().sent.length, 1, 'one successful send total');
    } finally {
      await harness.close();
    }
  });

  test('a terminal failure is not retried', async () => {
    const harness = await startHarness();
    try {
      await ensureConnected(harness);
      const accepted = await postText(harness, validPayload({ metadata: { invoice_id: 'INV-W4', event: 'created' } }), {
        idempotencyKey: keyFor('INV-W4', 'created'),
      });
      const id = accepted.body.message_id as string;

      harness.socket().failNextSend(Object.assign(new Error('item-not-found'), { code: 'INVALID_JID' }));
      await harness.gateway.worker.tick();

      const row = harness.gateway.outbox.getById(id)!;
      assert.equal(row.status, 'failed_terminal');
      assert.equal(row.last_error_code, 'INVALID_JID');
      assert.equal(row.next_attempt_at, null);

      // Further ticks never touch it again.
      await harness.gateway.worker.tick();
      assert.equal(harness.gateway.outbox.getById(id)?.status, 'failed_terminal');
      assert.equal(harness.socket().sent.length, 0);
    } finally {
      await harness.close();
    }
  });

  test('sends are retried across a real disconnect/reconnect cycle', async () => {
    const harness = await startHarness();
    try {
      await ensureConnected(harness);
      const accepted = await postText(harness, validPayload({ metadata: { invoice_id: 'INV-W5', event: 'created' } }), {
        idempotencyKey: keyFor('INV-W5', 'created'),
      });
      const id = accepted.body.message_id as string;

      // Socket drops, message cannot be sent.
      markDisconnected(harness, 428);
      await waitFor(() => !harness.gateway.connection.isConnected(), 2_000, 'disconnected');
      await harness.gateway.worker.tick();

      const afterDrop = harness.gateway.outbox.getById(id)!;
      assert.equal(afterDrop.status, 'queued', 'message stays queued while disconnected');
      assert.equal(harness.socket().sent.length, 0);

      // Reconnect and drain.
      await ensureConnected(harness);
      await harness.gateway.worker.tick();

      assert.equal(harness.gateway.outbox.getById(id)?.status, 'sent');
      assert.equal(harness.socket().sent.length, 1);
    } finally {
      await harness.close();
    }
  });
});

describe('logged-out handling', () => {
  test('loggedOut stops retries, terminal-fails pending work and keeps auth', async () => {
    const harness = await startHarness();
    try {
      await ensureConnected(harness);
      const accepted = await postText(harness, validPayload({ metadata: { invoice_id: 'INV-LO1', event: 'created' } }), {
        idempotencyKey: keyFor('INV-LO1', 'created'),
      });

      // Record auth material so we can prove it is not deleted.
      const keysBefore = harness.gateway.db.prepare('SELECT COUNT(*) AS n FROM auth_keys').get() as { n: number };

      markLoggedOut(harness);
      await waitFor(() => harness.gateway.session.get().state === 'logged_out', 2_000, 'logged_out state');

      // Pending work is terminal, not retried.
      const row = harness.gateway.outbox.getById(accepted.body.message_id as string)!;
      assert.equal(row.status, 'failed_terminal');
      assert.equal(row.last_error_code, 'SESSION_LOGGED_OUT');

      // Further ticks do nothing.
      await harness.gateway.worker.tick();
      assert.equal(harness.gateway.outbox.counts().queued, 0);
      assert.equal(harness.socket().sent.length, 0);

      // Auth is preserved for a future manual re-pair decision.
      const keysAfter = harness.gateway.db.prepare('SELECT COUNT(*) AS n FROM auth_keys').get() as { n: number };
      assert.equal(keysAfter.n, keysBefore.n, 'auth keys must not be deleted on logout');

      // Readiness reflects the logged-out state.
      const ready = await fetch(`${harness.baseUrl()}/health/ready`);
      assert.equal(ready.status, 503);
      const body = (await ready.json()) as { reason: string };
      assert.equal(body.reason, 'SESSION_LOGGED_OUT');
    } finally {
      await harness.close();
    }
  });

  test('loggedOut does not schedule a reconnect', async () => {
    const harness = await startHarness();
    try {
      await ensureConnected(harness);
      const before = harness.sockets.length;

      markLoggedOut(harness);
      await waitFor(() => harness.gateway.session.get().state === 'logged_out', 2_000, 'logged_out');
      await new Promise((resolve) => setTimeout(resolve, 200));

      assert.equal(harness.sockets.length, before, 'no new socket after logout');
      assert.equal(harness.gateway.connection.isConnected(), false);
    } finally {
      await harness.close();
    }
  });
});

describe('graceful shutdown', () => {
  test('shutdown returns claimed work to a retryable state', async () => {
    const harness = await startHarness();
    try {
      await ensureConnected(harness);
      const accepted = await postText(harness, validPayload({ metadata: { invoice_id: 'INV-SD', event: 'created' } }), {
        idempotencyKey: keyFor('INV-SD', 'created'),
      });
      const messageId = accepted.body.message_id as string;

      // Claim the message without delivering it: simulates a crash mid-send.
      const claimed = harness.gateway.outbox.claimDue(10);
      assert.equal(claimed.length, 1);
      assert.equal(harness.gateway.outbox.getById(messageId)?.status, 'sending');

      // Shutdown must not leave it stranded.
      await harness.gateway.shutdown();

      const reopened = openDatabase(harness.dbPath);
      try {
        const row = reopened
          .prepare('SELECT status, claim_token, next_attempt_at FROM messages WHERE id = ?')
          .get(messageId) as {
          status: string;
          claim_token: string | null;
          next_attempt_at: number | null;
        };
        assert.equal(row.status, 'failed_retryable', 'claimed work is released on shutdown');
        assert.equal(row.claim_token, null, 'claim token is cleared');
        assert.ok(row.next_attempt_at !== null, 'it becomes due again');
      } finally {
        reopened.close();
      }
    } finally {
      await harness.close().catch(() => {});
    }
  });
});
