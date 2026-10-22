import { createHash } from 'node:crypto';

// Temporary application patch for the inspected @inertiajs/core 2.3.28 ESM
// encryption section. Dependency updates MUST trigger review, never silently
// bypass this guard. No installed dependency files are edited.
const expectedHash = '423d08323c540480e62bdd84e5e78003eb100069634c60504331c4824827cfc6';
const startMarker = '// src/encryption.ts';
const endMarker = '// src/prefetched.ts';

export function patchHistoryEncryption(source) {
    const code = source.replace(/\r\n/g, '\n');
    const start = code.indexOf(startMarker);
    const end = code.indexOf(endMarker, start);
    if (start < 0 || end < 0 || createHash('sha256').update(code.slice(start, end)).digest('hex') !== expectedHash) {
        throw new Error('Inertia history encryption source changed. Review the unique-nonce patch before building.');
    }
    // KH v1 envelope: 4-byte format header, independent 12-byte random nonce,
    // then the framework's AES-GCM ciphertext/tag. Each write gets a new nonce.
    const encrypt = `var encryptHistory = async (data) => {
  if (typeof window === "undefined" || !window.crypto?.subtle) throw new Error("Secure history requires Web Crypto.");
  const iv = window.crypto.getRandomValues(new Uint8Array(12));
  const key = await getOrCreateKey(await getKeyFromSessionStorage());
  if (!key) throw new Error("Unable to encrypt history");
  const encrypted = await encryptData(iv, key, data);
  const envelope = new Uint8Array(16 + encrypted.byteLength);
  envelope.set([75, 72, 1, 0]);
  envelope.set(iv, 4);
  envelope.set(new Uint8Array(encrypted), 16);
  return envelope.buffer;
};
`;
    const decrypt = `var decryptHistory = async (data) => {
  if (typeof window === "undefined" || !window.crypto?.subtle) throw new Error("Secure history requires Web Crypto.");
  if (!(data instanceof ArrayBuffer) || data.byteLength < 32) throw new Error("Invalid encrypted history.");
  const envelope = new Uint8Array(data);
  if (envelope[0] !== 75 || envelope[1] !== 72 || envelope[2] !== 1 || envelope[3] !== 0) throw new Error("Unsupported encrypted history.");
  const key = await getKeyFromSessionStorage();
  if (!key) throw new Error("Unable to decrypt history");
  return decryptData(envelope.slice(4, 16), key, envelope.slice(16).buffer);
};
`;
    const section = code.slice(start, end)
        .replace(/var encryptHistory = async \(data\) => \{[\s\S]*?(?=var historySessionStorageKeys)/, encrypt)
        .replace(/var decryptHistory = async \(data\) => \{[\s\S]*?(?=var encryptData)/, decrypt);
    return code.slice(0, start) + section + code.slice(end);
}

export default function inertiaHistoryEncryption() {
    let applied = false;
    let building = false;
    return {
        name: 'kalinga-inertia-history-unique-nonce',
        enforce: 'pre',
        configResolved(config) { building = config.command === 'build'; },
        transform(code, id) {
            if (!id.replaceAll('\\', '/').split('?')[0].endsWith('/node_modules/@inertiajs/core/dist/index.esm.js')) return null;
            const patched = patchHistoryEncryption(code);
            applied = true;
            return { code: patched, map: null };
        },
        buildEnd(error) {
            if (building && !error && !applied) this.error('The reviewed Inertia history encryption patch was not applied.');
        },
    };
}
