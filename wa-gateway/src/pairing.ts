import { randomBytes } from 'node:crypto';
import {
  S_WHATSAPP_NET,
  aesEncryptCTR,
  bytesToCrockford,
  derivePairingCodeKey,
  jidEncode,
} from 'baileys';
import type { AuthenticationCreds, BinaryNode, WABrowserDescription } from 'baileys';
import type { Logger } from './logger.ts';

/**
 * Pairing-code (companion_hello) request path.
 *
 * Why this module exists instead of calling `socket.requestPairingCode()`:
 *
 * baileys 7.0.0-rc14 implements `requestPairingCode` optimistically. It mints a
 * code locally, mutates `creds.me`, emits `creds.update` (which we persist) and
 * then puts the `link_code_companion_reg` IQ on the wire with `sendNode()` —
 * fire and forget. If WhatsApp answers with `<iq type='error'><error
 * code='400' text='bad-request'/></iq>` the caller still receives an 8-character
 * code that can never link, and the gateway has already stored an identity that
 * was never registered. That is exactly the production failure mode.
 *
 * Two further upstream details cause the 400:
 *  - `companion_platform_display` is built as `${browser[1]} (${browser[0]})`,
 *    so a custom label like `['CAPTSHOP Gateway', 'Chrome', '1.0.0']` sends
 *    `Chrome (CAPTSHOP Gateway)`, which WhatsApp's stricter companion_hello
 *    validator rejects. Only canonical `<browser> (<OS>)` values are accepted.
 *  - The request must not be sent before the server has started the pairing
 *    handshake (`pair-device` stanza, surfaced by baileys as the first
 *    `connection.update` carrying a `qr`).
 *
 * This module therefore re-implements only the request/acknowledgement step
 * using baileys' *public* API (`query`, `derivePairingCodeKey`,
 * `aesEncryptCTR`, `bytesToCrockford`, `jidEncode`) so that:
 *  1. the platform display/id are canonicalized,
 *  2. the IQ result is awaited and a server error rejects before any creds are
 *     stored or any code is shown,
 *  3. the transient `creds.pairingCode` is rolled back on failure.
 *
 * Everything else (companion_finish, pair-success, creds persistence) stays in
 * upstream baileys; we do not duplicate it.
 */

export type PairingPlatform = {
  /** `companion_platform_id` — proto.DeviceProps.PlatformType value as string. */
  id: string;
  /** `companion_platform_display` — must be canonical `<browser> (<OS>)`. */
  display: string;
};

/** Canonical platform accepted by WhatsApp's companion_hello validator. */
const DEFAULT_PAIRING_PLATFORM: PairingPlatform = { id: '1', display: 'Chrome (Mac OS)' };

/**
 * Mirrors `proto.DeviceProps.PlatformType` for the browser names Baileys knows
 * about. Anything else (a custom label, "Desktop", an OS-derived name) falls
 * back to CHROME so the value stays inside the accepted set.
 */
const BROWSER_TO_PAIRING_PLATFORM: Record<string, PairingPlatform> = {
  Chrome: { id: '1', display: 'Chrome' },
  Firefox: { id: '2', display: 'Firefox' },
  IE: { id: '3', display: 'IE' },
  Opera: { id: '4', display: 'Opera' },
  Safari: { id: '5', display: 'Safari' },
  Edge: { id: '6', display: 'Edge' },
};

/** OS strings WhatsApp accepts inside the display value. */
const CANONICAL_OS = new Set(['Mac OS', 'Windows', 'Ubuntu']);

const DEFAULT_OS_DISPLAY = 'Mac OS';

/**
 * Normalizes the socket's `browser` tuple into the canonical pairing platform.
 *
 * The tuple stays untouched for registration (`clientPayload` still advertises
 * the CAPTSHOP label); only the pairing-code stanza is normalized.
 */
export function canonicalPairingPlatform(browser: WABrowserDescription): PairingPlatform {
  const [os, browserName] = browser;
  const platform = BROWSER_TO_PAIRING_PLATFORM[browserName] ?? BROWSER_TO_PAIRING_PLATFORM.Chrome;
  const resolved = platform ?? DEFAULT_PAIRING_PLATFORM;
  const osDisplay = CANONICAL_OS.has(os) ? os : DEFAULT_OS_DISPLAY;
  return { id: resolved.id, display: `${resolved.display} (${osDisplay})` };
}

/** Error codes surfaced to the CLI so it can fail closed with a stable reason. */
export type PairingErrorCode =
  | 'PAIRING_NOT_READY'
  | 'PAIRING_INTERRUPTED'
  | 'PAIRING_IN_PROGRESS'
  | 'PAIRING_TIMEOUT'
  | 'NO_PAIRING_SOCKET';

export function pairingError(code: PairingErrorCode, message: string): Error {
  const error = new Error(message);
  (error as Error & { code?: string }).code = code;
  return error;
}

/**
 * True when a failed attempt may be retried safely.
 *
 * Only a connection-level interruption qualifies: the socket was replaced or
 * closed before the handshake started, so nothing reached WhatsApp and no code
 * was ever minted. A server rejection (`bad-request`, `PAIRING_TIMEOUT`) is
 * final — retrying it would just burn WhatsApp's pairing attempts.
 */
export function isRetryablePairingError(error: unknown): boolean {
  return (error as { code?: unknown } | null)?.code === 'PAIRING_INTERRUPTED';
}

/** The slice of a Baileys socket this module needs (narrowed for tests). */
export type PairingSocket = {
  query(node: BinaryNode, timeoutMs?: number): Promise<unknown>;
  generateMessageTag?(): string;
};

export type PairingRequestOptions = {
  socket: PairingSocket;
  /** The live creds object shared with Baileys (mutated in place). */
  creds: AuthenticationCreds;
  /** Socket browser tuple; normalized for the pairing stanza only. */
  browser: WABrowserDescription;
  /** Digits-only phone with country code, e.g. 628123456789. */
  phoneE164: string;
  saveCreds: () => Promise<void>;
  logger: Logger;
  /** IQ response timeout. Baileys returns `undefined` when it elapses. */
  queryTimeoutMs?: number;
};

const DEFAULT_QUERY_TIMEOUT_MS = 60_000;

/**
 * Builds the `companion_hello` IQ with a wrapped ephemeral key.
 *
 * Identical in shape to upstream Baileys, except the platform id/display come
 * from {@link canonicalPairingPlatform}.
 */
export async function buildCompanionHelloNode(options: {
  creds: AuthenticationCreds;
  jid: string;
  platform: PairingPlatform;
  pairingCode: string;
  id?: string;
}): Promise<BinaryNode> {
  const salt = randomBytes(32);
  const iv = randomBytes(16);
  const key = await derivePairingCodeKey(options.pairingCode, salt);
  const ciphered = aesEncryptCTR(options.creds.pairingEphemeralKeyPair.public, key, iv);
  const wrapped = Buffer.concat([salt, iv, ciphered]);

  return {
    tag: 'iq',
    attrs: {
      to: S_WHATSAPP_NET,
      type: 'set',
      xmlns: 'md',
      ...(options.id ? { id: options.id } : {}),
    },
    content: [
      {
        tag: 'link_code_companion_reg',
        attrs: {
          jid: options.jid,
          stage: 'companion_hello',
          should_show_push_notification: 'true',
        },
        content: [
          {
            tag: 'link_code_pairing_wrapped_companion_ephemeral_pub',
            attrs: {},
            content: wrapped,
          },
          { tag: 'companion_server_auth_key_pub', attrs: {}, content: options.creds.noiseKey.public },
          { tag: 'companion_platform_id', attrs: {}, content: options.platform.id },
          { tag: 'companion_platform_display', attrs: {}, content: options.platform.display },
          { tag: 'link_code_pairing_nonce', attrs: {}, content: '0' },
        ],
      },
    ],
  };
}

/**
 * Requests a pairing code and returns it **only** after WhatsApp acknowledged
 * the `companion_hello` IQ.
 *
 * On any failure (server error node, timeout, transport error) the transient
 * `creds.pairingCode` is rolled back and `creds.me` is left untouched, so a
 * failed attempt never leaves a half-registered identity behind. The returned
 * code is the only place it exists — it is never logged or persisted by this
 * module beyond the creds field Baileys needs for `companion_finish`.
 */
export async function requestPairingCodeAwaitingResult(
  options: PairingRequestOptions,
): Promise<string> {
  const { socket, creds, logger } = options;
  const pairingCode = bytesToCrockford(randomBytes(5));
  const jid = jidEncode(options.phoneE164, 's.whatsapp.net');
  const platform = canonicalPairingPlatform(options.browser);

  const previousPairingCode = creds.pairingCode;
  const previousMe = creds.me;

  // Baileys reads creds.pairingCode when it handles companion_finish, so it has
  // to be set before the IQ goes out — but it is rolled back on failure and it
  // is never written to the auth store before a successful result.
  creds.pairingCode = pairingCode;

  try {
    const node = await buildCompanionHelloNode({
      creds,
      jid,
      platform,
      pairingCode,
      ...(socket.generateMessageTag ? { id: socket.generateMessageTag() } : {}),
    });

    const result = await socket.query(node, options.queryTimeoutMs ?? DEFAULT_QUERY_TIMEOUT_MS);
    if (result === undefined || result === null) {
      // baileys' query() resolves `undefined` on timeout instead of throwing.
      throw pairingError(
        'PAIRING_TIMEOUT',
        'WhatsApp did not answer the pairing request in time',
      );
    }
  } catch (error) {
    if (creds.pairingCode === pairingCode) creds.pairingCode = previousPairingCode;
    creds.me = previousMe;
    logger.warn('pairing.request_failed', { error });
    throw error;
  }

  // Only now is the identity real: WhatsApp accepted companion_hello.
  creds.me = { id: jid, name: '~' };
  await options.saveCreds().catch((error: unknown) => {
    logger.error('auth.save_creds_failed', { error });
  });

  return pairingCode;
}

export type PairingSessionOptions = {
  /** Opens the socket (does not wait for the pairing handshake). */
  connect: () => Promise<void>;
  /** Requests the code; must reject when WhatsApp rejects the request. */
  requestPairingCode: () => Promise<string>;
  /** Closes the socket and releases resources. */
  stop: () => Promise<void>;
  timeoutSeconds: number;
  stdout: (text: string) => void;
  stderr: (text: string) => void;
};

export type PairingSession = {
  /** Wire these into `WhatsAppConnection`'s events. */
  events: { connected: () => void; loggedOut: () => void };
  /** Runs the CLI flow and resolves with the process exit code. */
  run: () => Promise<number>;
};

/**
 * Operator-facing pairing flow, extracted from the CLI so the "no code is shown
 * unless WhatsApp accepted the request" contract can be tested directly.
 *
 * Exit codes: 0 paired, 1 not paired (rejected, logged out, timed out).
 */
export function createPairingSession(options: PairingSessionOptions): PairingSession {
  let settle: ((outcome: 'connected' | 'loggedOut') => void) | null = null;
  const terminal = new Promise<'connected' | 'loggedOut'>((resolve) => {
    settle = resolve;
  });

  const events = {
    connected: (): void => {
      options.stdout('\npaired: session is connected and credentials are stored.\n');
      settle?.('connected');
    },
    loggedOut: (): void => {
      options.stderr('\npairing failed: WhatsApp rejected the session (logged out).\n');
      settle?.('loggedOut');
    },
  };

  const run = async (): Promise<number> => {
    const timeoutSeconds = Math.max(1, options.timeoutSeconds);
    let timer: NodeJS.Timeout | undefined;
    // Deliberately *not* unref'd: the operator is waiting on this process, and an
    // unref'd timer would let Node exit before the timeout could ever fire.
    const timedOut = new Promise<'timeout'>((resolve) => {
      timer = setTimeout(() => resolve('timeout'), timeoutSeconds * 1000);
    });

    try {
      options.stdout('requesting pairing code...\n');
      await options.connect();

      // The request itself is bounded by the same deadline: a pairing-ready
      // wait plus a stalled IQ must not outlive `--timeout-seconds`.
      const first = await Promise.race([
        options
          .requestPairingCode()
          .then((value) => ({ ok: true as const, value }))
          .catch((error: unknown) => ({ ok: false as const, error })),
        timedOut,
      ]);

      if (first === 'timeout') {
        options.stderr(`\npairing timed out after ${timeoutSeconds}s; no session was registered.\n`);
        return 1;
      }

      if (!first.ok) {
        const message = first.error instanceof Error ? first.error.message : 'unknown error';
        options.stderr(`error: could not obtain a pairing code (${message})\n`);
        return 1;
      }

      const code = first.value;

      // The only place the code is ever printed. Not logged, not persisted.
      options.stdout(
        `\n  pairing code: ${code}\n\n` +
          '  Enter it in WhatsApp > Linked devices > Link with phone number.\n' +
          '  The code is shown once and is not stored anywhere.\n\n',
      );

      const outcome = await Promise.race([terminal, timedOut]);
      if (outcome === 'timeout') {
        options.stderr(`\npairing timed out after ${timeoutSeconds}s; no session was registered.\n`);
        return 1;
      }
      return outcome === 'connected' ? 0 : 1;
    } finally {
      if (timer) clearTimeout(timer);
      await options.stop().catch(() => {});
    }
  };

  return { events, run };
}
