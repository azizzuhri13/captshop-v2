import { createHash, randomUUID } from 'node:crypto';
import type { Db } from './db.ts';
import { withImmediateTransaction } from './db.ts';

export const MESSAGE_STATUSES = [
  'queued',
  'sending',
  'sent',
  'delivered',
  'read',
  'failed_retryable',
  'failed_terminal',
] as const;

export type MessageStatus = (typeof MESSAGE_STATUSES)[number];

export type OutboxMessage = {
  id: string;
  idempotency_key: string;
  payload_hash: string;
  to_e164: string;
  body_text: string;
  invoice_id: string | null;
  event: string | null;
  source: string | null;
  status: MessageStatus;
  attempts: number;
  max_attempts: number;
  next_attempt_at: number | null;
  claimed_at: number | null;
  claim_token: string | null;
  baileys_message_id: string | null;
  last_error_code: string | null;
  last_error_at: number | null;
  created_at: number;
  updated_at: number;
  sent_at: number | null;
  delivered_at: number | null;
  read_at: number | null;
};

/** Wire-safe projection: never exposes the body text or the raw idempotency key. */
export type MessageView = {
  message_id: string;
  status: MessageStatus;
  to: string;
  invoice_id: string | null;
  event: string | null;
  source: string | null;
  attempts: number;
  max_attempts: number;
  next_attempt_at: string | null;
  baileys_message_id: string | null;
  last_error_code: string | null;
  created_at: string;
  updated_at: string;
  sent_at: string | null;
  delivered_at: string | null;
  read_at: string | null;
};

export function toMessageView(row: OutboxMessage): MessageView {
  const iso = (ms: number | null): string | null => (ms === null ? null : new Date(ms).toISOString());
  return {
    message_id: row.id,
    status: row.status,
    to: row.to_e164,
    invoice_id: row.invoice_id,
    event: row.event,
    source: row.source,
    attempts: row.attempts,
    max_attempts: row.max_attempts,
    next_attempt_at: iso(row.next_attempt_at),
    baileys_message_id: row.baileys_message_id,
    last_error_code: row.last_error_code,
    created_at: iso(row.created_at) as string,
    updated_at: iso(row.updated_at) as string,
    sent_at: iso(row.sent_at),
    delivered_at: iso(row.delivered_at),
    read_at: iso(row.read_at),
  };
}

/**
 * Canonical payload hash.
 *
 * Only the fields that determine *what gets sent to whom* participate, with a
 * fixed key order, so a retry from Laravel that reorders JSON keys is treated
 * as the same request while a changed recipient or body is a conflict.
 */
export function canonicalPayloadHash(input: { to: string; text: string; invoiceId?: string | null; event?: string | null }): string {
  const canonical = JSON.stringify({
    to: input.to,
    text: input.text,
    invoice_id: input.invoiceId ?? null,
    event: input.event ?? null,
  });
  return createHash('sha256').update(canonical, 'utf8').digest('hex');
}

export type EnqueueInput = {
  idempotencyKey: string;
  to: string;
  text: string;
  invoiceId?: string | null;
  event?: string | null;
  source?: string | null;
  maxAttempts: number;
};

export type EnqueueResult =
  | { outcome: 'created'; message: OutboxMessage }
  | { outcome: 'duplicate'; message: OutboxMessage }
  | { outcome: 'conflict'; message: OutboxMessage };

export type ClaimedMessage = OutboxMessage & { claim_token: string };

export type BackoffOptions = {
  baseMs: number;
  maxMs: number;
  /** 0..1 — fraction of the computed delay to randomise by. */
  jitter: number;
  random?: () => number;
};

/** Bounded exponential backoff: base * 2^(attempts-1), capped, then jittered. */
export function computeBackoffMs(attempts: number, options: BackoffOptions): number {
  const safeAttempts = Math.max(1, attempts);
  const raw = options.baseMs * 2 ** Math.min(safeAttempts - 1, 20);
  const capped = Math.min(raw, options.maxMs);
  if (options.jitter <= 0) return Math.round(capped);
  const random = options.random ?? Math.random;
  const spread = capped * options.jitter;
  const jittered = capped - spread + random() * spread * 2;
  return Math.max(0, Math.round(Math.min(jittered, options.maxMs)));
}

export class Outbox {
  private readonly db: Db;

  constructor(db: Db) {
    this.db = db;
  }

  /**
   * Inserts a message or reports the existing one.
   *
   * Runs inside one IMMEDIATE transaction so two concurrent identical POSTs
   * cannot both insert: the UNIQUE index on idempotency_key is the arbiter and
   * the loser reads back the winner's row.
   */
  enqueue(input: EnqueueInput): EnqueueResult {
    const payloadHash = canonicalPayloadHash({
      to: input.to,
      text: input.text,
      invoiceId: input.invoiceId ?? null,
      event: input.event ?? null,
    });
    const now = Date.now();
    const id = randomUUID();

    return withImmediateTransaction(this.db, (): EnqueueResult => {
      const existing = this.findByKey(input.idempotencyKey);
      if (existing) {
        return {
          outcome: existing.payload_hash === payloadHash ? 'duplicate' : 'conflict',
          message: existing,
        };
      }

      this.db
        .prepare(
          `INSERT INTO messages (
             id, idempotency_key, payload_hash, to_e164, body_text,
             invoice_id, event, source, status, attempts, max_attempts,
             next_attempt_at, created_at, updated_at
           ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'queued', 0, ?, ?, ?, ?)`,
        )
        .run(
          id,
          input.idempotencyKey,
          payloadHash,
          input.to,
          input.text,
          input.invoiceId ?? null,
          input.event ?? null,
          input.source ?? null,
          input.maxAttempts,
          now,
          now,
          now,
        );

      return { outcome: 'created', message: this.getById(id) as OutboxMessage };
    });
  }

  findByKey(idempotencyKey: string): OutboxMessage | undefined {
    return this.db
      .prepare(`SELECT * FROM messages WHERE idempotency_key = ?`)
      .get(idempotencyKey) as OutboxMessage | undefined;
  }

  getById(id: string): OutboxMessage | undefined {
    return this.db.prepare(`SELECT * FROM messages WHERE id = ?`).get(id) as OutboxMessage | undefined;
  }

  /**
   * Atomically claims up to `limit` due messages.
   *
   * `claim_token` makes the claim fenced: a worker that lost its lease (stale
   * claim reclaimed after a crash) cannot later mark the row sent, because its
   * update no longer matches the token.
   */
  claimDue(limit: number, now: number = Date.now()): ClaimedMessage[] {
    return withImmediateTransaction(this.db, () => {
      const due = this.db
        .prepare(
          `SELECT id FROM messages
            WHERE status IN ('queued','failed_retryable')
              AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
            ORDER BY COALESCE(next_attempt_at, created_at) ASC
            LIMIT ?`,
        )
        .all(now, limit) as Array<{ id: string }>;

      const claimed: ClaimedMessage[] = [];
      const update = this.db.prepare(
        `UPDATE messages
            SET status = 'sending', claimed_at = ?, claim_token = ?, attempts = attempts + 1, updated_at = ?
          WHERE id = ? AND status IN ('queued','failed_retryable')`,
      );

      for (const { id } of due) {
        const token = randomUUID();
        const info = update.run(now, token, now, id);
        if (info.changes === 1) {
          const row = this.getById(id) as ClaimedMessage;
          claimed.push({ ...row, claim_token: token });
        }
      }
      return claimed;
    });
  }

  /**
   * Returns claims abandoned by a crashed worker to the retryable pool.
   * Called on boot and periodically by the worker.
   */
  requeueStaleClaims(now: number, staleAfterMs: number): number {
    const cutoff = now - staleAfterMs;
    const info = this.db
      .prepare(
        `UPDATE messages
            SET status = 'failed_retryable',
                next_attempt_at = ?,
                claim_token = NULL,
                claimed_at = NULL,
                last_error_code = COALESCE(last_error_code, 'WORKER_LEASE_EXPIRED'),
                updated_at = ?
          WHERE status = 'sending' AND claimed_at IS NOT NULL AND claimed_at <= ?`,
      )
      .run(now, now, cutoff);
    return info.changes;
  }

  markSent(id: string, claimToken: string, baileysMessageId: string | null, now: number = Date.now()): boolean {
    const info = this.db
      .prepare(
        `UPDATE messages
            SET status = 'sent', baileys_message_id = COALESCE(?, baileys_message_id),
                sent_at = ?, claimed_at = NULL, claim_token = NULL,
                next_attempt_at = NULL, last_error_code = NULL, updated_at = ?
          WHERE id = ? AND claim_token = ? AND status = 'sending'`,
      )
      .run(baileysMessageId, now, now, id, claimToken);
    return info.changes === 1;
  }

  /**
   * Records a failed attempt. Terminal failures never come back to the queue;
   * retryable ones get a backoff slot and stay claimable.
   */
  markFailed(
    id: string,
    claimToken: string,
    errorCode: string,
    options: { terminal: boolean; retryAt?: number },
    now: number = Date.now(),
  ): boolean {
    const row = this.getById(id);
    if (!row) return false;
    const exhausted = row.attempts >= row.max_attempts;
    const terminal = options.terminal || exhausted;
    const info = this.db
      .prepare(
        `UPDATE messages
            SET status = ?, next_attempt_at = ?, claimed_at = NULL, claim_token = NULL,
                last_error_code = ?, last_error_at = ?, updated_at = ?
          WHERE id = ? AND claim_token = ? AND status = 'sending'`,
      )
      .run(
        terminal ? 'failed_terminal' : 'failed_retryable',
        terminal ? null : (options.retryAt ?? now),
        errorCode,
        now,
        now,
        id,
        claimToken,
      );
    return info.changes === 1;
  }

  /**
   * Terminal-fails every message that has not been handed to Baileys yet.
   * Used when the session is logged out: the spec says stop retrying and
   * require re-pairing, not silently drop or infinitely retry.
   */
  failAllPending(errorCode: string, now: number = Date.now()): number {
    const info = this.db
      .prepare(
        `UPDATE messages
            SET status = 'failed_terminal', next_attempt_at = NULL,
                last_error_code = ?, last_error_at = ?, updated_at = ?
          WHERE status IN ('queued','failed_retryable')`,
      )
      .run(errorCode, now, now);
    return info.changes;
  }

  /**
   * Marks a `sending` row terminal after the session was logged out mid-flight.
   */
  failInFlight(errorCode: string, now: number = Date.now()): number {
    const info = this.db
      .prepare(
        `UPDATE messages
            SET status = 'failed_terminal', next_attempt_at = NULL,
                claimed_at = NULL, claim_token = NULL,
                last_error_code = ?, last_error_at = ?, updated_at = ?
          WHERE status = 'sending'`,
      )
      .run(errorCode, now, now);
    return info.changes;
  }

  /** Receipt-driven status upgrade: sent -> delivered -> read (never backwards). */
  applyReceipt(baileysMessageId: string, status: 'delivered' | 'read', now: number = Date.now()): number {
    if (status === 'delivered') {
      const info = this.db
        .prepare(
          `UPDATE messages
              SET status = 'delivered', delivered_at = COALESCE(delivered_at, ?), updated_at = ?
            WHERE baileys_message_id = ? AND status = 'sent'`,
        )
        .run(now, now, baileysMessageId);
      return info.changes;
    }

    const info = this.db
      .prepare(
        `UPDATE messages
            SET status = 'read',
                delivered_at = COALESCE(delivered_at, ?),
                read_at = COALESCE(read_at, ?),
                updated_at = ?
          WHERE baileys_message_id = ? AND status IN ('sent','delivered')`,
      )
      .run(now, now, now, baileysMessageId);
    return info.changes;
  }

  counts(): Record<MessageStatus, number> & { pending: number; retryable: number; terminal: number } {
    const rows = this.db
      .prepare(`SELECT status, COUNT(*) AS n FROM messages GROUP BY status`)
      .all() as Array<{ status: MessageStatus; n: number }>;
    const base = Object.fromEntries(MESSAGE_STATUSES.map((s) => [s, 0])) as Record<MessageStatus, number>;
    for (const row of rows) base[row.status] = row.n;
    return {
      ...base,
      pending: base.queued + base.sending,
      retryable: base.failed_retryable,
      terminal: base.failed_terminal,
    };
  }
}
