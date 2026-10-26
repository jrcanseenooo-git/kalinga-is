import { expect, test } from '@playwright/test';
import { login } from './helpers';

test.describe('disconnected field durability', () => {
    test.use({ serviceWorkers: 'allow' });

    test('anonymous login does not provision a protected field shell', async ({ page }) => {
        const protectedRequests: string[] = [];
        page.on('request', request => { if (new URL(request.url()).pathname === '/offline-workspace') protectedRequests.push(request.url()); });
        await page.goto('/login');
        await page.waitForLoadState('load');
        await expect(page.getByRole('heading', { name: 'Login account', exact: true })).toBeVisible();
        expect(await page.evaluate(async () => (await navigator.serviceWorker.getRegistrations()).length)).toBe(0);
        expect(protectedRequests).toEqual([]);
    });

    test('saved encrypted note survives offline reload and reconnects exactly once', async ({ page, context }) => {
        await login(page);
        await page.goto('/offline-workspace');
        await page.getByLabel('Offline passphrase').fill('field-device-passphrase');
        await page.getByLabel('Confirm passphrase').fill('field-device-passphrase');
        await page.getByRole('button', { name: 'Set up encrypted workspace' }).click();
        await page.getByRole('button', { name: 'Download case pack' }).click();
        await expect(page.getByText(/authorized case record\(s\) encrypted/i)).toBeVisible();
        await expect.poll(() => page.evaluate(async () => (await navigator.serviceWorker.getRegistrations())
            .map(registration => registration.active?.state ?? registration.installing?.state ?? 'waiting')),
            { message: 'An authorized field service worker must activate before network disconnection', timeout: 30_000 }).toContain('activated');
        await expect.poll(() => page.evaluate(() => Boolean(navigator.serviceWorker.controller)),
            { message: 'The field page must be controlled before offline reload', timeout: 10_000 }).toBe(true);
        await expect.poll(() => page.evaluate(async () => Boolean(await caches.match(new URL('/offline-workspace', location.origin).href)))).toBe(true);
        const cachedShell = await page.evaluate(async () => (await caches.match(new URL('/offline-workspace', location.origin).href))!.text());
        expect(cachedShell).not.toContain('Fictional presentation cases, tasks, referrals');
        await context.setOffline(true);
        await page.getByLabel('Case note', { exact: true }).fill('Synthetic disconnected durability note');
        await page.getByRole('button', { name: 'Save encrypted update on this device' }).click();
        const queue = page.getByRole('heading', { name: 'Local encrypted queue', exact: true }).locator('..').locator('..');
        await expect(queue).toContainText('pending');
        await page.reload();
        await page.getByLabel('Offline passphrase').fill('field-device-passphrase');
        await page.getByRole('button', { name: 'Unlock workspace', exact: true }).click();
        await expect(queue).toContainText('pending');
        let syncPosts = 0;
        page.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).pathname === '/sync') syncPosts++; });
        await context.setOffline(false);
        await expect(queue).toContainText('synced', { timeout: 20_000 });
        expect(syncPosts).toBe(1);
        await page.reload();
        await page.getByLabel('Offline passphrase').fill('field-device-passphrase');
        await page.getByRole('button', { name: 'Unlock workspace', exact: true }).click();
        await expect(queue).toContainText('synced');
        expect(syncPosts).toBe(1);
    });
});

test('offline workspace encrypts an authorized pack in IndexedDB', async ({ page }) => {
    await login(page);
    await page.goto('/offline-workspace');
    await page.getByLabel('Offline passphrase').fill('field-device-passphrase');
    await page.getByLabel('Confirm passphrase').fill('field-device-passphrase');
    await page.getByRole('button', { name: 'Set up encrypted workspace' }).click();
    await page.getByRole('button', { name: 'Download case pack' }).click();
    await expect(page.getByText(/authorized case record\(s\) encrypted/i)).toBeVisible();

    const stored = await page.evaluate(async () => {
        const request = indexedDB.open('kalinga-secure-offline');
        const database = await new Promise<IDBDatabase>((resolve, reject) => {
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
        const transaction = database.transaction('encrypted-records');
        const get = transaction.objectStore('encrypted-records').get('case-pack');
        return new Promise<unknown>((resolve, reject) => {
            get.onsuccess = () => resolve(get.result);
            get.onerror = () => reject(get.error);
        });
    });

    expect(stored).toEqual(expect.objectContaining({ iv: expect.any(String), value: expect.any(String) }));
    expect(JSON.stringify(stored)).not.toContain('first_name');
});

test('expired pack locks case details but keeps new intake available', async ({ page }) => {
    await login(page);
    await page.route('**/offline-workspace/pack', async route => {
        const response = await route.fetch();
        const body = await response.json();
        body.expires_at = new Date(Date.now() - 60_000).toISOString();
        await route.fulfill({ response, json: body });
    });
    await page.goto('/offline-workspace');
    await page.getByLabel('Offline passphrase').fill('field-device-passphrase');
    await page.getByLabel('Confirm passphrase').fill('field-device-passphrase');
    await page.getByRole('button', { name: 'Set up encrypted workspace' }).click();
    await page.getByRole('button', { name: 'Download case pack' }).click();
    await expect(page.getByText(/expired case details are locked/i)).toBeVisible();
    await expect(page.getByText(/current authorized pack/i)).toBeVisible();
    // Case updates are locked; a new intake (no case data needed) stays available for GIDA work.
    await expect(page.getByRole('button', { name: 'Save encrypted update on this device' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Continue', exact: true })).toBeVisible();
    await expect(page.getByRole('checkbox', { name: /I gave the privacy notice/ })).toBeVisible();
});

test('confirmed device revocation clears encrypted storage and locks the workspace', async ({ page }) => {
    await login(page);
    await page.goto('/offline-workspace');
    await page.getByLabel('Offline passphrase').fill('field-device-passphrase');
    await page.getByLabel('Confirm passphrase').fill('field-device-passphrase');
    await page.getByRole('button', { name: 'Set up encrypted workspace' }).click();
    await page.getByRole('button', { name: 'Download case pack' }).click();
    await expect(page.getByText(/authorized case record\(s\) encrypted/i)).toBeVisible();
    await page.getByRole('button', { name: 'Lock workspace', exact: true }).click();
    await page.route('**/offline-workspace/device-status', route => route.fulfill({ json: { revoked: true } }));
    await page.getByLabel('Offline passphrase').fill('field-device-passphrase');
    await page.getByRole('button', { name: 'Unlock workspace', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Set up encrypted workspace', exact: true })).toBeVisible();
    const databases = await page.evaluate(() => indexedDB.databases());
    expect(databases.some(database => database.name === 'kalinga-secure-offline')).toBe(false);
    await expect(page.getByLabel('Case note', { exact: true })).toHaveCount(0);
});

test('quota failure retains the field note and permits a single recovered save', async ({ page }) => {
    await login(page);
    await page.goto('/offline-workspace');
    await page.getByLabel('Offline passphrase').fill('field-device-passphrase');
    await page.getByLabel('Confirm passphrase').fill('field-device-passphrase');
    await page.getByRole('button', { name: 'Set up encrypted workspace' }).click();
    await page.getByRole('button', { name: 'Download case pack' }).click();
    await expect(page.getByText(/authorized case record\(s\) encrypted/i)).toBeVisible();
    await page.evaluate(() => {
        document.documentElement.dataset.syntheticQuota = 'exhausted';
        const original = IDBObjectStore.prototype.put;
        IDBObjectStore.prototype.put = function (this: IDBObjectStore, value: unknown, key?: IDBValidKey) {
            if (this.name === 'encrypted-records' && key === 'operations' && document.documentElement.dataset.syntheticQuota === 'exhausted') throw new DOMException('Synthetic quota failure', 'QuotaExceededError');
            return original.call(this, value, key);
        };
    });
    const note = page.getByLabel('Case note', { exact: true });
    await note.fill('Synthetic note retained after quota failure');
    await page.getByRole('button', { name: 'Save encrypted update on this device' }).click();
    await expect(page.getByRole('alert')).toContainText('Could not save on this device');
    await expect(note).toHaveValue('Synthetic note retained after quota failure');
    await expect(page.getByRole('heading', { name: 'Local encrypted queue', exact: true })).toHaveCount(0);
    await page.evaluate(() => { document.documentElement.dataset.syntheticQuota = 'recovered'; });
    await page.getByRole('button', { name: 'Save encrypted update on this device' }).click();
    await expect(note).toHaveValue('');
    const queue = page.getByRole('heading', { name: 'Local encrypted queue', exact: true }).locator('..').locator('..');
    await expect(queue.locator('span').filter({ hasText: /^pending$/ })).toHaveCount(1);
});

