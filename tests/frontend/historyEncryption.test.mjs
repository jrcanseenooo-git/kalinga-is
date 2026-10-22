import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { webcrypto } from 'node:crypto';
import { runInNewContext } from 'node:vm';
import { patchHistoryEncryption } from '../../build/inertia-history.mjs';

const source = readFileSync(new URL('../../node_modules/@inertiajs/core/dist/index.esm.js', import.meta.url), 'utf8');
function runtime(crypto = webcrypto) {
    const storage = new Map();
    const patched = patchHistoryEncryption(source);
    const section = patched.slice(patched.indexOf('// src/encryption.ts'), patched.indexOf('// src/prefetched.ts'));
    const methods = runInNewContext(`${section}; ({ encryptHistory, decryptHistory });`, {
        window: { crypto }, Uint8Array, ArrayBuffer, TextEncoder, TextDecoder,
        SessionStorage: { get: key => storage.get(key), set: (key, value) => storage.set(key, value) },
    });
    return { ...methods, storage };
}

test('actual transformed framework uses fresh nonces and decrypts earlier history writes', async () => {
    const api = runtime();
    const payload = { props: { narrative: 'Synthetic encrypted-history test' } };
    const first = await api.encryptHistory(payload);
    const second = await api.encryptHistory(payload);
    assert.notDeepEqual(new Uint8Array(first).slice(4, 16), new Uint8Array(second).slice(4, 16));
    assert.deepEqual(JSON.parse(JSON.stringify(await api.decryptHistory(first))), payload);
    assert.deepEqual(JSON.parse(JSON.stringify(await api.decryptHistory(second))), payload);
    assert.equal(new TextDecoder().decode(first).includes(payload.props.narrative), false);
});

test('corrupt, truncated and unknown envelopes fail without plaintext fallback', async () => {
    const api = runtime();
    const buffer = await api.encryptHistory({ synthetic: true });
    const corrupt = buffer.slice(0); new Uint8Array(corrupt)[20] ^= 1;
    await assert.rejects(api.decryptHistory(corrupt));
    await assert.rejects(api.decryptHistory(buffer.slice(0, 20)));
    const unknown = buffer.slice(0); new Uint8Array(unknown)[2] = 99;
    await assert.rejects(api.decryptHistory(unknown));
});

test('cleared keys and unavailable Web Crypto cannot restore sensitive history', async () => {
    const api = runtime();
    const buffer = await api.encryptHistory({ synthetic: true });
    api.storage.clear();
    await assert.rejects(api.decryptHistory(buffer));
    const unsupported = runtime({ getRandomValues: webcrypto.getRandomValues.bind(webcrypto) });
    await assert.rejects(unsupported.encryptHistory({ synthetic: true }));
    await assert.rejects(unsupported.decryptHistory(buffer));
});

test('dependency encryption changes fail the build patch closed', () => {
    assert.throws(() => patchHistoryEncryption(source.replace('const iv = getIv();', 'const iv = changedImplementation();')), /source changed/);
    assert.throws(() => patchHistoryEncryption('unrecognized dependency module'), /source changed/);
});
