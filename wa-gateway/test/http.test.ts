import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import type { TestHarness } from './helpers.ts';
import {
  startHarness,
  postText,
  getJson,
  validPayload,
  keyFor,
  markConnected,
  markDisconnected,
  awaitReconnected,
  waitFor,
  TEST_KEY,
} from './helpers.ts';

describe('POST /v1/messages/text', () => {
  let harness: TestHarness;

  before(async () => {
    harness = await startHarness();
  });

  after(async () => {
    await harness.close();
  });

  test('first accept returns 202 queued with duplicate:false', async () => {
    const res = await postText(
      harness,
      validPayload({ metadata: { invoice_id: 'INV-2001', event: 'created', source: 'captshop-laravel' } }),
      { idempotencyKey: keyFor('INV-2001', 'created') },
    );

    assert.equal(res.status, 202);
    assert.equal(res.body.status, 'queued');
    assert.equal(res.body.duplicate, false);
    assert.equal(typeof res.body.message_id, 'string');
    assert.equal(res.body.invoice_id, 'INV-2001');
    assert.equal(res.body.event, 'created');
    // The response must not echo the message body or the raw idempotency key.
    assert.equal(res.body.body_text, undefined);
    assert.equal(res.body.idempotency_key, undefined);
  });

  test('accept does not depend on WhatsApp connectivity', async () => {
    // Nothing is connected in this suite.
    assert.equal(harness.gateway.connection.isConnected(), false);
    const res = await postText(harness, validPayload(), { idempotencyKey: keyFor('INV-2002', 'paid') });
    assert.equal(res.status, 202);
    assert.equal(harness.gateway.outbox.counts().queued > 0, true);
  });

  test('identical replay returns 200 duplicate:true with the original id', async () => {
    const key = keyFor('INV-2003', 'created');
    const first = await postText(harness, validPayload({ metadata: { invoice_id: 'INV-2003', event: 'created', source: 'captshop-laravel' } }), {
      idempotencyKey: key,
    });
    const second = await postText(harness, validPayload({ metadata: { invoice_id: 'INV-2003', event: 'created', source: 'captshop-laravel' } }), {
      idempotencyKey: key,
    });

    assert.equal(first.status, 202);
    assert.equal(second.status, 200);
    assert.equal(second.body.duplicate, true);
    assert.equal(second.body.message_id, first.body.message_id);
  });

  test('same key with different text returns 409 IDEMPOTENCY_CONFLICT', async () => {
    const key = keyFor('INV-2004', 'created');
    await postText(harness, validPayload({ metadata: { invoice_id: 'INV-2004', event: 'created' } }), {
      idempotencyKey: key,
    });
    const res = await postText(
      harness,
      validPayload({ text: 'Isi yang berbeda', metadata: { invoice_id: 'INV-2004', event: 'created' } }),
      { idempotencyKey: key },
    );

    assert.equal(res.status, 409);
    assert.equal((res.body.error as Record<string, unknown>).code, 'IDEMPOTENCY_CONFLICT');
  });

  test('10 identical calls send exactly once', async () => {
    const key = keyFor('INV-2005', 'completed');
    const payload = validPayload({
      text: 'Pesanan selesai.',
      metadata: { invoice_id: 'INV-2005', event: 'completed', source: 'captshop-laravel' },
    });

    const results = await Promise.all(
      Array.from({ length: 10 }, () => postText(harness, payload, { idempotencyKey: key })),
    );

    const accepted = results.filter((r) => r.status === 202);
    const replayed = results.filter((r) => r.status === 200);
    assert.equal(accepted.length, 1, 'exactly one 202');
    assert.equal(replayed.length, 9, 'nine 200 replays');

    const ids = new Set(results.map((r) => r.body.message_id));
    assert.equal(ids.size, 1, 'all responses reference one message');

    // And only one row exists for that key.
    const id = [...ids][0] as string;
    assert.equal(harness.gateway.outbox.getById(id)?.idempotency_key, key);
    const count = harness.gateway.db
      .prepare('SELECT COUNT(*) AS n FROM messages WHERE idempotency_key = ?')
      .get(key) as { n: number };
    assert.equal(count.n, 1);
  });

  test('invalid phone returns 422 and queues nothing', async () => {
    const before = harness.gateway.outbox.counts().queued;
    const invalidPhones = ['12345', 'not-a-phone', '', '0700000000', '6281234567890123456', '+1 555 0100'];
    for (const [index, to] of invalidPhones.entries()) {
      const res = await postText(harness, validPayload({ to }), {
        idempotencyKey: keyFor(`INV-PHONE-${index}`, 'created'),
      });
      assert.equal(res.status, 422, `phone ${JSON.stringify(to)} must be rejected`);
      const issues = (res.body.error as { issues: Array<{ field: string }> }).issues;
      assert.ok(issues.some((i) => i.field === 'to'), `phone ${JSON.stringify(to)} should report a 'to' issue`);
    }
    assert.equal(harness.gateway.outbox.counts().queued, before, 'no rows queued for invalid phones');
  });

  test('invalid text and metadata return 422 with field-level issues', async () => {
    const emptyText = await postText(harness, validPayload({ text: '   ' }), {
      idempotencyKey: keyFor('INV-TXT', 'created'),
    });
    assert.equal(emptyText.status, 422);
    assert.ok((emptyText.body.error as { issues: Array<{ field: string }> }).issues.some((i) => i.field === 'text'));

    const badEvent = await postText(harness, validPayload({ metadata: { invoice_id: 'INV-TXT', event: 'shipped' } }), {
      idempotencyKey: keyFor('INV-TXT', 'created'),
    });
    assert.equal(badEvent.status, 422);
    assert.ok(
      (badEvent.body.error as { issues: Array<{ field: string }> }).issues.some((i) => i.field === 'metadata.event'),
    );
  });

  test('missing or malformed Idempotency-Key returns 422', async () => {
    const missing = await postText(harness, validPayload(), {});
    assert.equal(missing.status, 422);
    assert.equal((missing.body.error as { code: string }).code, 'MISSING_IDEMPOTENCY_KEY');

    const malformed = await postText(harness, validPayload(), { idempotencyKey: 'not-a-key' });
    assert.equal(malformed.status, 422);
    assert.equal((malformed.body.error as { code: string }).code, 'INVALID_IDEMPOTENCY_KEY');
  });

  test('invalid JSON returns 400', async () => {
    const res = await postText(harness, null, {
      idempotencyKey: keyFor('INV-JSON', 'created'),
      rawBody: '{not json',
    });
    assert.equal(res.status, 400);
    assert.equal((res.body.error as { code: string }).code, 'INVALID_JSON');
  });

  test('missing internal key returns 401 without leaking the expected value', async () => {
    const res = await postText(harness, validPayload(), {
      idempotencyKey: keyFor('INV-AUTH', 'created'),
      key: null,
    });
    assert.equal(res.status, 401);
    const serialized = JSON.stringify(res.body);
    assert.equal((res.body.error as { code: string }).code, 'UNAUTHORIZED');
    assert.ok(!serialized.includes(TEST_KEY), 'response must not contain the expected key');
    assert.ok(!/key/i.test(serialized.replace(/"code":"UNAUTHORIZED"/, '')), 'no key hint in the body');
  });

  test('wrong internal key returns 401', async () => {
    const res = await postText(harness, validPayload(), {
      idempotencyKey: keyFor('INV-AUTH2', 'created'),
      key: 'wrong-key-wrong-key-wrong',
    });
    assert.equal(res.status, 401);
  });

  test('unauthorized requests queue nothing', async () => {
    const before = harness.gateway.outbox.counts().queued;
    await postText(harness, validPayload(), { idempotencyKey: keyFor('INV-NOAUTH', 'created'), key: 'nope-nope-nope-nope' });
    assert.equal(harness.gateway.outbox.counts().queued, before);
  });
});

describe('read endpoints', () => {
  let harness: TestHarness;

  before(async () => {
    harness = await startHarness();
  });

  after(async () => {
    await harness.close();
  });

  test('GET /health/live is 200 without auth', async () => {
    const res = await getJson(harness, '/health/live', { key: null });
    assert.equal(res.status, 200);
    assert.equal(res.body.status, 'ok');
    assert.equal(res.body.database, 'up');
  });

  test('the listening socket is bound to loopback only', () => {
    const address = harness.gateway.server.address();
    assert.ok(address && typeof address === 'object', 'server reports a bound address');
    // 127.0.0.1 is what config enforces; the socket must actually match it, not
    // merely the config object.
    assert.equal((address as { address: string }).address, '127.0.0.1');
    assert.notEqual((address as { address: string }).address, '0.0.0.0');
    assert.notEqual((address as { address: string }).address, '::');
  });

  test('GET /health/ready is non-2xx when unpaired', async () => {
    const res = await getJson(harness, '/health/ready', { key: null });
    assert.equal(res.status, 503);
    assert.equal(res.body.status, 'not_ready');
    assert.equal(res.body.session_state, 'unpaired');
  });

  test('GET /health/ready is 200 once the socket is open', async () => {
    markConnected(harness);
    await waitFor(() => harness.gateway.connection.isConnected(), 2_000, 'connection open');

    const res = await getJson(harness, '/health/ready', { key: null });
    assert.equal(res.status, 200);
    assert.equal(res.body.status, 'ready');
  });

  test('GET /health/ready is non-2xx after a disconnect', async () => {
    markDisconnected(harness, 428);
    await waitFor(() => !harness.gateway.connection.isConnected(), 2_000, 'connection closed');

    const res = await getJson(harness, '/health/ready', { key: null });
    assert.equal(res.status, 503);
    assert.equal(res.body.reason, 'NOT_CONNECTED');
  });

  test('GET /v1/session/status requires the internal key', async () => {
    const res = await getJson(harness, '/v1/session/status', { key: null });
    assert.equal(res.status, 401);
  });

  test('GET /v1/session/status reports state, sender, timestamps and queue counts', async () => {
    await awaitReconnected(harness, '628999888777@s.whatsapp.net');

    await postText(harness, validPayload({ metadata: { invoice_id: 'INV-STAT', event: 'paid' } }), {
      idempotencyKey: keyFor('INV-STAT', 'paid'),
    });

    const res = await getJson(harness, '/v1/session/status');
    assert.equal(res.status, 200);
    assert.equal(res.body.state, 'connected');
    const sender = res.body.sender as { jid: string; masked: string | null };
    assert.equal(sender.jid, '628999888777@s.whatsapp.net');
    assert.ok(res.body.last_connected_at, 'last_connected_at is set');
    const queue = res.body.queue as Record<string, number>;
    assert.ok((queue.queued ?? 0) >= 1, 'queued count includes the new message');

    // No auth material may appear in the status payload.
    const serialized = JSON.stringify(res.body);
    assert.ok(!serialized.includes('signedIdentityKey'));
    assert.ok(!serialized.includes('noiseKey'));
    assert.ok(!serialized.includes('advSecretKey'));
  });

  test('GET /v1/messages/{id} returns the message and 404s for unknown ids', async () => {
    const created = await postText(harness, validPayload({ metadata: { invoice_id: 'INV-LOOK', event: 'created' } }), {
      idempotencyKey: keyFor('INV-LOOK', 'created'),
    });
    const id = created.body.message_id as string;

    const found = await getJson(harness, `/v1/messages/${id}`);
    assert.equal(found.status, 200);
    assert.equal(found.body.message_id, id);
    assert.equal(found.body.status, 'queued');
    assert.equal(found.body.body_text, undefined, 'lookup must not return message text');

    const missing = await getJson(harness, '/v1/messages/00000000-0000-4000-8000-000000000000');
    assert.equal(missing.status, 404);
  });

  test('unknown route returns 404 and requires auth', async () => {
    const noAuth = await getJson(harness, '/v1/unknown', { key: null });
    assert.equal(noAuth.status, 401);

    const withAuth = await getJson(harness, '/v1/unknown');
    assert.equal(withAuth.status, 404);
  });
});
