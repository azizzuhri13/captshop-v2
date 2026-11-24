import type { Logger } from './logger.ts';
import type { Outbox, ClaimedMessage, BackoffOptions } from './outbox.ts';
import { computeBackoffMs } from './outbox.ts';

/**
 * Error taxonomy.
 *
 * The classification decides retry vs terminal, and the *code* (never the raw
 * error text) is what gets persisted, so a Baileys error containing a JID or a
 * partial payload cannot leak into the database or the logs.
 */
export type SendFailureKind = 'transient' | 'terminal';

export type ClassifiedFailure = {
  code: string;
  kind: SendFailureKind;
};

const TRANSIENT_CODES = new Set([
  'NOT_CONNECTED',
  'ECONNRESET',
  'ETIMEDOUT',
  'EPIPE',
  'ENOTFOUND',
  'EAI_AGAIN',
  'ECONNREFUSED',
  'EHOSTUNREACH',
  'ENETUNREACH',
  'STREAM_ERROR',
  'TIMEOUT',
  'SEND_TIMEOUT',
  'INTERNAL_SERVER_ERROR',
  'SERVICE_UNAVAILABLE',
  'UNKNOWN_TRANSIENT',
]);

const TERMINAL_CODES = new Set([
  'INVALID_JID',
  'INVALID_PHONE',
  'NOT_ON_WHATSAPP',
  'BAD_REQUEST',
  'FORBIDDEN',
  'LOGGED_OUT',
  'SESSION_LOGGED_OUT',
]);

/**
 * Maps an error from the send path onto a retry decision.
 *
 * Rules from the spec: transient disconnect/timeouts retry with bounded
 * backoff; an invalid destination is terminal; loggedOut stops retries.
 */
export function classifySendFailure(error: unknown): ClassifiedFailure {
  const err = (error ?? {}) as {
    code?: unknown;
    message?: unknown;
    output?: { statusCode?: unknown };
    isBoom?: boolean;
  };

  const explicit = typeof err.code === 'string' ? err.code.toUpperCase() : null;
  if (explicit && TERMINAL_CODES.has(explicit)) {
    return { code: explicit, kind: 'terminal' };
  }
  if (explicit && TRANSIENT_CODES.has(explicit)) {
    return { code: explicit, kind: 'transient' };
  }

  const status = typeof err.output?.statusCode === 'number' ? err.output.statusCode : null;
  if (status !== null) {
    if (status === 401) return { code: 'LOGGED_OUT', kind: 'terminal' };
    if (status === 403) return { code: 'FORBIDDEN', kind: 'terminal' };
    if (status === 400 || status === 404) return { code: 'INVALID_JID', kind: 'terminal' };
    if (status === 408 || status === 428) return { code: 'TIMEOUT', kind: 'transient' };
    if (status >= 500) return { code: 'SERVICE_UNAVAILABLE', kind: 'transient' };
  }

  // Fall back to the message text, matched narrowly. The raw text is never
  // stored; only the mapped code is.
  const message = typeof err.message === 'string' ? err.message.toLowerCase() : '';
  if (message.includes('not-authorized') || message.includes('logged out')) {
    return { code: 'LOGGED_OUT', kind: 'terminal' };
  }
  if (message.includes('not on whatsapp') || message.includes('item-not-found')) {
    return { code: 'NOT_ON_WHATSAPP', kind: 'terminal' };
  }
  if (message.includes('invalid jid') || message.includes('bad jid')) {
    return { code: 'INVALID_JID', kind: 'terminal' };
  }
  if (message.includes('not connected') || message.includes('connection closed') || message.includes('timed out')) {
    return { code: 'NOT_CONNECTED', kind: 'transient' };
  }

  // Unknown errors are treated as transient but bounded by max_attempts, so a
  // permanently broken payload eventually lands in failed_terminal rather than
  // retrying forever.
  return { code: 'UNKNOWN_TRANSIENT', kind: 'transient' };
}

export type SenderFn = (jid: string, text: string) => Promise<{ id: string | null }>;

export type WorkerOptions = {
  outbox: Outbox;
  logger: Logger;
  /** Returns a sender, or null when the session cannot send right now. */
  getSender: () => SenderFn | null;
  backoff: BackoffOptions;
  batchSize: number;
  intervalMs: number;
  staleClaimMs: number;
  now?: () => number;
  /** Per-send timeout so a hung socket cannot wedge the worker. */
  sendTimeoutMs: number;
};

/**
 * Single-claiming outbox worker.
 *
 * Only one instance should run per database (the spec's single-gateway rule),
 * but the claim query is written to stay correct even if two ever overlap: the
 * `status IN ('queued','failed_retryable')` guard plus the claim token means a
 * row can only be claimed once and can only be completed by its claimer.
 */
export class OutboxWorker {
  private timer: NodeJS.Timeout | null = null;
  private running = false;
  private draining = false;
  private readonly now: () => number;

  private readonly options: WorkerOptions;

  constructor(options: WorkerOptions) {
    this.options = options;
    this.now = options.now ?? (() => Date.now());
  }

  /** Requeues claims left behind by a crashed worker. Returns rows affected. */
  recoverStaleClaims(): number {
    const recovered = this.options.outbox.requeueStaleClaims(this.now(), this.options.staleClaimMs);
    if (recovered > 0) {
      this.options.logger.warn('worker.stale_claims_requeued', { count: recovered });
    }
    return recovered;
  }

  start(): void {
    if (this.timer) return;
    this.recoverStaleClaims();
    this.timer = setInterval(() => {
      void this.tick();
    }, this.options.intervalMs);
    this.timer.unref?.();
  }

  async stop(): Promise<void> {
    this.draining = true;
    if (this.timer) {
      clearInterval(this.timer);
      this.timer = null;
    }
    // Wait for an in-flight tick so shutdown does not race a send.
    const deadline = this.now() + 10_000;
    while (this.running && this.now() < deadline) {
      await new Promise((resolve) => setTimeout(resolve, 10));
    }
  }

  /**
   * Graceful-shutdown hook: any row still claimed by this worker goes back to
   * failed_retryable with no delay so the next boot sends it immediately.
   */
  releaseClaims(): number {
    const released = this.options.outbox.requeueStaleClaims(this.now(), 0);
    if (released > 0) {
      this.options.logger.warn('worker.claims_released_on_shutdown', { count: released });
    }
    return released;
  }

  /** One pass over the outbox. Safe to call directly from tests. */
  async tick(): Promise<void> {
    if (this.running || this.draining) return;
    this.running = true;
    try {
      const sender = this.options.getSender();
      if (!sender) {
        // Nothing to do: messages stay queued, which is the spec's requirement
        // that acceptance does not depend on connectivity.
        return;
      }

      const claimed = this.options.outbox.claimDue(this.options.batchSize, this.now());
      for (const message of claimed) {
        if (this.draining) break;
        await this.deliver(sender, message);
      }
    } catch (error) {
      this.options.logger.error('worker.tick_failed', { error });
    } finally {
      this.running = false;
    }
  }

  private async deliver(sender: SenderFn, message: ClaimedMessage): Promise<void> {
    const jid = `${message.to_e164}@s.whatsapp.net`;
    try {
      const result = await withTimeout(sender(jid, message.body_text), this.options.sendTimeoutMs);
      const ok = this.options.outbox.markSent(message.id, message.claim_token, result.id, this.now());
      if (!ok) {
        // Our lease was taken over (crash recovery); the other worker owns it now.
        this.options.logger.warn('worker.claim_lost', { message_id: message.id });
        return;
      }
      this.options.logger.info('worker.sent', {
        message_id: message.id,
        attempt: message.attempts,
        baileys_message_id: result.id,
      });
    } catch (error) {
      const failure = classifySendFailure(error);
      const retryAt =
        failure.kind === 'transient'
          ? this.now() + computeBackoffMs(message.attempts, this.options.backoff)
          : undefined;
      this.options.outbox.markFailed(
        message.id,
        message.claim_token,
        failure.code,
        failure.kind === 'terminal'
          ? { terminal: true }
          : { terminal: false, retryAt: retryAt as number },
        this.now(),
      );
      this.options.logger.warn('worker.send_failed', {
        message_id: message.id,
        attempt: message.attempts,
        error_code: failure.code,
        retryable: failure.kind === 'transient',
      });
    }
  }
}

/** Rejects if `promise` does not settle within `ms`. */
export function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T> {
  if (ms <= 0) return promise;
  return new Promise<T>((resolve, reject) => {
    const timer = setTimeout(() => {
      const error = new Error('send timed out');
      (error as Error & { code?: string }).code = 'SEND_TIMEOUT';
      reject(error);
    }, ms);
    timer.unref?.();
    promise.then(
      (value) => {
        clearTimeout(timer);
        resolve(value);
      },
      (error: unknown) => {
        clearTimeout(timer);
        reject(error);
      },
    );
  });
}
