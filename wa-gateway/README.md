# CAPTSHOP WhatsApp Gateway

Localhost-only Node.js service that sends CAPTSHOP transactional WhatsApp
messages through a single Baileys session. Implements
`/root/.hermes/projects/captshop/BAILEYS-GATEWAY-SPEC.md` (v1).

Laravel posts a message here; the gateway queues it durably in SQLite and a
single worker delivers it over the Baileys socket. Message acceptance never
depends on the WhatsApp connection being up.

```
Laravel CAPTSHOP
  -> POST http://127.0.0.1:3213/v1/messages/text
  -> captshop-wa-gateway (Node.js + Baileys)
       - SQLite auth state
       - SQLite durable outbox
       - single Baileys socket/session
       - retry/reconnect/status
```

## Scope (v1)

In scope: outbound transactional text messages, durable outbox with idempotency,
SQLite auth store, localhost HTTP interface, CLI pairing and status, sanitized
logging.

Explicitly **not** in scope: inbound messages, chatbots, broadcasts, groups,
media, Next.js integration, a public endpoint, dashboard pairing/reset, and
session reset/logout commands.

## Requirements

- Node.js >= 22.18 (uses native TypeScript type stripping for tests; no loader
  dependency such as `tsx` is needed at runtime or in tests).
- A writable directory for the SQLite database, outside any webroot.

## Install and build

```bash
npm install
npm run build        # compiles src/ -> dist/ (required before start/pair/status)
npm run typecheck    # type-checks src/ + test/ without emitting
npm test             # builds, then runs the full suite
```

`start`, `pair` and `status` each have a `pre*` hook that runs the build, so
they always execute freshly compiled JavaScript from `dist/`. `npm run build`
uses `tsconfig.build.json`, which emits only `src/` (tests are type-checked by
`npm run typecheck` but never shipped).

## Configuration

Copy `.env.example` to `.env` (gitignored) or export the variables. The only
required value is the internal key:

```bash
export WHATSAPP_GATEWAY_KEY="$(openssl rand -hex 32)"
```

| Variable | Default | Purpose |
| --- | --- | --- |
| `WHATSAPP_GATEWAY_KEY` | *(required, >=16 chars)* | Shared secret Laravel sends as `X-Internal-Key` |
| `WHATSAPP_GATEWAY_HOST` | `127.0.0.1` | Bind address; must be loopback (`127.0.0.1`, `::1`, `localhost`) |
| `WHATSAPP_GATEWAY_PORT` | `3213` | Listen port |
| `WHATSAPP_GATEWAY_DB` | `./data/gateway.db` | SQLite file (outbox **and** auth state) |
| `WHATSAPP_GATEWAY_MAX_ATTEMPTS` | `8` | Attempts before a message becomes terminal |
| `WHATSAPP_GATEWAY_RETRY_BASE_MS` | `2000` | Exponential backoff base |
| `WHATSAPP_GATEWAY_RETRY_MAX_MS` | `300000` | Backoff ceiling |
| `WHATSAPP_GATEWAY_RETRY_JITTER` | `0.2` | Jitter ratio |
| `WHATSAPP_GATEWAY_WORKER_INTERVAL_MS` | `500` | Outbox poll interval |
| `WHATSAPP_GATEWAY_STALE_CLAIM_MS` | `120000` | Lease expiry for abandoned claims |
| `WHATSAPP_GATEWAY_RECONNECT_BASE_MS` | `2000` | Socket reconnect backoff base |
| `WHATSAPP_GATEWAY_RECONNECT_MAX_MS` | `60000` | Socket reconnect backoff ceiling |
| `WHATSAPP_GATEWAY_LOG_LEVEL` | `info` | `silent\|error\|warn\|info\|debug` |

The gateway refuses to start with a missing/short key, and refuses to start on
any non-loopback bind address. There is no opt-out: the gateway is a
localhost-only service and must not be exposed publicly.

## Pairing (operator, CLI only)

```bash
npm run pair -- --phone 628123456789
```

- `--phone` must be digits only with the country code (`62...`).
- The pairing code is printed **once** to your terminal. It is never logged and
  never persisted. Enter it in WhatsApp > Linked devices > Link with phone number.
- A code is printed **only after WhatsApp acknowledged the request**. If the
  server rejects the `companion_hello` IQ (for example `400 bad-request`), the
  command fails with the server's reason and prints no code.
- If a session is already registered the command refuses and exits non-zero.
  v1 has no re-pair or reset command: discarding a live session is destructive
  and out of scope. Do not delete `data/gateway.db` to work around this without
  understanding that it drops the linked device.
- `--timeout-seconds N` bounds how long it waits for the code (default 180).

### Why the gateway does not call `socket.requestPairingCode()`

baileys `7.0.0-rc14` implements pairing optimistically (`lib/Socket/socket.js`):
it mints the code locally, sets `creds.me`, emits `creds.update` — which we
persist — and only *then* sends the `link_code_companion_reg` IQ with
`sendNode()`, fire and forget. Two consequences:

1. A server rejection arrives after the code has already been returned, so the
   operator gets an 8-character code that can never link.
2. `companion_platform_display` is built as `${browser[1]} (${browser[0]})`, so
   a custom label sends `Chrome (CAPTSHOP Gateway)`, which WhatsApp's stricter
   `companion_hello` validator rejects with `400 bad-request`.

`src/pairing.ts` therefore re-implements only the request/acknowledgement step
against Baileys' public API (`query`, `derivePairingCodeKey`, `aesEncryptCTR`,
`bytesToCrockford`, `jidEncode`):

- `canonicalPairingPlatform()` maps the configured browser tuple onto the
  canonical `companion_platform_id`/`companion_platform_display` pair
  (`Chrome (Mac OS)` for the shipped `['CAPTSHOP Gateway', 'Chrome', '1.0.0']`).
  The tuple itself is unchanged for registration, so `clientPayload` still
  advertises the CAPTSHOP label.
- the IQ goes through `query()`, so a `<iq type='error'>` reply rejects before
  any code is returned or any creds are stored;
- `creds.me` is written and `saveCreds()` runs only after the server accepts,
  and the transient `creds.pairingCode` is rolled back on failure.

Upstream references: PR #2559 (`fix: harden pairing code request flow`, still
open/unmerged) documents both defects. This is a local, tested re-implementation
of the same behaviour rather than a cherry-pick.

### Post-pair `515 restartRequired`

After the phone confirms, WhatsApp asks the companion to reconnect with
`DisconnectReason.restartRequired` (515). `WhatsAppConnection` treats that as a
normal disconnect, reconnects with backoff, and only then reports `open`. The
CLI keeps running until that `open` event or the timeout, so a successful
pairing is reported by `paired: session is connected and credentials are
stored.` and not by the moment the code was entered.

Check state at any time (works while the gateway is stopped):

```bash
npm run status
```

## Running

```bash
npm start
```

Binds `127.0.0.1:3213` by default. Handles `SIGINT`/`SIGTERM` with a graceful
shutdown that releases any claimed work back to `failed_retryable` before exit.

## HTTP interface

All endpoints are loopback-only.

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| `POST` | `/v1/messages/text` | `X-Internal-Key` | Queue a transactional text |
| `GET` | `/v1/session/status` | `X-Internal-Key` | Session state, sender, timestamps, queue counts |
| `GET` | `/v1/messages/{id}` | `X-Internal-Key` | Message status lookup |
| `GET` | `/health/live` | none | Process and database alive |
| `GET` | `/health/ready` | none | `200` only when connected and able to send |

### POST /v1/messages/text

```http
X-Internal-Key: <secret>
Idempotency-Key: transaction:INV-1001:event:created
Content-Type: application/json
```

```json
{
  "to": "628123456789",
  "text": "Pesanan INV-1001 sudah dibuat.",
  "metadata": { "invoice_id": "INV-1001", "event": "created", "source": "captshop-laravel" }
}
```

Responses:

| Case | Status | Body |
| --- | --- | --- |
| First accept | `202` | `{message_id, status:"queued", duplicate:false, ...}` |
| Identical replay | `200` | same message, `duplicate:true` |
| Same key, different payload | `409` | `{error:{code:"IDEMPOTENCY_CONFLICT"}}` |
| Invalid phone/text/metadata | `422` | `{error:{code:"VALIDATION_FAILED", issues:[...]}}` |
| Missing/bad internal key | `401` | `{error:{code:"UNAUTHORIZED"}}` (no hint about the key) |

Responses never include the message body or the raw idempotency key.

## Outbox behaviour

Statuses: `queued`, `sending`, `sent`, `delivered`, `read`, `failed_retryable`,
`failed_terminal`.

- One worker claims messages atomically. Claims carry a fencing token, so a
  worker that lost its lease cannot mark a message sent.
- Transient failures (disconnects, timeouts, 5xx) retry with bounded exponential
  backoff until `max_attempts`, then become `failed_terminal`.
- Invalid destinations are terminal immediately.
- `loggedOut` stops retries: pending and in-flight messages become
  `failed_terminal` with `SESSION_LOGGED_OUT`, the session is marked
  `logged_out`, and auth material is **not** deleted.
- Messages accepted while disconnected stay `queued` and are sent on reconnect.
- Receipts upgrade `sent` → `delivered` → `read` and never regress.

## Security notes

- Binds loopback only; every non-loopback host is a startup error, with no opt-out.
- `X-Internal-Key` is compared in constant time against a hashed form, so
  comparison time does not leak key length.
- Auth state lives in the same SQLite file as the outbox, outside any webroot.
  The parent directory is created `0700` and the file is `chmod 0600` on open.
- Unreadable persisted auth fails **closed**: startup, `pair` and `status` abort
  with `AUTH_STORE_CORRUPT` and never re-initialise or overwrite the stored
  creds row, so corruption cannot silently turn into a fresh identity.
- Logs are structured JSON and redacted twice: by key name (`text`, `key`,
  `token`, `pairing`, `qr`, `session`, …) and by value (opaque blobs, PEM blocks,
  bearer tokens). Message text, internal keys, QR strings, pairing codes and
  Signal key material never reach a log sink.
- API responses and the status CLI expose a masked sender (`62812****789`), never
  the full number, and never message bodies.

## Tests

```bash
npm test
```

Covers, with a fake Baileys socket and no network access:

- auth store roundtrip for creds and representative Signal key types, including
  restart persistence and batch atomicity;
- corrupt creds fail closed (`AUTH_STORE_CORRUPT`) with the stored row untouched;
- disconnected accept queues, reconnect sends;
- 10 identical calls send once; conflicting replay returns `409`;
- invalid numbers rejected; logged-out stops retry and preserves auth;
- graceful shutdown releases claimed work;
- no secrets or message text in logs;
- loopback-only bind (every non-loopback host rejected); CLI refuses re-pairing
  and has no reset flag;
- pairing-code request path: canonical `companion_platform_display`/`_id`
  normalization (including the shipped CAPTSHOP label), the exact
  `<iq type='error'><error code='400' text='bad-request'/></iq>` reply yielding
  no code and an unpaired auth store, a successful IQ returning the code only
  after the result, the pairing-ready gate (and its re-arming on disconnect),
  rejection of a concurrent second request, the CLI deadline bounding a stalled
  request, and retryability classification.

No test pairs a real number or sends a real WhatsApp message.

## Known limitations and residual risks

These are deliberate v1 boundaries, not oversights:

- **No real Baileys socket is exercised.** Every test injects a fake socket
  through `SocketFactory`. The reconnect/backoff/logout state machine, the
  outbox worker and the HTTP surface are all covered, but real
  `makeWASocket` handshake behaviour (protocol-version fetch, QR fallback,
  LID migration) is unverified until a controlled pairing run.
- **Pairing is unverified end to end.** `npm run pair` is covered for argument
  validation, refusal-on-registered-session, corrupt-store refusal,
  no-reset-flag, the pairing-ready gate, canonical platform normalization and
  the reject-before-code contract — all against mocked IQ nodes. The live
  pairing-code exchange against WhatsApp has still never been run, so the
  canonical values are validated against upstream's report and WhatsApp's
  observed validator, not against a real `companion_hello` acceptance here.
- **`companion_reg_refresh` is NOT handled (known unresolved limitation).**
  The server can send `<notification type="companion_reg_refresh">` to an
  unpaired companion to retire the registration material it is advertising.
  Baileys 7.0.0-rc14 has no handler: `handleNotification` acks it and
  `processNotification` drops it, leaving a stale `advSecretKey`. Upstream
  PR #2765 (open, unmerged, `unstable`) fixes it, but the patch is not
  extractable as a minimal subset: it adds `makePairingQRRenderer` and
  restructures the `CB:iq,type:set,pair-device` QR generator so the adv secret
  is read per render, and it separately rewrites `sendMessageAck` to work before
  login (the pre-login ack crash it needs is #2749's fix). Those are
  `socket.ts`/`messages-recv.ts` internals; the gateway only sees `socket.ev`
  and does not fork Baileys.
  Two facts bound the residual risk instead of hand-waving it:
  1. The refresh exists so a client re-renders the **QR** with a live secret.
     This gateway never shows a QR: it consumes `connection.update.qr` only as
     the "pairing handshake started" readiness signal and prints nothing but
     the pairing code. There is no operator-visible QR that could go stale, and
     the `companion_hello` IQ carries only `noiseKey.public` plus the wrapped
     ephemeral key — never `advSecretKey`.
  2. Upstream's own handler deliberately **skips rotation when `creds.me` is
     set**, because `advSecretKey` is what a pending or completed pairing is
     verified against (`configureSuccessfulPairing` reads it to check the
     pair-success HMAC). Our flow sets `creds.me` as soon as `companion_hello`
     is acknowledged — i.e. before `pair-success` can arrive — so for exactly
     the window where the adv secret still matters, upstream would ignore the
     refresh too. Rotating it here would deviate from upstream semantics and
     could break a pairing that would otherwise succeed.
  The remaining exposure is narrow and fails visibly: a refresh that lands
  before `companion_hello` is acknowledged is dropped, and if the server then
  rejects the pair-success signature the CLI reports a failed pairing
  (non-zero exit, no code printed) instead of silently stalling.
- **`loggedOut` terminal-fails pending and in-flight messages**
  (`SESSION_LOGGED_OUT`). This is a deliberate choice to fail visibly instead of
  holding an unbounded queue that may never drain; the trade-off is that a
  transient unlink loses those notifications. They remain visible in the
  `failed_terminal` count and `GET /v1/session/status`.
- **Single-instance assumption.** The schema and claim fencing tolerate
  overlapping workers, but SQLite + one Baileys session is designed for exactly
  one process. Running two gateways against one database is unsupported.
- **`GET /v1/messages/{id}` returns the full destination number** to an
  authenticated internal caller. Only the operator-facing status CLI masks it.
- **No delivery guarantee after `sent`.** `sent` means Baileys accepted the
  message; `delivered`/`read` depend on receipts from the recipient's device and
  may never arrive.
- **Laravel adapter, admin panel and Fonnte removal are out of scope for this
  deliverable** and are not implemented here.
- **Not deployed, not committed.** This tree is local-only, uncommitted, and has
  never been started against a real WhatsApp account.
