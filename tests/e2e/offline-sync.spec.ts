import { expect, test, type Page } from '@playwright/test';
import { login } from './helpers';
import AxeBuilder from '@axe-core/playwright';

test.describe('controlled field shell', () => {
    test.use({ serviceWorkers: 'allow' });

    test('field preparation can recover explicitly without changing the encrypted queue', async ({ page, context }) => {
        await context.addInitScript(() => {
            const original = ServiceWorkerContainer.prototype.register;
            ServiceWorkerContainer.prototype.register = function (this: ServiceWorkerContainer, script: string | URL, options?: RegistrationOptions) {
                if (document.documentElement.dataset.syntheticFieldPreparation !== 'recovered') return Promise.reject(new Error('Synthetic preparation unavailable'));
                return original.call(this, script, options);
            };
        });
        await provision(page);
        await saveNote(page, 'Synthetic queue preserved during field preparation');
        await expect(page.getByText(/Offline reload is not ready/)).toBeVisible();
        await expect(page.getByText('Field app ready', { exact: true })).toHaveCount(0);
        await page.evaluate(() => { document.documentElement.dataset.syntheticFieldPreparation = 'recovered'; });
        await page.getByRole('button', { name: 'Prepare field app', exact: true }).click();
        await expect(page.getByText('Field app ready', { exact: true })).toBeVisible({ timeout: 30_000 });
        await expect(queue(page)).toContainText('pending');
        expect(await page.evaluate(() => Boolean(navigator.serviceWorker.controller))).toBe(true);
    });

    test('an Inertia response cannot replace the authenticated HTML field shell', async ({ page }) => {
        await provision(page);
        await expect(page.getByText('Field app ready', { exact: true })).toBeVisible({ timeout: 30_000 });
        const result = await page.evaluate(async () => {
            const version = JSON.parse(document.getElementById('app')!.dataset.page!).version;
            const response = await fetch('/offline-workspace', { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': version } });
            const cache = await caches.open('kalinga-field-shell-v15');
            const shell = (await cache.match(new URL('/offline-workspace', location.origin).href))!;
            return { status: response.status, inertia: response.headers.get('X-Inertia'), type: shell.headers.get('Content-Type'), text: await shell.text() };
        });
        expect(result.status).toBe(200); expect(result.inertia).toBe('true');
        expect(result.type).toContain('text/html'); expect(result.text).toMatch(/<!doctype html>/i);
        expect(result.text).not.toContain('DEMO-KLG-2026-');
    });
});

async function provision(page: Page) {
    await login(page);
    await page.goto('/offline-workspace');
    await page.getByLabel('Offline passphrase').fill('synthetic-sync-passphrase');
    await page.getByLabel('Confirm passphrase').fill('synthetic-sync-passphrase');
    await page.getByRole('button', { name: 'Set up encrypted workspace' }).click();
    await page.getByRole('button', { name: 'Download case pack' }).click();
    await expect(page.getByText(/authorized case record\(s\) encrypted/)).toBeVisible();
}
async function saveNote(page: Page, text: string) {
    await page.getByLabel('Case note', { exact: true }).fill(text);
    await page.getByRole('button', { name: 'Save encrypted update on this device' }).click();
    await expect(page.getByLabel('Case note', { exact: true })).toHaveValue('');
}
const queue = (page: Page) => page.getByRole('heading', { name: 'Local encrypted queue', exact: true }).locator('..').locator('..');
async function reloadQueue(page: Page) {
    await page.reload();
    await page.getByLabel('Offline passphrase').fill('synthetic-sync-passphrase');
    await page.getByRole('button', { name: 'Unlock workspace', exact: true }).click();
}

test('new encrypted work survives an earlier in-flight sync and reload', async ({ page }) => {
    await provision(page);
    await saveNote(page, 'Synthetic first in-flight note');
    let release!: () => void;
    const gate = new Promise<void>(resolve => { release = resolve; });
    let uploaded!: () => void;
    const upload = new Promise<void>(resolve => { uploaded = resolve; });
    await page.route('**/sync', async route => {
        const response = await route.fetch();
        uploaded();
        await gate;
        await route.fulfill({ response });
    });
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await upload;
    await saveNote(page, 'Synthetic second note appended while uploading');
    await expect(queue(page).locator('span').filter({ hasText: /^pending$/ })).toHaveCount(2);
    release();
    await expect(page.getByRole('button', { name: 'Sync now', exact: true })).toBeEnabled();
    await expect(queue(page).locator('span').filter({ hasText: /^pending$/ })).toHaveCount(1);
    await expect(queue(page).locator('span').filter({ hasText: /^synced$/ })).toHaveCount(1);
    await reloadQueue(page);
    await expect(queue(page).locator('span').filter({ hasText: /^pending$/ })).toHaveCount(1);
});

test('concurrent tabs append distinct encrypted notes without lost updates or plaintext persistence', async ({ page, context }) => {
    await provision(page);
    const second = await context.newPage();
    await second.goto('/offline-workspace');
    await second.getByLabel('Offline passphrase').fill('synthetic-sync-passphrase');
    await second.getByRole('button', { name: 'Unlock workspace', exact: true }).click();
    await expect(second.getByLabel('Case note', { exact: true })).toBeVisible();
    const secret = `Synthetic concurrency private ${Date.now()}`;
    const output: string[] = [];
    page.on('console', message => output.push(message.text()));
    await Promise.all([saveNote(page, secret + ' A'), saveNote(second, secret + ' B')]);
    await reloadQueue(page);
    await expect(queue(page).locator('span').filter({ hasText: /^pending$/ })).toHaveCount(2);
    const surfaces = await page.evaluate(async () => {
        const database = await new Promise<IDBDatabase>((resolve, reject) => {
            const request = indexedDB.open('kalinga-secure-offline');
            request.onsuccess = () => resolve(request.result); request.onerror = () => reject(request.error);
        });
        const records = await new Promise<unknown[]>((resolve, reject) => {
            const transaction = database.transaction('encrypted-records');
            const request = transaction.objectStore('encrypted-records').getAll();
            transaction.oncomplete = () => { database.close(); resolve(request.result); };
            transaction.onerror = () => { database.close(); reject(transaction.error); };
        });
        const cacheBodies: string[] = [];
        for (const name of await caches.keys()) {
            const cache = await caches.open(name);
            for (const request of await cache.keys()) {
                if (new URL(request.url).pathname === '/offline-workspace') cacheBodies.push(await (await cache.match(request))!.text());
            }
        }
        return { records, storage: JSON.stringify({ ...localStorage, ...sessionStorage }), cacheBodies };
    });
    expect(JSON.stringify(surfaces)).not.toContain(secret);
    expect(output.join('\n')).not.toContain(secret);
    expect(surfaces.records).toEqual(expect.arrayContaining([expect.objectContaining({ iv: expect.any(String), value: expect.any(String) })]));
});

test('lost acknowledgement retries the identical UUID without duplicating the server note', async ({ page }) => {
    await provision(page);
    await saveNote(page, 'Synthetic lost acknowledgement note');
    const ids: string[] = [];
    let first = true;
    await page.route('**/sync', async route => {
        ids.push(route.request().postDataJSON().offline_id);
        const response = await route.fetch();
        if (first) { first = false; await route.abort('connectionreset'); }
        else await route.fulfill({ response });
    });
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(queue(page)).toContainText('failed');
    await reloadQueue(page);
    await expect(queue(page)).toContainText('failed');
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(queue(page)).toContainText('synced');
    expect(ids).toHaveLength(2); expect(ids[0]).toBe(ids[1]);
});

for (const status of [401, 403, 419]) test(`authorization ${status} retains the entire encrypted batch across reload`, async ({ page }) => {
    await provision(page);
    await saveNote(page, 'Synthetic auth-expiry first note');
    await saveNote(page, 'Synthetic auth-expiry second note');
    let uploads = 0;
    await page.route('**/sync', route => { uploads++; return route.fulfill({ status, json: { message: 'Synthetic private server error must not be persisted' } }); });
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Authorization could not be confirmed');
    expect(uploads).toBe(1);
    await reloadQueue(page);
    await expect(queue(page).locator('span').filter({ hasText: /^failed$/ })).toHaveCount(1);
    await expect(queue(page).locator('span').filter({ hasText: /^pending$/ })).toHaveCount(1);
    await expect(queue(page)).not.toContainText('private server error');
});

test('quota failure after server acknowledgement retains the original UUID for safe retry', async ({ page }) => {
    await provision(page);
    await saveNote(page, 'Synthetic acknowledgement quota note');
    await page.evaluate(() => {
        const original = IDBObjectStore.prototype.put;
        document.documentElement.dataset.syncQuota = 'exhausted';
        IDBObjectStore.prototype.put = function (this: IDBObjectStore, value: unknown, key?: IDBValidKey) {
            if (this.name === 'encrypted-records' && key === 'operations' && document.documentElement.dataset.syncQuota === 'exhausted') throw new DOMException('Synthetic quota', 'QuotaExceededError');
            return original.call(this, value, key);
        };
    });
    const ids: string[] = [];
    page.on('request', request => { if (new URL(request.url()).pathname === '/sync') ids.push(request.postDataJSON().offline_id); });
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('encrypted queue state could not be saved');
    await expect(queue(page)).toContainText('pending');
    await reloadQueue(page);
    await expect(queue(page)).toContainText('pending');
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(queue(page)).toContainText('synced');
    expect(ids).toHaveLength(2); expect(ids[0]).toBe(ids[1]);
});

test('reload while an acknowledgement is held leaves pending work recoverable', async ({ page }) => {
    await provision(page);
    await saveNote(page, 'Synthetic interrupted page note');
    let uploaded!: () => void; const upload = new Promise<void>(resolve => { uploaded = resolve; });
    let release!: () => void; const gate = new Promise<void>(resolve => { release = resolve; });
    const ids: string[] = [];
    await page.route('**/sync', async route => {
        ids.push(route.request().postDataJSON().offline_id);
        const response = await route.fetch(); uploaded(); await gate;
        try { await route.fulfill({ response }); } catch { /* The uploading document was intentionally closed. */ }
    });
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await upload;
    await reloadQueue(page);
    await expect(queue(page)).toContainText('pending');
    release();
    await page.unroute('**/sync');
    const response = page.waitForResponse(response => new URL(response.url()).pathname === '/sync');
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    expect((await response).request().postDataJSON().offline_id).toBe(ids[0]);
    await expect(queue(page)).toContainText('synced');
});

test('mismatched acknowledgements and conflicts remain recoverable without automatic conflict resubmission', async ({ page }) => {
    await provision(page);
    await saveNote(page, 'Synthetic acknowledgement mismatch note');
    await page.route('**/sync', route => route.fulfill({ json: { status: 'synced', offline_id: 'different-identifier' } }));
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(queue(page)).toContainText('failed');
    await page.unroute('**/sync');
    let posts = 0;
    await page.route('**/sync', route => { posts++; return route.fulfill({ status: 409, json: { message: 'Synthetic changed case' } }); });
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Sync conflicts', exact: true })).toBeVisible();
    await reloadQueue(page);
    await expect(queue(page)).toContainText('conflict');
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(page.getByText(/0 acknowledged/)).toBeVisible();
    expect(posts).toBe(1);
});

test('locking during a held acknowledgement cannot clear or resurrect a local queue', async ({ page }) => {
    await provision(page);
    await saveNote(page, 'Synthetic lock during sync note');
    let release!: () => void; const gate = new Promise<void>(resolve => { release = resolve; });
    let uploaded!: () => void; const upload = new Promise<void>(resolve => { uploaded = resolve; });
    await page.route('**/sync', async route => {
        const response = await route.fetch(); uploaded(); await gate; await route.fulfill({ response });
    });
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await upload;
    await page.getByRole('button', { name: 'Lock workspace', exact: true }).click();
    release();
    await page.getByLabel('Offline passphrase').fill('synthetic-sync-passphrase');
    await page.getByRole('button', { name: 'Unlock workspace', exact: true }).click();
    await expect(queue(page)).toContainText('pending');
    await reloadQueue(page);
    await expect(queue(page)).toContainText('pending');
});

test('a large encrypted queue remains durable across reload', async ({ page }) => {
    test.setTimeout(90_000);
    await provision(page);
    for (let index = 0; index < 30; index++) await saveNote(page, `Synthetic large queue ${index} ` + 'x'.repeat(4900));
    await reloadQueue(page);
    await expect(queue(page).locator('span').filter({ hasText: /^pending$/ })).toHaveCount(30);
});

for (const width of [360, 768, 1440]) test(`encrypted queue recovery controls are usable at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 });
    await provision(page);
    await saveNote(page, 'Synthetic responsive recovery note');
    await page.route('**/sync', route => route.fulfill({ status: 503, json: { message: 'Synthetic unavailable' } }));
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(queue(page)).toContainText('failed');
    const retry = queue(page).getByRole('button', { name: 'Retry now', exact: true });
    await retry.scrollIntoViewIfNeeded();
    for (const button of [retry, queue(page).getByRole('button', { name: 'Discard', exact: true })]) {
        const box = (await button.boundingBox())!;
        expect(box.height).toBeGreaterThanOrEqual(44);
        expect(box.x).toBeGreaterThanOrEqual(0); expect(box.x + box.width).toBeLessThanOrEqual(width);
    }
    const accessibility = await new AxeBuilder({ page }).include('#encrypted-operation-queue').analyze();
    expect(accessibility.violations.filter(item => ['critical', 'serious'].includes(item.impact ?? ''))).toEqual([]);
});

test('a different signed-in worker cannot unlock the previous account pack or queue', async ({ page }) => {
    await provision(page);
    await saveNote(page, 'Synthetic original owner recoverable note');
    await page.getByPlaceholder('First name * (e.g., Maria)', { exact: true }).fill('SyntheticOwnerDraft');
    await expect(page.getByText('Draft encrypted and saved on this device. Not yet queued.', { exact: true })).toBeVisible();
    const signOut = async () => {
        await page.getByRole('button', { name: 'Log out', exact: true }).click();
        await page.getByRole('dialog', { name: 'Sign out of Kalinga' }).getByRole('button', { name: 'Sign out', exact: true }).click();
        await expect(page).toHaveURL(/\/login$/);
    };
    await signOut();
    await login(page, { email: 'supervisor@kalinga.test', password: 'password' });
    await page.goto('/offline-workspace');
    await page.getByLabel('Offline passphrase').fill('synthetic-sync-passphrase');
    await page.getByRole('button', { name: 'Unlock workspace', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('belongs to another account');
    await expect(page.getByLabel('Case note', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Local encrypted queue', exact: true })).toHaveCount(0);
    await signOut();
    await login(page);
    await page.goto('/offline-workspace');
    await page.getByLabel('Offline passphrase').fill('synthetic-sync-passphrase');
    await page.getByRole('button', { name: 'Unlock workspace', exact: true }).click();
    await expect(queue(page)).toContainText('pending');
    await expect(page.getByPlaceholder('First name * (e.g., Maria)', { exact: true })).toHaveValue('SyntheticOwnerDraft');
});

test('conflict retry requires a refreshed authorized pack and explicit review', async ({ page }) => {
    await provision(page);
    await saveNote(page, 'Synthetic first versioned note');
    await saveNote(page, 'Synthetic full saved observation to review before append');
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Sync conflicts', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Field workspace', exact: true }).click();
    await page.getByRole('button', { name: 'Refresh case pack', exact: true }).click();
    await expect(page.getByText(/authorized case record\(s\) encrypted/)).toBeVisible();
    await page.getByRole('button', { name: 'Conflicts (1)', exact: true }).click();
    await page.getByRole('button', { name: /^(Retry|Review and retry)$/ }).click();
    const review = page.getByRole('dialog', { name: 'Review queued work' });
    await expect(review).toBeVisible();
    await expect(review).toContainText('Synthetic full saved observation to review before append');
    const confirm = review.getByRole('button', { name: 'Confirm review and retry', exact: true });
    await expect(confirm).toBeDisabled();
    await review.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(review).not.toBeVisible();
    await expect(page.getByRole('button', { name: 'Conflicts (1)', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Review and retry', exact: true }).click();
    await expect(confirm).toBeDisabled();
    await page.evaluate(() => {
        const original = IDBObjectStore.prototype.put;
        document.documentElement.dataset.reviewQuota = 'exhausted';
        IDBObjectStore.prototype.put = function (this: IDBObjectStore, value: unknown, key?: IDBValidKey) {
            if (this.name === 'encrypted-records' && key === 'operations' && document.documentElement.dataset.reviewQuota === 'exhausted') throw new DOMException('Synthetic review quota', 'QuotaExceededError');
            return original.call(this, value, key);
        };
    });
    await review.getByRole('checkbox').check();
    await confirm.click();
    await expect(review.getByRole('alert')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Conflicts (1)', exact: true })).toBeVisible();
    await page.evaluate(() => { document.documentElement.dataset.reviewQuota = 'recovered'; });
    await confirm.click();
    await expect(review).not.toBeVisible();
    await expect(page.getByRole('status')).toContainText('1 acknowledged');
    await page.getByRole('button', { name: 'Field workspace', exact: true }).click();
    await expect(queue(page).locator('span').filter({ hasText: /^synced$/ })).toHaveCount(2);
    await reloadQueue(page);
    await expect(queue(page).locator('span').filter({ hasText: /^synced$/ })).toHaveCount(2);
});

test('expired packs hide queued narratives while preserving encrypted conflict recovery', async ({ page }) => {
    await provision(page);
    await saveNote(page, 'Synthetic first expiry version note');
    const secret = 'Synthetic queued observation hidden after pack expiry';
    await saveNote(page, secret);
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Sync conflicts', exact: true })).toBeVisible();
    await expect(page.getByText(secret, { exact: false })).toBeVisible();
    await page.route('**/offline-workspace/pack', async route => {
        const response = await route.fetch();
        const body = await response.json(); body.expires_at = new Date(Date.now() - 60_000).toISOString();
        await route.fulfill({ response, json: body });
    });
    await page.getByRole('button', { name: 'Field workspace', exact: true }).click();
    await page.getByRole('button', { name: 'Refresh case pack', exact: true }).click();
    await expect(page.getByText(/expired case details are locked/i)).toBeVisible();
    await page.getByRole('button', { name: 'Conflicts (1)', exact: true }).click();
    await expect(page.getByText(secret, { exact: false })).toHaveCount(0);
    await page.getByRole('button', { name: 'Field workspace', exact: true }).click();
    await expect(queue(page)).toContainText('conflict');
});

test('a case removed from the refreshed pack cannot disclose or rebase its queued conflict', async ({ page }) => {
    await provision(page);
    await saveNote(page, 'Synthetic first removed case note');
    const secret = 'Synthetic removed case queued observation';
    await saveNote(page, secret);
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Sync conflicts', exact: true })).toBeVisible();
    await page.route('**/offline-workspace/pack', async route => {
        const response = await route.fetch();
        const body = await response.json(); body.cases = [];
        await route.fulfill({ response, json: body });
    });
    await page.getByRole('button', { name: 'Field workspace', exact: true }).click();
    await page.getByRole('button', { name: 'Refresh case pack', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('0 authorized');
    await page.getByRole('button', { name: 'Conflicts (1)', exact: true }).click();
    await expect(page.getByText(secret, { exact: false })).toHaveCount(0);
    await page.getByRole('button', { name: 'Review and retry', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Review queued work' })).not.toBeVisible();
    await expect(page.getByRole('alert')).toBeVisible();
    await page.getByRole('button', { name: 'Field workspace', exact: true }).click();
    await expect(queue(page)).toContainText('conflict');
});


