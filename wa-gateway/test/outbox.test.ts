import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { openDatabase } from '../src/db.ts';
import { Outbox, computeBackoffMs, canonicalPayloadHash } from '../src/outbox.ts';
import type { Db } from '../src/db.ts';

function withOutbox(fn: (outbox: Outbox, db: Db) => void): void {
  const dir = mkdtempSync(join(tmpdir(), 'wa-outbox-test-'));
  const db = openDatabase(join(dir, 'gateway.db'));
  try {
    fn(new Outbox(db), db);
  } finally {
    db.close();
    rmSync(dir, { recursive: true, force: true });
  }
}

const baseInput = {
  idempotencyKey: 'transaction:INV-1:event:created',
  to: '628123456789',
  text: 'Pesanan dibuat.',
  invoiceId: 'INV-1',
  event: 'created',
  source: 'captshop-laravel',
  maxAttempts: 3,
};

describe('outbox idempotency', () => {
  test('10 identical enqueues produce exactly one message', () => {
    withOutbox((outbox) => {
      const results = Array.from({ length: 10 }, () => outbox.enqueue(baseInput));
      const created = results.filter((r) => r.outcome === 'created');
      const duplicate = results.filter((r) => r.outcome === 'duplicate');

      assert.equal(created.length, 1, 'exactly one insert');
      assert.equal(duplicate.length, 9, 'the rest are replays');

      const ids = new Set(results.map((r) => r.message.id));
      assert.equal(ids.size, 1, 'every replay points at the same message id');

      const counts = outbox.counts();
      assert.equal(counts.queued, 1, 'only one row exists in the outbox');
    });
  });

  test('identical replay with reordered metadata keys is still a duplicate', () => {
    withOutbox((outbox) => {
      const first = outbox.enqueue(baseInput);
      // Same semantic payload, but the caller serialised metadata differently.
      const second = outbox.enqueue({ ...baseInput, source: 'captshop-laravel' });
      assert.equal(first.outcome, 'created');
      assert.equal(second.outcome, 'duplicate');
      assert.equal(second.message.id, first.message.id);
    });
  });

  test('same key with a different body returns conflict and does not overwrite', () => {
    withOutbox((outbox) => {
      const first = outbox.enqueue(baseInput);
      const conflict = outbox.enqueue({ ...baseInput, text: 'Pesanan BERBEDA.' });

      assert.equal(conflict.outcome, 'conflict');
      assert.equal(conflict.message.id, first.message.id, 'conflict returns the original row');

      const stored = outbox.getById(first.message.id);
      assert.equal(stored?.body_text, 'Pesanan dibuat.', 'original text is preserved');
      assert.equal(outbox.counts().queued, 1);
    });
  });

  test('same key with a different recipient is a conflict', () => {
    withOutbox((outbox) => {
      outbox.enqueue(baseInput);
      const conflict = outbox.enqueue({ ...baseInput, to: '628999999999' });
      assert.equal(conflict.outcome, 'conflict');
    });
  });

  test('the payload hash ignores fields that do not change the message', () => {
    const a = canonicalPayloadHash({ to: '6281', text: 'hi', invoiceId: 'INV', event: 'paid' });
    const b = canonicalPayloadHash({ to: '6281', text: 'hi', invoiceId: 'INV', event: 'paid' });
    const c = canonicalPayloadHash({ to: '6281', text: 'hi', invoiceId: 'INV', event: 'created' });
    assert.equal(a, b);
    assert.notEqual(a, c);
    assert.match(a, /^[0-9a-f]{64}$/);
  });
});

describe('outbox claiming', () => {
  test('claimDue hands each row to exactly one claimer', () => {
    withOutbox((outbox) => {
      outbox.enqueue({ ...baseInput, idempotencyKey: 'transaction:INV-A:event:created', invoiceId: 'INV-A' });
      outbox.enqueue({ ...baseInput, idempotencyKey: 'transaction:INV-B:event:created', invoiceId: 'INV-B' });

      const first = outbox.claimDue(10);
      assert.equal(first.length, 2, 'both due rows claimed');

      const second = outbox.claimDue(10);
      assert.equal(second.length, 0, 'a claimed row cannot be claimed twice');

      assert.ok(first.every((m) => m.status === 'sending'));
      assert.ok(first.every((m) => typeof m.claim_token === 'string' && m.claim_token.length > 0));
      assert.ok(first.every((m) => m.attempts === 1), 'attempt counter increments on claim');
    });
  });

  test('claimDue respects next_attempt_at', () => {
    withOutbox((outbox) => {
      const { message } = outbox.enqueue(baseInput);
      const claimed = outbox.claimDue(10);
      assert.equal(claimed.length, 1);

      // Fail it with a retry slot in the future.
      const token = claimed[0]!.claim_token;
      outbox.markFailed(message.id, token, 'NOT_CONNECTED', { terminal: false, retryAt: Date.now() + 60_000 });

      assert.equal(outbox.claimDue(10).length, 0, 'not due yet');
      assert.equal(outbox.claimDue(10, Date.now() + 61_000).length, 1, 'due once the clock passes');
    });
  });

  test('a stale sending row is requeued and its old token can no longer complete it', () => {
    withOutbox((outbox) => {
      const { message } = outbox.enqueue(baseInput);
      const [claimed] = outbox.claimDue(10);
      const oldToken = claimed!.claim_token;

      // Simulate a crashed worker: the claim is old.
      const requeued = outbox.requeueStaleClaims(Date.now() + 10_000, 1_000);
      assert.equal(requeued, 1);
      assert.equal(outbox.getById(message.id)?.status, 'failed_retryable');

      // New claim by a new worker.
      const [reclaimed] = outbox.claimDue(10, Date.now() + 10_000);
      assert.ok(reclaimed);
      assert.notEqual(reclaimed!.claim_token, oldToken);

      // The stale worker's late completion must be rejected.
      const staleOk = outbox.markSent(message.id, oldToken, 'BAE-STALE', Date.now() + 10_000);
      assert.equal(staleOk, false, 'fenced write from the old claim must not apply');
      assert.equal(outbox.getById(message.id)?.status, 'sending', 'row still owned by the new claim');
    });
  });
});

describe('outbox failure handling', () => {
  test('retryable failure keeps the message sendable and records only an error code', () => {
    withOutbox((outbox) => {
      const { message } = outbox.enqueue(baseInput);
      const [claimed] = outbox.claimDue(10);
      const ok = outbox.markFailed(message.id, claimed!.claim_token, 'NOT_CONNECTED', {
        terminal: false,
        retryAt: Date.now(),
      });
      assert.equal(ok, true);

      const row = outbox.getById(message.id)!;
      assert.equal(row.status, 'failed_retryable');
      assert.equal(row.last_error_code, 'NOT_CONNECTED');
      assert.equal(row.claim_token, null);
    });
  });

  test('terminal failure is never retried', () => {
    withOutbox((outbox) => {
      const { message } = outbox.enqueue(baseInput);
      const [claimed] = outbox.claimDue(10);
      outbox.markFailed(message.id, claimed!.claim_token, 'INVALID_JID', { terminal: true });

      const row = outbox.getById(message.id)!;
      assert.equal(row.status, 'failed_terminal');
      assert.equal(row.next_attempt_at, null);
      assert.equal(outbox.claimDue(10, Date.now() + 10_000_000).length, 0);
    });
  });

  test('exhausting max_attempts turns a retryable failure terminal', () => {
    withOutbox((outbox) => {
      const { message } = outbox.enqueue({ ...baseInput, maxAttempts: 2 });

      for (let i = 0; i < 2; i += 1) {
        const [claimed] = outbox.claimDue(10, Date.now() + i * 1_000);
        assert.ok(claimed, `attempt ${i + 1} should be claimable`);
        outbox.markFailed(message.id, claimed!.claim_token, 'NOT_CONNECTED', {
          terminal: false,
          retryAt: Date.now() + i * 1_000,
        });
      }

      const row = outbox.getById(message.id)!;
      assert.equal(row.attempts, 2);
      assert.equal(row.status, 'failed_terminal', 'attempt cap forces terminal');
      assert.equal(outbox.claimDue(10, Date.now() + 10_000_000).length, 0);
    });
  });

  test('logged-out state terminal-fails pending and in-flight work', () => {
    withOutbox((outbox) => {
      outbox.enqueue({ ...baseInput, idempotencyKey: 'transaction:INV-P:event:paid', invoiceId: 'INV-P' });
      outbox.enqueue({ ...baseInput, idempotencyKey: 'transaction:INV-Q:event:paid', invoiceId: 'INV-Q' });

      const [claimed] = outbox.claimDue(1);
      assert.equal(claimed?.status, 'sending');

      const inFlight = outbox.failInFlight('SESSION_LOGGED_OUT');
      const pending = outbox.failAllPending('SESSION_LOGGED_OUT');
      assert.equal(inFlight, 1);
      assert.equal(pending, 1);

      const counts = outbox.counts();
      assert.equal(counts.terminal, 2);
      assert.equal(counts.queued, 0);
      assert.equal(counts.sending, 0);
    });
  });
});

describe('outbox receipts', () => {
  test('receipts upgrade sent -> delivered -> read and never regress', () => {
    withOutbox((outbox) => {
      const { message } = outbox.enqueue(baseInput);
      const [claimed] = outbox.claimDue(10);
      outbox.markSent(message.id, claimed!.claim_token, 'BAE-1');

      assert.equal(outbox.applyReceipt('BAE-1', 'delivered'), 1);
      assert.equal(outbox.getById(message.id)?.status, 'delivered');

      assert.equal(outbox.applyReceipt('BAE-1', 'read'), 1);
      const row = outbox.getById(message.id)!;
      assert.equal(row.status, 'read');
      assert.ok(row.delivered_at);
      assert.ok(row.read_at);

      // A late delivered receipt must not downgrade a read message.
      assert.equal(outbox.applyReceipt('BAE-1', 'delivered'), 0);
      assert.equal(outbox.getById(message.id)?.status, 'read');
    });
  });

  test('a read receipt upgrades a sent message directly', () => {
    withOutbox((outbox) => {
      const { message } = outbox.enqueue(baseInput);
      const [claimed] = outbox.claimDue(10);
      outbox.markSent(message.id, claimed!.claim_token, 'BAE-2');

      assert.equal(outbox.applyReceipt('BAE-2', 'read'), 1);
      const row = outbox.getById(message.id)!;
      assert.equal(row.status, 'read');
      assert.ok(row.delivered_at, 'delivered_at is backfilled when read arrives first');
    });
  });

  test('receipts for unknown message ids are ignored', () => {
    withOutbox((outbox) => {
      assert.equal(outbox.applyReceipt('does-not-exist', 'read'), 0);
    });
  });
});

describe('backoff', () => {
  test('grows exponentially, caps, and stays within jitter bounds', () => {
    const opts = { baseMs: 1_000, maxMs: 60_000, jitter: 0 };
    assert.equal(computeBackoffMs(1, opts), 1_000);
    assert.equal(computeBackoffMs(2, opts), 2_000);
    assert.equal(computeBackoffMs(3, opts), 4_000);
    assert.equal(computeBackoffMs(10, opts), 60_000, 'capped');
    assert.equal(computeBackoffMs(100, opts), 60_000, 'still capped, no overflow');

    const jittered = { baseMs: 1_000, maxMs: 60_000, jitter: 0.5, random: () => 1 };
    const value = computeBackoffMs(1, jittered);
    assert.ok(value >= 1_000 && value <= 60_000, `jittered value ${value} within bounds`);
  });

  test('attempt 0 is treated as the first attempt', () => {
    assert.equal(computeBackoffMs(0, { baseMs: 500, maxMs: 10_000, jitter: 0 }), 500);
  });
});
