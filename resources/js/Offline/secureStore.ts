import { reviseDraft, promoteDraft, type IntakeDraft } from './intakeDraft';
import type { OfflineOperation } from './offlineTypes';

type Ciphertext = { iv: string; value: string };
type Metadata = { salt: string; verifier: Ciphertext; version: 1; device_id?: string };
const DATABASE = 'kalinga-secure-offline';
const STORE = 'encrypted-records';
const META = 'metadata';
let key: CryptoKey | null = null;
/** Account that provisioned this workspace, sealed inside the encrypted verifier (null for older workspaces). */
let owner: number | null = null;
let keyEpoch = 0;
const MAX_QUEUE_COMMIT_ATTEMPTS = 20;

function bytesToBase64(bytes: Uint8Array) {
    let binary = '';
    // Avoid argument-count overflow for a large encrypted pack or queue.
    for (let offset = 0; offset < bytes.length; offset += 32768) binary += String.fromCharCode(...bytes.subarray(offset, offset + 32768));
    return btoa(binary);
}
function base64ToBytes(value: string) { return Uint8Array.from(atob(value), (character) => character.charCodeAt(0)); }
function asBuffer(bytes: Uint8Array): ArrayBuffer { return bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) as ArrayBuffer; }
function open(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DATABASE, 1);
        request.onupgradeneeded = () => {
            request.result.createObjectStore(STORE);
            request.result.createObjectStore(META);
        };
        request.onsuccess = () => {
            request.result.onversionchange = () => request.result.close();
            resolve(request.result);
        };
        request.onerror = () => reject(request.error);
        request.onblocked = () => reject(new Error('Close other Kalinga tabs and try again.'));
    });
}

async function transaction<T>(store: string, mode: IDBTransactionMode, operation: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    const database = await open();
    return new Promise((resolve, reject) => {
        try {
            const active = database.transaction(store, mode);
            let result: T;
            const request = operation(active.objectStore(store));
            request.onsuccess = () => { result = request.result; };
            active.oncomplete = () => { database.close(); resolve(result); };
            active.onerror = active.onabort = () => { database.close(); reject(active.error ?? new Error('Encrypted storage could not be updated.')); };
        } catch (error) { database.close(); reject(error); }
    });
}

async function getRaw<T>(store: string, id: string): Promise<T | undefined> {
    return transaction(store, 'readonly', records => records.get(id));
}
async function putRaw(store: string, id: string, value: unknown): Promise<void> {
    await transaction(store, 'readwrite', records => records.put(value, id));
}
async function derive(passphrase: string, salt: Uint8Array) { const material = await crypto.subtle.importKey('raw', asBuffer(new TextEncoder().encode(passphrase)), 'PBKDF2', false, ['deriveKey']); return crypto.subtle.deriveKey({ name: 'PBKDF2', salt: asBuffer(salt), iterations: 310000, hash: 'SHA-256' }, material, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']); }
async function encrypt(value: unknown): Promise<Ciphertext> { if (!key) throw new Error('Unlock the offline workspace first.'); const iv = crypto.getRandomValues(new Uint8Array(12)); const plain = new TextEncoder().encode(JSON.stringify(value)); const encrypted = await crypto.subtle.encrypt({ name: 'AES-GCM', iv: asBuffer(iv) }, key, asBuffer(plain)); return { iv: bytesToBase64(iv), value: bytesToBase64(new Uint8Array(encrypted)) }; }
async function decrypt<T>(ciphertext: Ciphertext): Promise<T> { if (!key) throw new Error('Unlock the offline workspace first.'); const plain = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: asBuffer(base64ToBytes(ciphertext.iv)) }, key, asBuffer(base64ToBytes(ciphertext.value))); return JSON.parse(new TextDecoder().decode(plain)); }

export async function configured() { return Boolean(await getRaw<Metadata>(META, 'offline-pin')); }
export async function deviceId() { const metadata = await getRaw<Metadata>(META, 'offline-pin'); if (!metadata) throw new Error('Set up this device before downloading case data.'); if (!metadata.device_id) { metadata.device_id = crypto.randomUUID(); await putRaw(META, 'offline-pin', metadata); } return metadata.device_id; }
export async function setPassphrase(passphrase: string, ownerUserId?: number) { keyEpoch++; if (passphrase.length < 12) throw new Error('Use at least 12 characters for the offline passphrase.'); const salt = crypto.getRandomValues(new Uint8Array(16)); key = await derive(passphrase, salt); owner = ownerUserId && Number.isSafeInteger(ownerUserId) ? ownerUserId : null; await putRaw(META, 'offline-pin', { salt: bytesToBase64(salt), verifier: await encrypt({ purpose: 'kalinga-offline-workspace', version: 1, user_id: owner }), version: 1, device_id: crypto.randomUUID() } satisfies Metadata); }
export async function unlock(passphrase: string) { keyEpoch++; const metadata = await getRaw<Metadata>(META, 'offline-pin'); if (!metadata) throw new Error('This device has not been provisioned for offline work.'); key = await derive(passphrase, base64ToBytes(metadata.salt)); try { const verifier = await decrypt<{ purpose: string; user_id?: number | null }>(metadata.verifier); if (verifier.purpose !== 'kalinga-offline-workspace') throw new Error(); owner = typeof verifier.user_id === 'number' ? verifier.user_id : null; } catch { key = null; owner = null; throw new Error('Incorrect offline passphrase.'); } }
export function lock() { key = null; owner = null; keyEpoch++; }
/** Owner sealed at setup; lets new intakes be saved without a case pack (GIDA). */
export function workspaceOwnerId(): number | null { return key ? owner : null; }
export function unlocked() { return Boolean(key); }
export async function savePack(pack: unknown) { await putRaw(STORE, 'case-pack', await encrypt(pack)); }
export async function loadPack<T>() { const record = await getRaw<Ciphertext>(STORE, 'case-pack'); return record ? decrypt<T>(record) : null; }
export async function loadQueue(): Promise<OfflineOperation[]> { const record = await getRaw<Ciphertext>(STORE, 'operations'); return record ? decrypt<OfflineOperation[]>(record) : []; }

// Encrypt outside the transaction, then atomically compare ciphertext before
// replacing it. A competing tab/write causes a fresh decrypt/merge/retry.
async function mutateQueue(change: (items: OfflineOperation[]) => OfflineOperation[]): Promise<void> {
    if (!key) throw new Error('Unlock the offline workspace first.');
    const epoch = keyEpoch;
    const metadata = await getRaw<Metadata>(META, 'offline-pin');
    if (!metadata) throw new Error('The offline device is no longer configured.');
    for (let attempt = 0; attempt < MAX_QUEUE_COMMIT_ATTEMPTS; attempt++) {
        const previous = await getRaw<Ciphertext>(STORE, 'operations');
        const items = previous ? await decrypt<OfflineOperation[]>(previous) : [];
        const next = await encrypt(change(items));
        if (!key || epoch !== keyEpoch) throw new Error('The offline workspace was locked.');
        const database = await open();
        const committed = await new Promise<boolean>((resolve, reject) => {
            const active = database.transaction([STORE, META], 'readwrite');
            let swapped = false;
            const records = active.objectStore(STORE);
            const read = records.get('operations');
            const ownerRecord = active.objectStore(META).get('offline-pin');
            ownerRecord.onsuccess = () => {
                const current = read.result as Ciphertext | undefined;
                const currentMetadata = ownerRecord.result as Metadata | undefined;
                if (!key || epoch !== keyEpoch || currentMetadata?.verifier.value !== metadata.verifier.value) { active.abort(); return; }
                if (current?.value === previous?.value && current?.iv === previous?.iv) {
                    try { records.put(next, 'operations'); swapped = true; }
                    catch (error) { active.abort(); reject(error); }
                }
            };
            active.oncomplete = () => { database.close(); resolve(swapped); };
            active.onerror = active.onabort = () => { database.close(); reject(active.error ?? new Error('Encrypted queue update was interrupted.')); };
        });
        if (committed) return;
    }
    throw new Error('The encrypted queue is busy. Keep this page open and retry.');
}

export async function addOperation(operation: OfflineOperation) {
    await mutateQueue(items => {
        const existing = items.find(item => item.local_id === operation.local_id);
        if (existing) {
            if (JSON.stringify(existing) !== JSON.stringify(operation)) throw new Error('This queue identifier already belongs to another operation.');
            return items;
        }
        return [...items, operation];
    });
}

export async function updateOperation(localId: string, update: (item: OfflineOperation) => OfflineOperation) {
    await mutateQueue(items => items.map(item => item.local_id === localId ? update(item) : item));
}

export async function removeOperation(localId: string) {
    await mutateQueue(items => items.filter(item => item.local_id !== localId));
}

export async function recordSyncResult(sent: OfflineOperation, status: 'synced' | 'failed' | 'conflict', errorMessage?: string) {
    await updateOperation(sent.local_id, current => {
        if (current.sync_status === 'synced') return current;
        // Do not overwrite a local retry/edit made after this request started.
        if (JSON.stringify(current) !== JSON.stringify(sent)) return current;
        return { ...current, payload: status === 'synced' ? {} : current.payload,
            sync_status: status, retry_count: status === 'synced' ? current.retry_count : current.retry_count + 1,
            error_message: errorMessage };
    });
}

export async function clearSecureWorkspace() { lock(); await new Promise<void>((resolve, reject) => { const request = indexedDB.deleteDatabase(DATABASE); request.onsuccess = () => resolve(); request.onerror = () => reject(request.error); request.onblocked = () => reject(new Error('Close other Kalinga tabs and reload to finish clearing encrypted storage.')); }); }



// Drafts and queue commit together. Crypto stays outside IndexedDB transactions;
// ciphertext comparison also covers the authorizing pack and workspace metadata.
export async function loadIntakeDrafts(userId: number): Promise<IntakeDraft[]> {
    const pack = await loadPack<{ user_id: number; expires_at?: string }>();
    const raw = await getRaw<Ciphertext>(STORE, 'intake-drafts');
    if (!raw) return [];
    const packOwned = Boolean(pack && pack.user_id === userId && pack.expires_at && Date.parse(pack.expires_at) > Date.now());
    if (pack && pack.user_id !== userId) throw new Error('The saved drafts belong to another workspace.');
    // Without a current pack, drafts open only for the account sealed in the workspace at setup.
    if (!packOwned && owner !== userId) return [];
    const metadata = await getRaw<Metadata>(META, 'offline-pin');
    const drafts = await decrypt<IntakeDraft[]>(raw);
    if (drafts.some(item => item.user_id !== userId || item.device_id !== metadata?.device_id)) throw new Error('The saved drafts belong to another workspace.');
    return drafts.filter(item => item.state === 'draft');
}

async function mutateIntake(userId: number, change: (drafts: IntakeDraft[], queue: OfflineOperation[], device: string) => { drafts: IntakeDraft[]; queue: OfflineOperation[] }): Promise<void> {
    const epoch = keyEpoch;
    const metadata = await getRaw<Metadata>(META, 'offline-pin');
    if (!key || !metadata?.device_id) throw new Error('Unlock the authorized offline workspace first.');
    const ids = ['intake-drafts', 'operations', 'case-pack'];
    for (let attempt = 0; attempt < MAX_QUEUE_COMMIT_ATTEMPTS; attempt++) {
        const previous = await Promise.all(ids.map(id => getRaw<Ciphertext>(STORE, id)));
        const pack = previous[2] ? await decrypt<{ user_id: number; expires_at?: string }>(previous[2]) : null;
        if (pack && pack.user_id !== userId) throw new Error('The saved drafts belong to another workspace.');
        const packOwned = Boolean(pack && pack.expires_at && Date.parse(pack.expires_at) > Date.now());
        if (!packOwned && owner !== userId) throw new Error('Refresh the authorized case pack before saving this draft.');
        const drafts = previous[0] ? await decrypt<IntakeDraft[]>(previous[0]) : [];
        if (drafts.some(item => item.user_id !== userId || item.device_id !== metadata.device_id)) throw new Error('The saved drafts belong to another workspace.');
        const queue = previous[1] ? await decrypt<OfflineOperation[]>(previous[1]) : [];
        const changed = change(drafts, queue, metadata.device_id);
        const next = await Promise.all([encrypt(changed.drafts), encrypt(changed.queue)]);
        if (!key || epoch !== keyEpoch) throw new Error('The workspace was locked.');
        const database = await open();
        const committed = await new Promise<boolean>((resolve, reject) => {
            const active = database.transaction([STORE, META], 'readwrite');
            const records = active.objectStore(STORE);
            const reads = ids.map(id => records.get(id));
            const ownerRecord = active.objectStore(META).get('offline-pin');
            let swapped = false;
            ownerRecord.onsuccess = () => {
                if (!key || epoch !== keyEpoch || ownerRecord.result?.verifier.value !== metadata.verifier.value || (packOwned && !(Date.parse(pack!.expires_at!) > Date.now()))) { active.abort(); return; }
                if (reads.every((read, index) => read.result?.iv === previous[index]?.iv && read.result?.value === previous[index]?.value)) {
                    try { records.put(next[0], ids[0]); records.put(next[1], ids[1]); swapped = true; }
                    catch (error) { active.abort(); reject(error); }
                }
            };
            active.oncomplete = () => { database.close(); resolve(swapped); };
            active.onerror = active.onabort = () => { database.close(); reject(active.error ?? new Error('Encrypted draft save was interrupted.')); };
        });
        if (committed) return;
    }
    throw new Error('The encrypted workspace is busy. Keep this page open and retry.');
}

export async function saveIntakeDraft(id: string, userId: number, revision: number, payload: Record<string, unknown>): Promise<void> {
    await mutateIntake(userId, (drafts, queue, device) => {
        const next = reviseDraft(drafts.find(item => item.id === id), id, userId, device, revision, payload);
        return { drafts: [...drafts.filter(item => item.id !== id), next], queue };
    });
}
export async function promoteIntakeDraft(id: string, userId: number, revision: number, payload: Record<string, unknown>): Promise<void> {
    const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(JSON.stringify(payload)));
    const hash = bytesToBase64(new Uint8Array(bytes));
    await mutateIntake(userId, (drafts, queue, device) => promoteDraft(drafts, queue, id, userId, device, revision, payload, hash));
}
