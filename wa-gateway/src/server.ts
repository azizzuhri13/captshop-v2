import { createServer } from 'node:http';
import type { IncomingMessage, Server, ServerResponse } from 'node:http';
import type { Logger } from './logger.ts';
import type { Outbox, MessageView } from './outbox.ts';
import { toMessageView } from './outbox.ts';
import type { SessionStore } from './session-store.ts';
import { verifyInternalKey } from './auth.ts';
import { validateIdempotencyKey, validateSendTextRequest } from './validation.ts';
import { maskPhone } from './phone.ts';

export type GatewayDeps = {
  outbox: Outbox;
  session: SessionStore;
  logger: Logger;
  internalKey: string;
  /** True when a socket is open and registered — drives /health/ready. */
  isConnected: () => boolean;
  /** True when creds show a completed pairing. */
  isRegistered: () => boolean;
  maxAttempts: number;
  /** Max accepted request body, in bytes. */
  maxBodyBytes?: number;
};

const DEFAULT_MAX_BODY = 32 * 1024;

class HttpError extends Error {
  readonly status: number;
  readonly code: string;

  constructor(status: number, code: string) {
    super(code);
    this.status = status;
    this.code = code;
  }
}

function sendJson(res: ServerResponse, status: number, payload: unknown): void {
  const body = JSON.stringify(payload);
  res.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'content-length': Buffer.byteLength(body),
    'cache-control': 'no-store',
    'x-content-type-options': 'nosniff',
  });
  res.end(body);
}

async function readBody(req: IncomingMessage, limit: number): Promise<string> {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks: Buffer[] = [];
    req.on('data', (chunk: Buffer) => {
      size += chunk.length;
      if (size > limit) {
        reject(new HttpError(413, 'PAYLOAD_TOO_LARGE'));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', (error) => reject(error));
  });
}

function sessionStatusPayload(deps: GatewayDeps): Record<string, unknown> {
  const row = deps.session.get();
  const counts = deps.outbox.counts();
  return {
    state: row.state,
    sender: row.sender_jid
      ? { jid: row.sender_jid, display: row.sender_display ?? null, masked: row.sender_display ? maskPhone(row.sender_display) : null }
      : null,
    last_connected_at: row.last_connected_at ? new Date(row.last_connected_at).toISOString() : null,
    last_disconnected_at: row.last_disconnected_at ? new Date(row.last_disconnected_at).toISOString() : null,
    last_disconnect_code: row.last_disconnect_code,
    queue: {
      queued: counts.queued,
      sending: counts.sending,
      pending: counts.pending,
      retryable: counts.retryable,
      failed_terminal: counts.terminal,
      sent: counts.sent,
      delivered: counts.delivered,
      read: counts.read,
    },
    updated_at: new Date(row.updated_at).toISOString(),
  };
}

function readyState(deps: GatewayDeps): { ready: boolean; state: string; reason: string | null } {
  const row = deps.session.get();
  if (deps.isConnected()) {
    return { ready: true, state: row.state, reason: null };
  }
  // Report the *cause* accurately: an unpaired gateway is not the same failure
  // as a paired gateway whose socket dropped, and the caller acts differently.
  const state = deps.isRegistered() ? row.state : 'unpaired';
  const reason =
    state === 'unpaired'
      ? 'SESSION_UNPAIRED'
      : state === 'logged_out'
        ? 'SESSION_LOGGED_OUT'
        : 'NOT_CONNECTED';
  return { ready: false, state, reason };
}

/**
 * Builds the HTTP surface. Deliberately small: one write endpoint, two health
 * probes, two reads. No pairing/reset endpoint in v1 (spec).
 */
export function createGatewayServer(deps: GatewayDeps): Server {
  const logger = deps.logger;
  const maxBody = deps.maxBodyBytes ?? DEFAULT_MAX_BODY;

  const handleSend = async (req: IncomingMessage, res: ServerResponse): Promise<void> => {
    const keyIssue = validateIdempotencyKey(req.headers['idempotency-key'] as string | undefined);
    if (keyIssue) {
      sendJson(res, 422, { error: { code: keyIssue.code, issues: [keyIssue] } });
      return;
    }
    const idempotencyKey = req.headers['idempotency-key'] as string;

    const raw = await readBody(req, maxBody);
    let parsed: unknown;
    try {
      parsed = JSON.parse(raw);
    } catch {
      sendJson(res, 400, { error: { code: 'INVALID_JSON' } });
      return;
    }

    const validation = validateSendTextRequest(parsed);
    if (!validation.ok) {
      sendJson(res, 422, { error: { code: 'VALIDATION_FAILED', issues: validation.issues } });
      return;
    }

    const result = deps.outbox.enqueue({
      idempotencyKey,
      to: validation.value.to,
      text: validation.value.text,
      invoiceId: validation.value.invoiceId,
      event: validation.value.event,
      source: validation.value.source,
      maxAttempts: deps.maxAttempts,
    });

    if (result.outcome === 'conflict') {
      logger.warn('http.idempotency_conflict', { message_id: result.message.id });
      sendJson(res, 409, {
        error: {
          code: 'IDEMPOTENCY_CONFLICT',
          message: 'this Idempotency-Key was already used with a different payload',
        },
      });
      return;
    }

    const view: MessageView = toMessageView(result.message);
    if (result.outcome === 'duplicate') {
      sendJson(res, 200, { ...view, duplicate: true });
      return;
    }

    logger.info('http.message_queued', {
      message_id: result.message.id,
      invoice_id: result.message.invoice_id,
      event: result.message.event,
    });
    sendJson(res, 202, { ...view, duplicate: false });
  };

  const server = createServer((req, res) => {
    const url = new URL(req.url ?? '/', 'http://127.0.0.1');
    const path = url.pathname;
    const method = req.method ?? 'GET';

    void (async () => {
      try {
        // --- unauthenticated health probes (no secrets in the response) ---
        if (path === '/health/live' && method === 'GET') {
          try {
            deps.outbox.counts();
          } catch (error) {
            logger.error('http.live_db_failed', { error });
            sendJson(res, 503, { status: 'error', database: 'down' });
            return;
          }
          sendJson(res, 200, { status: 'ok', database: 'up' });
          return;
        }

        if (path === '/health/ready' && method === 'GET') {
          const state = readyState(deps);
          sendJson(res, state.ready ? 200 : 503, {
            status: state.ready ? 'ready' : 'not_ready',
            session_state: state.state,
            reason: state.reason,
          });
          return;
        }

        // --- everything below requires the internal key ---
        if (!verifyInternalKey(req.headers['x-internal-key'] as string | undefined, deps.internalKey)) {
          // Deliberately no hint about the expected value or its length.
          logger.warn('http.unauthorized', { path });
          sendJson(res, 401, { error: { code: 'UNAUTHORIZED' } });
          return;
        }

        if (path === '/v1/messages/text' && method === 'POST') {
          await handleSend(req, res);
          return;
        }

        if (path === '/v1/session/status' && method === 'GET') {
          sendJson(res, 200, sessionStatusPayload(deps));
          return;
        }

        const messageMatch = /^\/v1\/messages\/([A-Za-z0-9-]{1,64})$/.exec(path);
        if (messageMatch && method === 'GET') {
          const id = messageMatch[1] as string;
          const row = deps.outbox.getById(id);
          if (!row) {
            sendJson(res, 404, { error: { code: 'MESSAGE_NOT_FOUND' } });
            return;
          }
          sendJson(res, 200, toMessageView(row));
          return;
        }

        sendJson(res, 404, { error: { code: 'NOT_FOUND' } });
      } catch (error) {
        if (error instanceof HttpError) {
          sendJson(res, error.status, { error: { code: error.code } });
          return;
        }
        logger.error('http.unhandled_error', { error, path });
        sendJson(res, 500, { error: { code: 'INTERNAL_ERROR' } });
      }
    })();
  });

  // Slowloris guard: bound header and total request time.
  server.headersTimeout = 10_000;
  server.requestTimeout = 20_000;
  server.keepAliveTimeout = 5_000;

  return server;
}
