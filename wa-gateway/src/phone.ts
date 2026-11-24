/**
 * Indonesian destination-number normalization.
 *
 * The gateway is the last line of defence before Baileys turns a JID into a
 * Signal session, so it rejects anything that is not a plausible WhatsApp
 * number rather than letting Baileys fail later (which would look like a
 * transient error and burn retries).
 */

export type PhoneResult =
  | { ok: true; e164: string; jid: string }
  | { ok: false; code: 'INVALID_PHONE' | 'INVALID_PHONE_COUNTRY'; reason: string };

/** Indonesian mobile numbers: 628xx — the only shape WhatsApp delivers to. */
const ID_MOBILE = /^628[1-9][0-9]{6,11}$/;

export function normalizePhone(raw: unknown): PhoneResult {
  if (typeof raw !== 'string') {
    return { ok: false, code: 'INVALID_PHONE', reason: 'phone must be a string' };
  }

  const trimmed = raw.trim();
  if (trimmed.length === 0) {
    return { ok: false, code: 'INVALID_PHONE', reason: 'phone is empty' };
  }

  // Only digits, spaces, dashes, dots and a single leading '+'.
  if (!/^\+?[0-9\s\-.()]+$/.test(trimmed)) {
    return { ok: false, code: 'INVALID_PHONE', reason: 'phone contains non-numeric characters' };
  }

  const digits = trimmed.replace(/[^0-9]/g, '');
  if (digits.length === 0) {
    return { ok: false, code: 'INVALID_PHONE', reason: 'phone has no digits' };
  }

  let normalized: string;
  if (digits.startsWith('62')) {
    normalized = digits;
  } else if (digits.startsWith('0')) {
    normalized = `62${digits.slice(1)}`;
  } else if (digits.startsWith('8')) {
    normalized = `62${digits}`;
  } else {
    return {
      ok: false,
      code: 'INVALID_PHONE_COUNTRY',
      reason: 'phone must be an Indonesian number (62.../08.../8...)',
    };
  }

  if (normalized.length < 10 || normalized.length > 15) {
    return { ok: false, code: 'INVALID_PHONE', reason: 'phone length out of range' };
  }

  if (!ID_MOBILE.test(normalized)) {
    return { ok: false, code: 'INVALID_PHONE', reason: 'phone is not a valid Indonesian mobile number' };
  }

  // Reject repeated-digit placeholders such as 620000000000.
  if (/^62(\d)\1+$/.test(normalized)) {
    return { ok: false, code: 'INVALID_PHONE', reason: 'phone looks like a placeholder' };
  }

  return { ok: true, e164: normalized, jid: `${normalized}@s.whatsapp.net` };
}

/** Safe display form for status/admin surfaces: 62812****789. */
export function maskPhone(e164: string): string {
  if (e164.length <= 7) return `${e164.slice(0, 2)}***`;
  return `${e164.slice(0, 5)}****${e164.slice(-3)}`;
}
