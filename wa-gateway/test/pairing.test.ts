import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import {
  assertNodeErrorFree,
  decodeBinaryNode,
  encodeBinaryNode,
} from 'baileys';
import type { AuthenticationCreds, BinaryNode } from 'baileys';
import { initAuthCreds } from 'baileys';
import { createLogger } from '../src/logger.ts';
import {
  buildCompanionHelloNode,
  canonicalPairingPlatform,
  createPairingSession,
  isRetryablePairingError,
  requestPairingCodeAwaitingResult,
} from '../src/pairing.ts';
import { startHarness } from './helpers.ts';

const silentLogger = (): ReturnType<typeof createLogger> =>
  createLogger({ level: 'debug', capture: true, sink: () => {} });

/** The exact node WhatsApp sends back when it rejects companion_hello. */
const badRequestNode = (id = 'PAIR-1'): BinaryNode => ({
  tag: 'iq',
  attrs: { from: '@s.whatsapp.net', id, type: 'error' },
  content: [{ tag: 'error', attrs: { code: '400', text: 'bad-request' } }],
});

/** The error Baileys' `query()` raises for that node (assertNodeErrorFree). */
const badRequestError = (): Error => {
  try {
    assertNodeErrorFree(badRequestNode());
  } catch (error) {
    return error as Error;
  }
  throw new Error('assertNodeErrorFree did not throw');
};

function freshCreds(): AuthenticationCreds {
  return initAuthCreds();
}

/** Polls until `predicate` holds, so tests never depend on a fixed sleep. */
async function waitUntil(predicate: () => boolean, label: string, timeoutMs = 5_000): Promise<void> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (predicate()) return;
    await new Promise((resolve) => setTimeout(resolve, 5));
  }
  throw new Error(`timed out waiting for ${label}`);
}

/** Collects the tag/content of a node's children, for assertions. */
function childOf(node: BinaryNode, tag: string): BinaryNode | undefined {
  const content = node.content;
  if (!Array.isArray(content)) return undefined;
  return content.find((entry) => entry.tag === tag);
}

function linkCodeNode(node: BinaryNode): BinaryNode {
  const found = childOf(node, 'link_code_companion_reg');
  assert.ok(found, 'companion_hello IQ must carry link_code_companion_reg');
  return found;
}

/**
 * Decoded nodes carry text as `Buffer`s (that is what goes on the wire), so
 * assertions compare the decoded UTF-8 rather than the in-memory string.
 */
function textOf(node: BinaryNode | undefined): string | null {
  if (!node) return null;
  const { content } = node;
  if (typeof content === 'string') return content;
  if (Buffer.isBuffer(content) || content instanceof Uint8Array) {
    return Buffer.from(content).toString('utf-8');
  }
  return null;
}

describe('canonicalPairingPlatform', () => {
  test('normalizes the CAPTSHOP production label to a canonical display', () => {
    // This is the exact tuple the production bundle ships; upstream would have
    // sent `Chrome (CAPTSHOP Gateway)` and been rejected with 400 bad-request.
    const platform = canonicalPairingPlatform(['CAPTSHOP Gateway', 'Chrome', '1.0.0']);
    assert.deepEqual(platform, { id: '1', display: 'Chrome (Mac OS)' });
    assert.ok(!platform.display.includes('CAPTSHOP'));
  });

  test('keeps canonical browser names and OS values', () => {
    assert.deepEqual(canonicalPairingPlatform(['Windows', 'Firefox', '120.0']), {
      id: '2',
      display: 'Firefox (Windows)',
    });
    assert.deepEqual(canonicalPairingPlatform(['Ubuntu', 'Safari', '17.0']), {
      id: '5',
      display: 'Safari (Ubuntu)',
    });
    assert.deepEqual(canonicalPairingPlatform(['Mac OS', 'Edge', '1.0']), {
      id: '6',
      display: 'Edge (Mac OS)',
    });
  });

  test('falls back to Chrome/Mac OS for unknown labels and OS names', () => {
    assert.deepEqual(canonicalPairingPlatform(['Some Custom OS', 'CustomBrowser', '9.9']), {
      id: '1',
      display: 'Chrome (Mac OS)',
    });
    // "Desktop" is a Baileys registration-only value; pairing codes need a real browser.
    assert.deepEqual(canonicalPairingPlatform(['Linux', 'Desktop', '1.0']), {
      id: '1',
      display: 'Chrome (Mac OS)',
    });
  });

  test('every normalization stays inside the values WhatsApp accepts', () => {
    const allowedDisplays = new Set([
      'Chrome (Mac OS)',
      'Chrome (Windows)',
      'Chrome (Ubuntu)',
      'Firefox (Mac OS)',
      'Firefox (Windows)',
      'Firefox (Ubuntu)',
      'IE (Mac OS)',
      'IE (Windows)',
      'IE (Ubuntu)',
      'Opera (Mac OS)',
      'Opera (Windows)',
      'Opera (Ubuntu)',
      'Safari (Mac OS)',
      'Safari (Windows)',
      'Safari (Ubuntu)',
      'Edge (Mac OS)',
      'Edge (Windows)',
      'Edge (Ubuntu)',
    ]);
    for (const os of ['Mac OS', 'Windows', 'Ubuntu', 'Linux', 'Android', 'FreeBSD']) {
      for (const browser of ['Chrome', 'Firefox', 'IE', 'Opera', 'Safari', 'Edge', 'Desktop', 'CAPTSHOP']) {
        const platform = canonicalPairingPlatform([os, browser, '1.0.0']);
        assert.ok(allowedDisplays.has(platform.display), `unexpected display: ${platform.display}`);
        assert.match(platform.id, /^[1-6]$/, `unexpected platform id: ${platform.id}`);
      }
    }
  });
});

describe('buildCompanionHelloNode', () => {
  test('sends the canonicalized platform id/display, never the custom label', async () => {
    const creds = freshCreds();
    const node = await buildCompanionHelloNode({
      creds,
      jid: '628123456789@s.whatsapp.net',
      platform: canonicalPairingPlatform(['CAPTSHOP Gateway', 'Chrome', '1.0.0']),
      pairingCode: 'ABCD1234',
      id: 'PAIR-1',
    });

    assert.equal(node.tag, 'iq');
    assert.equal(node.attrs.type, 'set');
    assert.equal(node.attrs.xmlns, 'md');
    assert.equal(node.attrs.id, 'PAIR-1');

    const reg = linkCodeNode(node);
    assert.equal(reg.attrs.stage, 'companion_hello');
    assert.equal(reg.attrs.jid, '628123456789@s.whatsapp.net');

    const display = childOf(reg, 'companion_platform_display');
    const platformId = childOf(reg, 'companion_platform_id');
    assert.equal(display?.content, 'Chrome (Mac OS)');
    assert.equal(platformId?.content, '1');

    // The wrapped ephemeral pub key is salt(32) + iv(16) + ciphertext.
    const wrapped = childOf(reg, 'link_code_pairing_wrapped_companion_ephemeral_pub');
    assert.ok(Buffer.isBuffer(wrapped?.content));
    assert.ok((wrapped?.content as Buffer).length > 48);
  });

  test('the encoded wire bytes carry the canonical display, not the custom label', async () => {
    const creds = freshCreds();
    const node = await buildCompanionHelloNode({
      creds,
      jid: '628123456789@s.whatsapp.net',
      platform: canonicalPairingPlatform(['CAPTSHOP Gateway', 'Chrome', '1.0.0']),
      pairingCode: 'ABCD1234',
      id: 'PAIR-WIRE',
    });

    // Encode/decode exactly as Baileys does when the node goes over the socket,
    // so this asserts the actual bytes rather than our in-memory object.
    const decoded = await decodeBinaryNode(encodeBinaryNode(node));
    const reg = linkCodeNode(decoded);
    assert.equal(reg.attrs.stage, 'companion_hello');
    assert.equal(textOf(childOf(reg, 'companion_platform_display')), 'Chrome (Mac OS)');
    assert.equal(textOf(childOf(reg, 'companion_platform_id')), '1');

    // The custom label must appear nowhere in the pairing stanza.
    const raw = JSON.stringify({
      attrs: reg.attrs,
      children: (reg.content as BinaryNode[]).map((child) => ({
        tag: child.tag,
        attrs: child.attrs,
        content: textOf(child),
      })),
    });
    assert.ok(!raw.includes('CAPTSHOP'), 'the custom browser label is never sent for pairing');
  });
});

describe('requestPairingCodeAwaitingResult', () => {
  test('an IQ 400 rejection surfaces to the caller and leaves auth unpaired', async () => {
    const creds = freshCreds();
    const logger = silentLogger();
    let saves = 0;

    const query = async (): Promise<unknown> => {
      throw badRequestError();
    };

    await assert.rejects(
      requestPairingCodeAwaitingResult({
        socket: { query },
        creds,
        browser: ['CAPTSHOP Gateway', 'Chrome', '1.0.0'],
        phoneE164: '628123456789',
        saveCreds: async () => {
          saves += 1;
        },
        logger,
      }),
      (error: Error) => {
        assert.equal(error.message, 'bad-request');
        return true;
      },
    );

    assert.equal(saves, 0, 'nothing is persisted on a rejected request');
    assert.equal(creds.me, undefined, 'no identity is stored');
    assert.equal(creds.pairingCode, undefined, 'the transient pairing code is rolled back');
    assert.equal(creds.registered, false, 'the session stays unpaired');

    // The failure is logged by code only — never the phone number or a code.
    const logged = logger.records().map((record) => JSON.stringify(record)).join('\n');
    assert.ok(logged.includes('pairing.request_failed'));
    assert.ok(!logged.includes('628123456789'), 'the phone number never reaches the log');
  });

  test('resolves the code only after the IQ result arrives', async () => {
    const creds = freshCreds();
    const logger = silentLogger();
    let resolveQuery: ((value: unknown) => void) | null = null;
    let saves = 0;

    const query = (): Promise<unknown> =>
      new Promise((resolve) => {
        resolveQuery = resolve;
      });

    const pending = requestPairingCodeAwaitingResult({
      socket: { query },
      creds,
      browser: ['CAPTSHOP Gateway', 'Chrome', '1.0.0'],
      phoneE164: '628123456789',
      saveCreds: async () => {
        saves += 1;
      },
      logger,
    });

    // Let the request reach `query()` (key derivation is async).
    await waitUntil(() => resolveQuery !== null, 'the companion_hello IQ to be sent');
    assert.ok(resolveQuery, 'the companion_hello IQ was sent');
    assert.equal(saves, 0, 'creds are not persisted before the server answers');

    let settled = false;
    void pending.then(() => {
      settled = true;
    });
    await new Promise((resolve) => setTimeout(resolve, 10));
    assert.equal(settled, false, 'no code is returned before the IQ result');

    (resolveQuery as unknown as (value: unknown) => void)({
      tag: 'iq',
      attrs: { type: 'result', id: 'PAIR-1' },
    });

    const code = await pending;
    assert.equal(code.length, 8, 'an 8-character Crockford pairing code');
    assert.match(code, /^[0-9A-Z]{8}$/);
    assert.equal(creds.me?.id, '628123456789@s.whatsapp.net');
    assert.equal(creds.pairingCode, code);
    assert.equal(saves, 1, 'creds are persisted once, after the server accepts');
  });

  test('a query timeout (undefined result) is a failure, not a code', async () => {
    const creds = freshCreds();
    const logger = silentLogger();
    let saves = 0;

    await assert.rejects(
      requestPairingCodeAwaitingResult({
        socket: { query: async () => undefined },
        creds,
        browser: ['CAPTSHOP Gateway', 'Chrome', '1.0.0'],
        phoneE164: '628123456789',
        saveCreds: async () => {
          saves += 1;
        },
        logger,
      }),
      (error: Error & { code?: string }) => {
        assert.equal(error.code, 'PAIRING_TIMEOUT');
        return true;
      },
    );

    assert.equal(saves, 0);
    assert.equal(creds.me, undefined);
    assert.equal(creds.pairingCode, undefined);
  });

  test('a transport error is a failure and restores prior creds state', async () => {
    const creds = freshCreds();
    const previousMe = { id: '628111222333@s.whatsapp.net', name: 'Previous' };
    creds.me = previousMe;
    const logger = silentLogger();

    await assert.rejects(
      requestPairingCodeAwaitingResult({
        socket: {
          query: async () => {
            throw new Error('connection closed');
          },
        },
        creds,
        browser: ['CAPTSHOP Gateway', 'Chrome', '1.0.0'],
        phoneE164: '628123456789',
        saveCreds: async () => {},
        logger,
      }),
      /connection closed/,
    );

    assert.equal(creds.me, previousMe, 'the pre-existing identity is restored');
    assert.equal(creds.pairingCode, undefined);
  });
});

describe('WhatsAppConnection pairing gate', () => {
  test('waits for the pairing handshake before sending companion_hello', async () => {
    const harness = await startHarness();
    try {
      const socket = harness.socket();
      const pending = harness.gateway.connection.requestPairingCode('628123456789', {
        readyTimeoutMs: 2_000,
      });
      pending.catch(() => {});

      // Nothing is sent before the server starts the handshake.
      await new Promise((resolve) => setTimeout(resolve, 20));
      assert.equal(socket.queriedIqs.length, 0);
      assert.equal(harness.gateway.connection.isPairingReady(), false);

      socket.emitConnection({ qr: 'https://wa.me/settings/linked_devices#ref' });

      const code = await pending;
      assert.equal(code.length, 8);
      assert.equal(socket.queriedIqs.length, 1);
      const iq = socket.queriedIqs[0];
      assert.ok(iq);
      assert.equal(linkCodeNode(iq).attrs.stage, 'companion_hello');
    } finally {
      await harness.close();
    }
  });

  test('an IQ 400 leaves the persisted auth state unpaired and returns no code', async () => {
    const harness = await startHarness();
    try {
      const socket = harness.socket();
      socket.emitConnection({ qr: 'https://wa.me/settings/linked_devices#ref' });

      // Exactly what Baileys raises for <iq type='error'><error code='400'/>.
      socket.failNextQuery(badRequestError());

      await assert.rejects(
        harness.gateway.connection.requestPairingCode('628123456789', { readyTimeoutMs: 2_000 }),
        /bad-request/,
      );

      // Nothing was persisted: the auth store still reports an unpaired session.
      assert.equal(harness.gateway.auth.isRegistered(), false);
      assert.equal(harness.gateway.auth.readCreds().me, undefined);
      assert.equal(harness.gateway.auth.readCreds().pairingCode, undefined);
      assert.equal(harness.gateway.session.get().state, 'connecting');
    } finally {
      await harness.close();
    }
  });

  test('fails closed when the handshake never starts', async () => {
    const harness = await startHarness();
    try {
      await assert.rejects(
        harness.gateway.connection.requestPairingCode('628123456789', { readyTimeoutMs: 50 }),
        (error: Error & { code?: string }) => {
          assert.equal(error.code, 'PAIRING_INTERRUPTED');
          assert.equal(isRetryablePairingError(error), true);
          return true;
        },
      );
      assert.equal(harness.gateway.auth.isRegistered(), false);
    } finally {
      await harness.close();
    }
  });

  test('a second concurrent pairing request is rejected, not queued', async () => {
    const harness = await startHarness();
    try {
      const socket = harness.socket();
      socket.emitConnection({ qr: 'https://wa.me/settings/linked_devices#ref' });

      // Never resolves: the first request stays in flight.
      let release: (() => void) | null = null;
      let queries = 0;
      const originalQuery = socket.query;
      socket.query = ((node: BinaryNode, timeoutMs?: number) => {
        void node;
        void timeoutMs;
        queries += 1;
        return new Promise((resolve) => {
          release = () => resolve({ tag: 'iq', attrs: { type: 'result', id: '' } });
        });
      }) as typeof socket.query;

      const first = harness.gateway.connection.requestPairingCode('628123456789', {
        readyTimeoutMs: 2_000,
      });
      first.catch(() => {});

      await assert.rejects(
        harness.gateway.connection.requestPairingCode('628123456789', { readyTimeoutMs: 2_000 }),
        (error: Error & { code?: string }) => {
          assert.equal(error.code, 'PAIRING_IN_PROGRESS');
          return true;
        },
      );

      await waitUntil(() => release !== null, 'the first companion_hello IQ to be sent');
      (release as unknown as () => void)();
      const code = await first;
      assert.equal(code.length, 8);
      assert.equal(queries, 1, 'only one companion_hello IQ reached the wire');
      socket.query = originalQuery;
    } finally {
      await harness.close();
    }
  });

  test('the pairing gate re-arms after a disconnect', async () => {
    const harness = await startHarness();
    try {
      const socket = harness.socket();
      socket.emitConnection({ qr: 'https://wa.me/settings/linked_devices#ref' });
      assert.equal(harness.gateway.connection.isPairingReady(), true);

      socket.emitConnection({
        connection: 'close',
        lastDisconnect: {
          error: Object.assign(new Error('gone'), { output: { statusCode: 428 } }),
        },
      });
      assert.equal(harness.gateway.connection.isPairingReady(), false);
    } finally {
      await harness.close();
    }
  });

  test('a server rejection is not retryable', async () => {
    const harness = await startHarness();
    try {
      const socket = harness.socket();
      socket.emitConnection({ qr: 'https://wa.me/settings/linked_devices#ref' });
      socket.failNextQuery(badRequestError());

      await assert.rejects(
        harness.gateway.connection.requestPairingCode('628123456789', { readyTimeoutMs: 2_000 }),
        (error: Error) => {
          assert.equal(isRetryablePairingError(error), false, 'a 400 must never be retried');
          return true;
        },
      );
    } finally {
      await harness.close();
    }
  });
});

describe('pairing CLI flow', () => {
  function session(overrides: Partial<Parameters<typeof createPairingSession>[0]> = {}) {
    const out: string[] = [];
    const err: string[] = [];
    const created = createPairingSession({
      timeoutSeconds: 5,
      stdout: (text) => out.push(text),
      stderr: (text) => err.push(text),
      connect: async () => {},
      requestPairingCode: async () => 'ABCD1234',
      stop: async () => {},
      ...overrides,
    });
    return {
      created,
      stdout: () => out.join(''),
      stderr: () => err.join(''),
    };
  }

  test('a rejected pairing request prints no code and exits non-zero', async () => {
    const { created, stdout, stderr } = session({
      requestPairingCode: async () => {
        throw badRequestError();
      },
    });

    assert.equal(await created.run(), 1);
    assert.ok(!stdout().includes('pairing code:'), 'no code banner is ever shown');
    assert.ok(!stdout().includes('ABCD1234'));
    assert.ok(stderr().includes('could not obtain a pairing code'));
    assert.ok(stderr().includes('bad-request'));
  });

  test('prints the code once and exits 0 only after the session connects', async () => {
    const { created, stdout } = session();
    const running = created.run();
    // Give the flow a tick to print the code.
    await new Promise((resolve) => setTimeout(resolve, 10));
    assert.ok(stdout().includes('pairing code: ABCD1234'));
    created.events.connected();
    assert.equal(await running, 0);
    assert.equal(stdout().split('ABCD1234').length - 1, 1, 'the code is printed exactly once');
  });

  test('a logged-out session is a failure', async () => {
    const { created, stderr } = session();
    const running = created.run();
    await new Promise((resolve) => setTimeout(resolve, 10));
    created.events.loggedOut();
    assert.equal(await running, 1);
    assert.ok(stderr().includes('logged out'));
  });

  test('a stalled request is bounded by the timeout and prints no code', async () => {
    const { created, stdout, stderr } = session({
      timeoutSeconds: 1,
      requestPairingCode: () => new Promise<string>(() => {}),
    });

    assert.equal(await created.run(), 1);
    assert.ok(!stdout().includes('pairing code:'), 'no code banner is ever shown');
    assert.ok(stderr().includes('timed out'));
  });

  test('times out when the phone never confirms', async () => {
    const { created, stderr } = session({ timeoutSeconds: 1 });
    assert.equal(await created.run(), 1);
    assert.ok(stderr().includes('timed out'));
  });
});
