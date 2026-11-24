import { normalizePhone } from './phone.ts';

export type ValidationIssue = {
  field: string;
  code: string;
  message: string;
};

export type SendTextRequest = {
  to: string;
  jid: string;
  text: string;
  invoiceId: string | null;
  event: string | null;
  source: string | null;
};

export type ValidationResult =
  | { ok: true; value: SendTextRequest }
  | { ok: false; issues: ValidationIssue[] };

const MAX_TEXT_LENGTH = 4096;
const MAX_IDEMPOTENCY_KEY_LENGTH = 255;
/** Mirrors the Laravel contract: transaction:{invoice_id}:event:{created|paid|completed}. */
const IDEMPOTENCY_KEY_PATTERN = /^transaction:[A-Za-z0-9._:-]{1,120}:event:(created|paid|completed)$/;
const ALLOWED_EVENTS = new Set(['created', 'paid', 'completed']);

/**
 * Validates the POST body. Returns 422-able issues rather than throwing so the
 * handler can report every problem at once without echoing input back.
 */
export function validateSendTextRequest(body: unknown): ValidationResult {
  const issues: ValidationIssue[] = [];
  const input = (body ?? {}) as Record<string, unknown>;

  if (typeof input !== 'object' || Array.isArray(input)) {
    return {
      ok: false,
      issues: [{ field: 'body', code: 'INVALID_BODY', message: 'body must be a JSON object' }],
    };
  }

  const phone = normalizePhone(input.to);
  if (!phone.ok) {
    issues.push({ field: 'to', code: phone.code, message: phone.reason });
  }

  let text = '';
  if (typeof input.text !== 'string') {
    issues.push({ field: 'text', code: 'INVALID_TEXT', message: 'text must be a string' });
  } else if (input.text.trim().length === 0) {
    issues.push({ field: 'text', code: 'EMPTY_TEXT', message: 'text must not be empty' });
  } else if (input.text.length > MAX_TEXT_LENGTH) {
    issues.push({
      field: 'text',
      code: 'TEXT_TOO_LONG',
      message: `text must be at most ${MAX_TEXT_LENGTH} characters`,
    });
  } else {
    text = input.text;
  }

  let invoiceId: string | null = null;
  let event: string | null = null;
  let source: string | null = null;

  const metadata = input.metadata;
  if (metadata !== undefined && metadata !== null) {
    if (typeof metadata !== 'object' || Array.isArray(metadata)) {
      issues.push({ field: 'metadata', code: 'INVALID_METADATA', message: 'metadata must be an object' });
    } else {
      const meta = metadata as Record<string, unknown>;

      if (meta.invoice_id !== undefined && meta.invoice_id !== null) {
        if (typeof meta.invoice_id !== 'string' || meta.invoice_id.trim().length === 0) {
          issues.push({
            field: 'metadata.invoice_id',
            code: 'INVALID_INVOICE_ID',
            message: 'invoice_id must be a non-empty string',
          });
        } else if (meta.invoice_id.length > 128) {
          issues.push({
            field: 'metadata.invoice_id',
            code: 'INVALID_INVOICE_ID',
            message: 'invoice_id is too long',
          });
        } else {
          invoiceId = meta.invoice_id;
        }
      }

      if (meta.event !== undefined && meta.event !== null) {
        if (typeof meta.event !== 'string' || !ALLOWED_EVENTS.has(meta.event)) {
          issues.push({
            field: 'metadata.event',
            code: 'INVALID_EVENT',
            message: 'event must be one of created, paid, completed',
          });
        } else {
          event = meta.event;
        }
      }

      if (meta.source !== undefined && meta.source !== null) {
        if (typeof meta.source !== 'string' || meta.source.length > 64) {
          issues.push({
            field: 'metadata.source',
            code: 'INVALID_SOURCE',
            message: 'source must be a string of at most 64 characters',
          });
        } else {
          source = meta.source;
        }
      }
    }
  }

  if (issues.length > 0 || !phone.ok) {
    return { ok: false, issues };
  }

  return {
    ok: true,
    value: { to: phone.e164, jid: phone.jid, text, invoiceId, event, source },
  };
}

/** Validates the Idempotency-Key header shape. */
export function validateIdempotencyKey(raw: string | undefined): ValidationIssue | null {
  if (raw === undefined || raw.trim().length === 0) {
    return { field: 'Idempotency-Key', code: 'MISSING_IDEMPOTENCY_KEY', message: 'Idempotency-Key header is required' };
  }
  if (raw.length > MAX_IDEMPOTENCY_KEY_LENGTH) {
    return { field: 'Idempotency-Key', code: 'INVALID_IDEMPOTENCY_KEY', message: 'Idempotency-Key is too long' };
  }
  if (!IDEMPOTENCY_KEY_PATTERN.test(raw)) {
    return {
      field: 'Idempotency-Key',
      code: 'INVALID_IDEMPOTENCY_KEY',
      message: 'Idempotency-Key must look like transaction:{invoice_id}:event:{created|paid|completed}',
    };
  }
  return null;
}
