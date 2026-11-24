import { EventEmitter } from 'node:events';
import makeWASocket, { DisconnectReason, fetchLatestBaileysVersion } from 'baileys';
import type { AuthenticationState, BinaryNode, WABrowserDescription, WAVersion } from 'baileys';
import type { Logger } from './logger.ts';
import type { SessionStore, SessionState } from './session-store.ts';
import { pairingError, requestPairingCodeAwaitingResult } from './pairing.ts';

/**
 * Minimal surface of a Baileys socket that the gateway depends on. Narrowing
 * it here lets tests drive connect/disconnect/logout/send paths with a fake
 * socket and no network, while production passes the real makeWASocket result.
 */
export type SendResult = { id: string | null };

export type SocketLike = {
  ev: {
    on(event: 'connection.update', handler: (update: ConnectionUpdate) => void): void;
    on(event: 'creds.update', handler: () => void): void;
    on(event: 'message-receipt.update', handler: (updates: ReceiptUpdate[]) => void): void;
  };
  sendMessage(jid: string, content: { text: string }): Promise<{ key?: { id?: string | null } | null } | undefined>;
  /**
   * Baileys' IQ helper: sends a node and awaits the matching `TAG:` response,
   * throwing on an `type='error'` reply. Required for pairing, where we must
   * see the server's verdict before showing a code.
   */
  query?(node: BinaryNode, timeoutMs?: number): Promise<unknown>;
  generateMessageTag?(): string;
  end?(error?: Error): void;
};

export type ConnectionUpdate = {
  connection?: 'connecting' | 'open' | 'close';
  lastDisconnect?: { error?: unknown; date?: Date };
  qr?: string;
  isNewLogin?: boolean;
};

export type ReceiptUpdate = {
  key?: { id?: string | null };
  receipt?: { readTimestamp?: number | Long | null; receiptTimestamp?: number | Long | null };
};

type Long = { toNumber(): number };

export type SocketFactory = (auth: AuthenticationState, logger: Logger) => Promise<SocketLike>;

export type ConnectionEvents = {
  /** A send path became usable (socket open + registered). */
  connected: (senderJid: string | null) => void;
  /** A send path became unusable. */
  disconnected: (state: SessionState, reason: string | null) => void;
  /** Session invalidated; do not retry sends until re-paired. */
  loggedOut: (reason: string | null) => void;
  /** Baileys reported a receipt; upgrade outbox rows. */
  receipt: (baileysMessageId: string, status: 'delivered' | 'read') => void;
};

export type ConnectionOptions = {
  auth: AuthenticationState;
  saveCreds: () => Promise<void>;
  session: SessionStore;
  logger: Logger;
  events: ConnectionEvents;
  /** Injected in tests; defaults to real Baileys. */
  socketFactory?: SocketFactory;
  /**
   * Baileys browser tuple. Kept as the CAPTSHOP label for registration; only
   * the pairing-code stanza is canonicalized (see `canonicalPairingPlatform`).
   */
  browser?: WABrowserDescription;
  /** Bounded exponential reconnect backoff. */
  reconnectBaseMs: number;
  reconnectMaxMs: number;
  /** Deterministic jitter for tests. */
  random?: () => number;
  /** Reconnect timer override (tests use a fake clock). */
  schedule?: (fn: () => void, ms: number) => { cancel: () => void };
};

/** Default browser tuple; also the value normalized away for pairing codes. */
export const DEFAULT_BROWSER: WABrowserDescription = ['CAPTSHOP Gateway', 'Chrome', '1.0.0'];

/** Someone blocked on the pairing handshake starting. */
type PairingWaiter = {
  resolve: () => void;
  reject: (reason: string) => void;
};

const defaultSchedule = (fn: () => void, ms: number): { cancel: () => void } => {
  const timer = setTimeout(fn, ms);
  // Never hold the event loop open purely for a reconnect timer.
  timer.unref?.();
  return { cancel: () => clearTimeout(timer) };
};

function toNumber(value: unknown): number | null {
  if (typeof value === 'number' && Number.isFinite(value)) return value;
  if (value && typeof (value as Long).toNumber === 'function') return (value as Long).toNumber();
  return null;
}

/** Extracts the DisconnectReason status code from whatever Baileys threw. */
export function disconnectStatusCode(error: unknown): number | null {
  if (!error || typeof error !== 'object') return null;
  const candidate = error as { output?: { statusCode?: unknown }; statusCode?: unknown };
  const fromOutput = candidate.output?.statusCode;
  if (typeof fromOutput === 'number') return fromOutput;
  if (typeof candidate.statusCode === 'number') return candidate.statusCode;
  return null;
}

/**
 * Single-socket Baileys session manager.
 *
 * Responsibilities:
 *  - own exactly one socket at a time (the spec's "single gateway instance owns
 *    the session" rule),
 *  - persist creds on every `creds.update`,
 *  - translate Baileys' connection lifecycle into the persisted session state,
 *  - reconnect with bounded exponential backoff, except on `loggedOut` where
 *    the spec requires retries to stop and the pairing to be re-established.
 */
export class WhatsAppConnection {
  private socket: SocketLike | null = null;
  private reconnectAttempt = 0;
  private reconnectTimer: { cancel: () => void } | null = null;
  private stopped = false;
  private connected = false;
  private version: WAVersion | null = null;
  private readonly events: ConnectionEvents;
  private readonly logger: Logger;
  /** Browser tuple advertised to WhatsApp (see `DEFAULT_BROWSER`). */
  private readonly browser: WABrowserDescription;

  /**
   * True once the server has started the pairing handshake. Baileys surfaces
   * that as the first `connection.update` carrying a `qr` payload (the
   * `pair-device` stanza). Requesting a code earlier is rejected by WhatsApp,
   * so `requestPairingCode()` waits for this instead of firing blind.
   */
  private pairingReady = false;
  private pairingWaiters: PairingWaiter[] = [];
  /** Rejects a second concurrent pairing request (upstream PR #2559 does the same). */
  private pairingRequestInFlight = false;

  private readonly options: ConnectionOptions;

  constructor(options: ConnectionOptions) {
    this.options = options;
    this.events = options.events;
    this.logger = options.logger;
    this.browser = options.browser ?? DEFAULT_BROWSER;
  }

  isConnected(): boolean {
    return this.connected && this.socket !== null;
  }

  /** True once the pairing handshake has started on the current socket. */
  isPairingReady(): boolean {
    return this.pairingReady;
  }

  /** Sends one text message. Throws if there is no usable socket. */
  async sendText(jid: string, text: string): Promise<SendResult> {
    const socket = this.socket;
    if (!socket || !this.connected) {
      const error = new Error('gateway is not connected to WhatsApp');
      (error as Error & { code?: string }).code = 'NOT_CONNECTED';
      throw error;
    }
    const result = await socket.sendMessage(jid, { text });
    const id = result?.key?.id ?? null;
    return { id };
  }

  /**
   * Requests a pairing code and resolves only once WhatsApp acknowledged the
   * `companion_hello` IQ.
   *
   * The code is returned to the caller (CLI) and is never logged. Unlike
   * upstream `socket.requestPairingCode()` — which mints the code locally, emits
   * `creds.update` and returns before the server has answered — this waits for
   * the IQ result and rejects on a server error, so the caller never sees a code
   * that cannot link.
   */
  async requestPairingCode(phoneE164: string, options: { readyTimeoutMs?: number } = {}): Promise<string> {
    const socket = this.socket;
    if (!socket?.query) {
      throw pairingError('NO_PAIRING_SOCKET', 'socket does not support pairing IQ queries');
    }

    if (this.pairingRequestInFlight) {
      throw pairingError('PAIRING_IN_PROGRESS', 'a pairing request is already in progress');
    }
    this.pairingRequestInFlight = true;
    try {
      return await this.requestPairingCodeInner(socket, phoneE164, options);
    } finally {
      this.pairingRequestInFlight = false;
    }
  }

  private async requestPairingCodeInner(
    socket: SocketLike,
    phoneE164: string,
    options: { readyTimeoutMs?: number },
  ): Promise<string> {
    await this.waitForPairingReady(options.readyTimeoutMs ?? 30_000);

    const current = this.socket;
    if (current !== socket) {
      throw pairingError('PAIRING_INTERRUPTED', 'socket was replaced while waiting to pair');
    }

    return requestPairingCodeAwaitingResult({
      socket: {
        query: (node: BinaryNode, timeoutMs?: number) => socket.query!(node, timeoutMs),
        ...(socket.generateMessageTag ? { generateMessageTag: () => socket.generateMessageTag!() } : {}),
      },
      creds: this.options.auth.creds,
      browser: this.browser,
      phoneE164,
      saveCreds: this.options.saveCreds,
      logger: this.logger,
    });
  }

  /** Resolves once the pairing handshake has started, or rejects on timeout. */
  private waitForPairingReady(timeoutMs: number): Promise<void> {
    if (this.pairingReady) return Promise.resolve();
    return new Promise<void>((resolve, reject) => {
      let settled = false;
      const waiter: PairingWaiter = {
        resolve: () => {
          if (settled) return;
          settled = true;
          clearTimeout(timer);
          resolve();
        },
        reject: (reason: string) => {
          if (settled) return;
          settled = true;
          clearTimeout(timer);
          reject(pairingError('PAIRING_INTERRUPTED', reason));
        },
      };
      const timer = setTimeout(() => {
        if (settled) return;
        settled = true;
        this.pairingWaiters = this.pairingWaiters.filter((entry) => entry !== waiter);
        reject(
          pairingError(
            'PAIRING_INTERRUPTED',
            `WhatsApp did not start the pairing handshake within ${timeoutMs}ms`,
          ),
        );
      }, timeoutMs);
      timer.unref?.();
      this.pairingWaiters.push(waiter);
    });
  }

  private markPairingReady(): void {
    if (this.pairingReady) return;
    this.pairingReady = true;
    const waiters = this.pairingWaiters;
    this.pairingWaiters = [];
    for (const waiter of waiters) waiter.resolve();
  }

  /** Fails everyone blocked on the handshake so a disconnect never hangs the CLI. */
  private rejectPairingWaiters(reason: string): void {
    const waiters = this.pairingWaiters;
    this.pairingWaiters = [];
    for (const waiter of waiters) waiter.reject(reason);
  }

  async connect(): Promise<void> {
    if (this.stopped) return;
    if (this.socket) return;

    this.options.session.setState('connecting');

    let factory: SocketFactory;
    if (this.options.socketFactory) {
      factory = this.options.socketFactory;
    } else {
      factory = async (auth, logger) => {
        if (!this.version) {
          try {
            const fetched = await fetchLatestBaileysVersion();
            this.version = fetched.version;
          } catch {
            this.logger.warn('baileys.version_fetch_failed');
          }
        }
        return makeWASocket({
          auth,
          logger: toBaileysLogger(logger),
          ...(this.version ? { version: this.version } : {}),
          markOnlineOnConnect: false,
          // Keep initial history sync ENABLED. Baileys warns that disabling it
          // with shouldSyncHistoryMessage prevents it from receiving initial LID
          // mappings, which destabilises the session. syncFullHistory stays off
          // so we do not download the whole backlog — only the initial payload
          // needed for mappings.
          syncFullHistory: false,
          emitOwnEvents: false,
          browser: this.browser,
          defaultQueryTimeoutMs: 60_000,
          connectTimeoutMs: 60_000,
          keepAliveIntervalMs: 25_000,
          retryRequestDelayMs: 250,
          maxMsgRetryCount: 3,
        }) as unknown as SocketLike;
      };
    }

    let socket: SocketLike;
    try {
      socket = await factory(this.options.auth, this.logger);
    } catch (error) {
      this.logger.error('baileys.socket_create_failed', { error });
      this.scheduleReconnect();
      return;
    }

    this.socket = socket;

    socket.ev.on('connection.update', (update) => {
      // Ignore events from a socket that is no longer current: a torn-down
      // socket can emit a late 'open'/'close' after we already replaced it, and
      // honouring it would resurrect stale state or cancel a pending reconnect.
      if (this.socket !== socket) {
        this.logger.debug('baileys.stale_socket_event_ignored', { connection: update.connection ?? null });
        return;
      }
      void this.handleConnectionUpdate(update);
    });

    socket.ev.on('creds.update', () => {
      if (this.socket !== socket) return;
      // Durability first: if the write fails the process must not continue
      // with unpersisted keys.
      this.options
        .saveCreds()
        .catch((error: unknown) => this.logger.error('auth.save_creds_failed', { error }));
    });

    socket.ev.on('message-receipt.update', (updates) => {
      if (this.socket !== socket) return;
      this.handleReceipts(updates);
    });
  }

  private async handleConnectionUpdate(update: ConnectionUpdate): Promise<void> {
    // The `pair-device` stanza reaches us as a `connection.update` carrying a
    // `qr` payload; that is the moment WhatsApp is willing to accept a
    // companion_hello pairing request.
    if (typeof update.qr === 'string' && update.qr.length > 0) {
      this.markPairingReady();
    }

    if (update.connection === 'open') {
      this.connected = true;
      this.reconnectAttempt = 0;
      if (this.reconnectTimer) {
        this.reconnectTimer.cancel();
        this.reconnectTimer = null;
      }
      const senderJid = senderJidFromAuth(this.options.auth);
      this.options.session.markConnected(senderJid, senderJid ? senderJid.split('@')[0] ?? null : null);
      // Persist immediately: pairing completes here and must survive a crash.
      await this.options.saveCreds().catch((error: unknown) =>
        this.logger.error('auth.save_creds_failed', { error }),
      );
      this.logger.info('baileys.connected', { sender_jid: senderJid });
      this.events.connected(senderJid);
      return;
    }

    if (update.connection === 'close') {
      this.connected = false;
      const code = disconnectStatusCode(update.lastDisconnect?.error);
      const wasSocket = this.socket;
      this.socket = null;
      // A new socket re-runs the handshake, so the gate must be re-armed and
      // anyone still waiting on the old one must fail rather than hang.
      this.pairingReady = false;
      this.rejectPairingWaiters('the socket closed before pairing started');
      try {
        wasSocket?.end?.(undefined);
      } catch {
        // Socket is already gone; nothing to clean up.
      }

      if (code === DisconnectReason.loggedOut) {
        // Spec: stop send retry, mark pairing-required/degraded, do NOT delete auth.
        this.options.session.clearSender('logged_out', code);
        this.logger.warn('baileys.logged_out', { disconnect_code: code });
        this.events.loggedOut('logged_out');
        return;
      }

      const state: SessionState = this.options.auth.creds?.registered ? 'degraded' : 'unpaired';
      this.options.session.markDisconnected(state, code);
      this.logger.warn('baileys.disconnected', { disconnect_code: code });
      this.events.disconnected(state, code === null ? null : String(code));
      this.scheduleReconnect();
    }
  }

  private handleReceipts(updates: ReceiptUpdate[]): void {
    for (const update of updates) {
      const id = update.key?.id ?? null;
      if (!id) continue;
      const read = toNumber(update.receipt?.readTimestamp);
      const delivered = toNumber(update.receipt?.receiptTimestamp);
      if (read !== null && read > 0) {
        this.events.receipt(id, 'read');
      } else if (delivered !== null && delivered > 0) {
        this.events.receipt(id, 'delivered');
      }
    }
  }

  private scheduleReconnect(): void {
    if (this.stopped || this.reconnectTimer) return;
    this.reconnectAttempt += 1;
    const random = this.options.random ?? Math.random;
    const raw = this.options.reconnectBaseMs * 2 ** Math.min(this.reconnectAttempt - 1, 20);
    const capped = Math.min(raw, this.options.reconnectMaxMs);
    const delay = Math.round(capped / 2 + (random() * capped) / 2);

    this.logger.info('baileys.reconnect_scheduled', {
      attempt: this.reconnectAttempt,
      delay_ms: delay,
    });

    const schedule = this.options.schedule ?? defaultSchedule;
    this.reconnectTimer = schedule(() => {
      this.reconnectTimer = null;
      void this.connect();
    }, delay);
  }

  /** Graceful shutdown: close the socket without scheduling a reconnect. */
  async stop(): Promise<void> {
    this.stopped = true;
    this.connected = false;
    if (this.reconnectTimer) {
      this.reconnectTimer.cancel();
      this.reconnectTimer = null;
    }
    const socket = this.socket;
    this.socket = null;
    if (socket) {
      try {
        socket.end?.(undefined);
      } catch {
        // ignore
      }
    }
  }
}

export function senderJidFromAuth(auth: AuthenticationState): string | null {
  const id = auth.creds?.me?.id;
  return typeof id === 'string' && id.length > 0 ? id : null;
}

/**
 * Adapts our sanitized logger to the ILogger shape Baileys expects.
 *
 * Baileys logs message metadata and connection internals at debug/trace. We
 * forward only warnings and errors, and always through the redacting logger, so
 * `msg` payloads never reach a sink.
 */
export function toBaileysLogger(logger: Logger): {
  level: string;
  child(obj: Record<string, unknown>): ReturnType<typeof toBaileysLogger>;
  trace(): void;
  debug(): void;
  info(): void;
  warn(obj: unknown, msg?: string): void;
  error(obj: unknown, msg?: string): void;
} {
  const adapter = (childLogger: Logger) => ({
    level: 'warn',
    child: (obj: Record<string, unknown>) => adapter(childLogger.child(obj)),
    trace: () => {},
    debug: () => {},
    info: () => {},
    warn: (obj: unknown, msg?: string) => childLogger.warn(msg ?? 'baileys.warn', { detail: obj }),
    error: (obj: unknown, msg?: string) => childLogger.error(msg ?? 'baileys.error', { detail: obj }),
  });
  return adapter(logger);
}

/** Small helper used by tests to build a fake Baileys emitter. */
export function createFakeSocket(): SocketLike & {
  emitConnection(update: ConnectionUpdate): void;
  emitReceipts(updates: ReceiptUpdate[]): void;
  emitCreds(): void;
  sent: Array<{ jid: string; text: string }>;
  /** Every IQ passed to `query()`, in order. */
  queriedIqs: BinaryNode[];
  failNextSend(error: Error): void;
  /** Makes the next `query()` reject, as an `iq type='error'` reply does. */
  failNextQuery(error: Error): void;
  ended: boolean;
} {
  const emitter = new EventEmitter();
  const sent: Array<{ jid: string; text: string }> = [];
  const queriedIqs: BinaryNode[] = [];
  let nextError: Error | null = null;
  let nextQueryError: Error | null = null;
  let tagCounter = 0;
  let ended = false;

  const socket = {
    ev: {
      // Handlers are stored verbatim; the cast at return reconciles the
      // per-event signatures with this single implementation.
      on(event: string, handler: (payload: never) => void) {
        emitter.on(event, handler as (payload: unknown) => void);
      },
    },
    async sendMessage(jid: string, content: { text: string }) {
      if (nextError) {
        const error = nextError;
        nextError = null;
        throw error;
      }
      sent.push({ jid, text: content.text });
      return { key: { id: `BAE-${sent.length}` } };
    },
    /**
     * Mirrors Baileys: resolves with the server's result node, or rejects when
     * the server answered with an `iq type='error'` (Baileys throws from
     * `assertNodeErrorFree`).
     */
    async query(node: BinaryNode) {
      queriedIqs.push(node);
      if (nextQueryError) {
        const error = nextQueryError;
        nextQueryError = null;
        throw error;
      }
      return { tag: 'iq', attrs: { type: 'result', id: node.attrs.id ?? '' } };
    },
    generateMessageTag() {
      tagCounter += 1;
      return `FAKE-${tagCounter}`;
    },
    end() {
      ended = true;
    },
    emitConnection(update: ConnectionUpdate) {
      emitter.emit('connection.update', update);
    },
    emitReceipts(updates: ReceiptUpdate[]) {
      emitter.emit('message-receipt.update', updates);
    },
    emitCreds() {
      emitter.emit('creds.update');
    },
    sent,
    queriedIqs,
    failNextSend(error: Error) {
      nextError = error;
    },
    failNextQuery(error: Error) {
      nextQueryError = error;
    },
    get ended() {
      return ended;
    },
  };
  return socket;
}
