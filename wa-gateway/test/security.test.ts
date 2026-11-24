import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { createLogger, redact, scrubString, isSecretKey } from '../src/logger.ts';
import { verifyInternalKey } from '../src/auth.ts';
import { normalizePhone, maskPhone } from '../src/phone.ts';
import { loadConfig, ConfigError, LOOPBACK_HOSTS } from '../src/config.ts';
import { validateIdempotencyKey, validateSendTextRequest } from '../src/validation.ts';
import { classifySendFailure, withTimeout } from '../src/worker.ts';
import { disconnectStatusCode } from '../src/connection.ts';
import { canonicalPayloadHash } from '../src/outbox.ts';
import { startHarness, awaitReconnected, postText, validPayload, keyFor, waitFor, TEST_KEY } from './helpers.ts';

describe('logger redaction', () => {
  test('message text, keys and auth material never reach the sink', () => {
    const lines: string[] = [];
    const logger = createLogger({ level: 'debug', sink: (line) => lines.push(line) });

    logger.info('http.message_queued', {
      message_id: 'abc-123',
      invoice_id: 'INV-1',
      // Every one of these must be redacted:
      text: 'Rahasia pesanan pelanggan',
      internal_key: 'super-secret-key-value',
      x_internal_key: 'super-secret-key-value',
      signedIdentityKey: { private: 'AAAA', public: 'BBBB' },
      noiseKey: { private: 'CCCC' },
      advSecretKey: 'advsecret',
      pairingCode: 'ABCD-EFGH',
      qr: '2@abcdefghijklmnop',
      session: 'session-bytes',
      body: 'payload body',
    });

    const output = lines.join('\n');
    assert.ok(!output.includes('Rahasia pesanan pelanggan'), 'message text must not be logged');
    assert.ok(!output.includes('super-secret-key-value'), 'internal key must not be logged');
    assert.ok(!output.includes('advsecret'), 'adv secret must not be logged');
    assert.ok(!output.includes('ABCD-EFGH'), 'pairing code must not be logged');
    assert.ok(!output.includes('2@abcdefghijklmnop'), 'QR must not be logged');
    assert.ok(!output.includes('session-bytes'), 'session material must not be logged');

    // Safe fields survive, so the log stays useful.
    assert.ok(output.includes('abc-123'));
    assert.ok(output.includes('INV-1'));
  });

  test('nested structures are redacted recursively', () => {
    const logger = createLogger({ level: 'debug', sink: () => {} , capture: true});
    logger.error('nested', {
      outer: { inner: { text: 'secret text', token: 'abc', ok: 1 } },
      list: [{ password: 'p' }, { safe: 'yes' }],
    });
    const [record] = logger.records();
    const serialized = JSON.stringify(record);
    assert.ok(!serialized.includes('secret text'));
    assert.ok(!serialized.includes('"abc"'));
    assert.ok(!serialized.includes('"p"'));
    assert.ok(serialized.includes('yes'));
  });

  test('opaque blobs and bearer tokens are scrubbed from free-form strings', () => {
    const longBase64 = 'A'.repeat(120);
    assert.equal(scrubString(longBase64), '[redacted]');
    assert.equal(scrubString('-----BEGIN PRIVATE KEY-----abc'), '[redacted]');
    assert.ok(scrubString('Authorization: Bearer abcdefghijklmnop').includes('[redacted]'));
    assert.equal(scrubString('short normal message'), 'short normal message');
  });

  test('errors are reduced to name and message, with the message scrubbed', () => {
    const logger = createLogger({ level: 'debug', sink: () => {}, capture: true });
    const error = new Error(`failed with Bearer ${'x'.repeat(40)}`);
    logger.error('boom', { error });
    const serialized = JSON.stringify(logger.records()[0]);
    assert.ok(serialized.includes('Error'));
    assert.ok(!serialized.includes('x'.repeat(40)), 'bearer token is scrubbed');
  });

  test('buffers and byte arrays are never serialised', () => {
    const logger = createLogger({ level: 'debug', sink: () => {}, capture: true });
    logger.info('bytes', { blob: Buffer.from('secret-bytes'), arr: new Uint8Array([1, 2, 3]) });
    const serialized = JSON.stringify(logger.records()[0]);
    assert.ok(!serialized.includes('secret-bytes'));
    assert.ok(serialized.includes('[redacted]'));
  });

  test('level filtering works and silent emits nothing', () => {
    const lines: string[] = [];
    const warnOnly = createLogger({ level: 'warn', sink: (line) => lines.push(line) });
    warnOnly.debug('nope');
    warnOnly.info('nope');
    warnOnly.warn('yes');
    assert.equal(lines.length, 1);

    const silent = createLogger({ level: 'silent', sink: (line) => lines.push(line) });
    silent.error('nope');
    assert.equal(lines.length, 1);
  });

  test('secret key detection is not over-eager on safe operational fields', () => {
    assert.equal(isSecretKey('text'), true);
    assert.equal(isSecretKey('pairingCode'), true);
    assert.equal(isSecretKey('noiseKey'), true);
    assert.equal(isSecretKey('message_id'), false);
    assert.equal(isSecretKey('session_state'), false);
    assert.equal(isSecretKey('last_error_code'), false);
  });

  test('redact handles primitives, null and depth limits', () => {
    assert.equal(redact(null), null);
    assert.equal(redact(42), 42);
    assert.equal(redact(true), true);
    let deep: Record<string, unknown> = { v: 'leaf' };
    for (let i = 0; i < 10; i += 1) deep = { deep };
    assert.equal(JSON.stringify(redact(deep)).includes('leaf'), false);
  });
});

describe('internal key verification', () => {
  const key = 'correct-key-0123456789abcdef';

  test('accepts the exact key and rejects everything else', () => {
    assert.equal(verifyInternalKey(key, key), true);
    assert.equal(verifyInternalKey('wrong', key), false);
    assert.equal(verifyInternalKey('', key), false);
    assert.equal(verifyInternalKey(undefined, key), false);
    assert.equal(verifyInternalKey(key, ''), false);
  });

  test('is not fooled by a prefix or case change', () => {
    assert.equal(verifyInternalKey(key.slice(0, -1), key), false);
    assert.equal(verifyInternalKey(`${key}x`, key), false);
    assert.equal(verifyInternalKey(key.toUpperCase(), key), false);
  });
});

describe('phone normalization', () => {
  test('accepts the canonical Indonesian shapes', () => {
    for (const input of ['628123456789', '08123456789', '8123456789', '+62 812-3456-789', '62812 3456 789']) {
      const result = normalizePhone(input);
      assert.equal(result.ok, true, `${input} should be valid`);
      if (result.ok) {
        assert.equal(result.e164, '628123456789');
        assert.equal(result.jid, '628123456789@s.whatsapp.net');
      }
    }
  });

  test('rejects non-Indonesian, malformed and placeholder numbers', () => {
    for (const input of ['', '   ', 'abc', '+1 555 0100', '12345', '0700000000', '620000000000', '6281234567890123456']) {
      assert.equal(normalizePhone(input).ok, false, `${JSON.stringify(input)} should be invalid`);
    }
    assert.equal(normalizePhone(undefined).ok, false);
    assert.equal(normalizePhone(628123456789).ok, false, 'numbers are rejected, only strings are accepted');
  });

  test('masks a number for display', () => {
    assert.equal(maskPhone('628123456789'), '62812****789');
    assert.ok(!maskPhone('628123456789').includes('3456'));
  });
});

describe('config', () => {
  test('requires an internal key of reasonable length', () => {
    assert.throws(() => loadConfig({}), ConfigError);
    assert.throws(() => loadConfig({ WHATSAPP_GATEWAY_KEY: 'short' }), ConfigError);
  });

  test('binds loopback only and refuses every non-loopback address', () => {
    const base = { WHATSAPP_GATEWAY_KEY: 'x'.repeat(32), WHATSAPP_GATEWAY_DB: '/tmp/cfg-test/a.db' };
    const config = loadConfig(base);
    assert.equal(config.host, '127.0.0.1');
    assert.equal(config.port, 3213);
    assert.ok(LOOPBACK_HOSTS.has(config.host));

    // Loopback aliases are accepted.
    for (const host of ['127.0.0.1', '::1', 'localhost']) {
      assert.equal(loadConfig({ ...base, WHATSAPP_GATEWAY_HOST: host }).host, host);
    }

    // There is no env opt-out: every non-loopback address is a config error.
    for (const host of ['0.0.0.0', '::', '192.168.1.10', '10.0.0.5', 'gateway.example.com', '']) {
      assert.throws(
        () => loadConfig({ ...base, WHATSAPP_GATEWAY_HOST: host }),
        ConfigError,
        `${JSON.stringify(host)} must be rejected`,
      );
    }

    // A legacy opt-out env var must not resurrect a public bind.
    assert.throws(
      () =>
        loadConfig({
          ...base,
          WHATSAPP_GATEWAY_HOST: '0.0.0.0',
          WHATSAPP_GATEWAY_ALLOW_NON_LOOPBACK: 'true',
        }),
      ConfigError,
      'ALLOW_NON_LOOPBACK must not permit a public bind',
    );

    // The config surface must not even carry a non-loopback switch.
    assert.equal(
      Object.prototype.hasOwnProperty.call(config, 'allowNonLoopback'),
      false,
      'GatewayConfig must not expose an allowNonLoopback field',
    );
  });

  test('rejects nonsensical numeric settings', () => {
    const base = { WHATSAPP_GATEWAY_KEY: 'x'.repeat(32), WHATSAPP_GATEWAY_DB: '/tmp/cfg-test/b.db' };
    assert.throws(() => loadConfig({ ...base, WHATSAPP_GATEWAY_PORT: 'abc' }), ConfigError);
    assert.throws(() => loadConfig({ ...base, WHATSAPP_GATEWAY_PORT: '0' }), ConfigError);
    assert.throws(() => loadConfig({ ...base, WHATSAPP_GATEWAY_MAX_ATTEMPTS: '-3' }), ConfigError);
  });
});

describe('request validation', () => {
  test('accepts a well-formed payload and normalizes the phone', () => {
    const result = validateSendTextRequest({
      to: '08123456789',
      text: 'Halo',
      metadata: { invoice_id: 'INV-1', event: 'paid', source: 'captshop-laravel' },
    });
    assert.equal(result.ok, true);
    if (result.ok) {
      assert.equal(result.value.to, '628123456789');
      assert.equal(result.value.jid, '628123456789@s.whatsapp.net');
      assert.equal(result.value.invoiceId, 'INV-1');
      assert.equal(result.value.event, 'paid');
    }
  });

  test('rejects non-object bodies, unknown events and oversized text', () => {
    assert.equal(validateSendTextRequest(null).ok, false);
    assert.equal(validateSendTextRequest([]).ok, false);
    assert.equal(validateSendTextRequest('string').ok, false);
    assert.equal(
      validateSendTextRequest({ to: '628123456789', text: 'x', metadata: { event: 'shipped' } }).ok,
      false,
    );
    assert.equal(validateSendTextRequest({ to: '628123456789', text: 'x'.repeat(5000) }).ok, false);
  });

  test('idempotency key must match the documented shape', () => {
    assert.equal(validateIdempotencyKey('transaction:INV-1:event:created'), null);
    assert.equal(validateIdempotencyKey('transaction:INV-1:event:paid'), null);
    assert.equal(validateIdempotencyKey('transaction:INV-1:event:completed'), null);
    assert.notEqual(validateIdempotencyKey(undefined), null);
    assert.notEqual(validateIdempotencyKey('transaction:INV-1:event:shipped'), null);
    assert.notEqual(validateIdempotencyKey('random-key'), null);
    assert.notEqual(validateIdempotencyKey(`transaction:${'A'.repeat(300)}:event:paid`), null);
  });
});

describe('failure classification', () => {
  test('transient network errors retry', () => {
    for (const code of ['ECONNRESET', 'ETIMEDOUT', 'EPIPE', 'NOT_CONNECTED', 'SEND_TIMEOUT']) {
      const result = classifySendFailure(Object.assign(new Error('x'), { code }));
      assert.equal(result.kind, 'transient', `${code} should be transient`);
    }
  });

  test('invalid destination and auth failures are terminal', () => {
    for (const code of ['INVALID_JID', 'INVALID_PHONE', 'NOT_ON_WHATSAPP', 'LOGGED_OUT', 'FORBIDDEN']) {
      const result = classifySendFailure(Object.assign(new Error('x'), { code }));
      assert.equal(result.kind, 'terminal', `${code} should be terminal`);
    }
  });

  test('boom status codes map to the right kind', () => {
    const boom = (status: number) => Object.assign(new Error('x'), { output: { statusCode: status } });
    assert.equal(classifySendFailure(boom(401)).kind, 'terminal');
    assert.equal(classifySendFailure(boom(400)).kind, 'terminal');
    assert.equal(classifySendFailure(boom(408)).kind, 'transient');
    assert.equal(classifySendFailure(boom(503)).kind, 'transient');
  });

  test('unknown errors are transient but bounded by max_attempts', () => {
    const result = classifySendFailure(new Error('something weird'));
    assert.equal(result.kind, 'transient');
    assert.equal(result.code, 'UNKNOWN_TRANSIENT');
    assert.equal(classifySendFailure(null).kind, 'transient');
  });

  test('disconnectStatusCode reads boom-shaped errors', () => {
    assert.equal(disconnectStatusCode({ output: { statusCode: 401 } }), 401);
    assert.equal(disconnectStatusCode({ statusCode: 428 }), 428);
    assert.equal(disconnectStatusCode(null), null);
    assert.equal(disconnectStatusCode(new Error('no code')), null);
  });
});

describe('withTimeout', () => {
  test('resolves fast promises and rejects slow ones with a code', async () => {
    assert.equal(await withTimeout(Promise.resolve('ok'), 100), 'ok');

    await assert.rejects(
      withTimeout(new Promise((resolve) => setTimeout(resolve, 200)), 20),
      (error: Error & { code?: string }) => error.code === 'SEND_TIMEOUT',
    );
  });
});

describe('canonical payload hash', () => {
  test('is stable across key order and sensitive to meaningful change', () => {
    const a = canonicalPayloadHash({ to: '6281', text: 'a', invoiceId: 'I', event: 'paid' });
    const b = canonicalPayloadHash({ text: 'a', event: 'paid', to: '6281', invoiceId: 'I' });
    assert.equal(a, b);
    assert.notEqual(a, canonicalPayloadHash({ to: '6281', text: 'b', invoiceId: 'I', event: 'paid' }));
    assert.notEqual(a, canonicalPayloadHash({ to: '6282', text: 'a', invoiceId: 'I', event: 'paid' }));
  });
});

describe('end-to-end log hygiene', () => {
  test('a full accept -> send -> receipt cycle logs no message text or secrets', async () => {
    const harness = await startHarness();
    try {
      await awaitReconnected(harness);

      const secretText = 'RAHASIA-PESANAN-JANGAN-DICATAT';
      const accepted = await postText(
        harness,
        validPayload({
          text: secretText,
          metadata: { invoice_id: 'INV-LOG', event: 'paid', source: 'captshop-laravel' },
        }),
        { idempotencyKey: keyFor('INV-LOG', 'paid') },
      );
      assert.equal(accepted.status, 202);

      // Deliver it, and drive a receipt upgrade so the receipt path logs too.
      await harness.gateway.worker.tick();
      harness.socket().emitReceipts([
        { key: { id: 'BAE-1' }, receipt: { receiptTimestamp: 1, readTimestamp: 1 } },
      ]);
      await waitFor(() => harness.gateway.outbox.counts().read === 1, 2_000, 'read receipt applied');

      // An unauthorized request logs a warning; it must not echo the key.
      await postText(harness, validPayload(), {
        idempotencyKey: keyFor('INV-LOG2', 'paid'),
        key: 'wrong-key-that-must-not-appear',
      });

      const logs = harness.logs();
      assert.ok(logs.length > 0, 'the gateway did log something, so this is a real check');

      assert.ok(!logs.includes(secretText), 'message text must never reach a log sink');
      assert.ok(!logs.includes('RAHASIA'), 'no fragment of the message text is logged');
      assert.ok(!logs.includes(TEST_KEY), 'the configured internal key is never logged');
      assert.ok(!logs.includes('wrong-key-that-must-not-appear'), 'a rejected key is never logged');
      assert.ok(!logs.includes('628123456789'), 'the destination number is not logged in full');
      assert.ok(!/signedIdentityKey|noiseKey|advSecretKey|pairingCode/.test(logs), 'no auth material');

      // But the operational trail is still useful.
      assert.ok(logs.includes('http.message_queued'), 'queueing is logged');
      assert.ok(logs.includes('worker.sent'), 'the send is logged');
      assert.ok(logs.includes('INV-LOG'), 'the invoice id is logged');
    } finally {
      await harness.close();
    }
  });
});
