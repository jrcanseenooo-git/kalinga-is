import { expect, test, type Page } from '@playwright/test';
import { login } from './helpers';
import AxeBuilder from '@axe-core/playwright';

async function provision(page: Page) {
    await login(page);
    await page.goto('/offline-workspace');
    await page.getByLabel('Offline passphrase').fill('synthetic-draft-passphrase');
    await page.getByLabel('Confirm passphrase').fill('synthetic-draft-passphrase');
    await page.getByRole('button', { name: 'Set up encrypted workspace' }).click();
    await page.getByRole('button', { name: 'Download case pack' }).click();
    await expect(page.getByText(/authorized case record\(s\) encrypted/)).toBeVisible();
}
async function unlock(page: Page) {
    await page.getByLabel('Offline passphrase').fill('synthetic-draft-passphrase');
    await page.getByRole('button', { name: 'Unlock workspace', exact: true }).click();
}
test.use({ serviceWorkers: 'allow' });
const firstName = (page: Page) => page.getByPlaceholder('First name * (e.g., Maria)', { exact: true });

test.describe('encrypted intake drafts', () => {
    test.use({ serviceWorkers: 'allow' });
    test('unfinished intake survives a disconnected browser reload', async ({ page, context }) => {
        await provision(page);
        await expect(page.getByText('Field app ready', { exact: true })).toBeVisible({ timeout: 30_000 });
        await context.setOffline(true);
        await firstName(page).fill('SyntheticRecoverable');
        await expect(page.getByText('Draft encrypted and saved on this device. Not yet queued.', { exact: true })).toBeVisible();
        await page.reload();
        await unlock(page);
        await expect(firstName(page)).toHaveValue('SyntheticRecoverable');
    });
});

const saved = (page: Page) => page.getByText('Draft encrypted and saved on this device. Not yet queued.', { exact: true });
const submit = (page: Page) => page.getByRole('button', { name: 'Save encrypted CEFMU Intake Sheet', exact: true });
const queue = (page: Page) => page.locator('#encrypted-operation-queue');
async function completeIntake(page: Page) {
    await firstName(page).fill('SyntheticAtomic');
    await page.getByPlaceholder('Last name * (e.g., Dela Cruz)', { exact: true }).fill('Draft');
    await page.locator('input[type="date"]').first().fill('2014-02-16');
    await page.locator('select').filter({ has: page.locator('option', { hasText: 'Sex *' }) }).selectOption('Female');
    await page.getByRole('checkbox', { name: /I acknowledge that I have read/ }).check();
    await page.locator('select').filter({ has: page.locator('option', { hasText: 'How was the privacy notice provided?' }) }).selectOption('explained_verbally');
    await page.getByRole('checkbox', { name: /Early union/ }).check();
    await page.locator('select').filter({ has: page.locator('option', { hasText: 'Initial risk level *' }) }).selectOption('low');
    await page.getByPlaceholder('Municipality / city').fill('City of Tabuk');
    await page.getByPlaceholder('Barangay').fill('Dagupan Centro');
    await expect(saved(page)).toBeVisible();
}

for (const failure of ['QuotaExceededError', 'AbortError']) test(`atomic intake promotion recovers from ${failure} without duplicates`, async ({ page }) => {
    await provision(page);
    await completeIntake(page);
    await page.evaluate(failure => {
        const original = IDBObjectStore.prototype.put;
        IDBObjectStore.prototype.put = function (this: IDBObjectStore, value: unknown, key?: IDBValidKey) {
            // Draft ciphertext was already written earlier in this transaction.
            if (this.name === 'encrypted-records' && key === 'operations') throw new DOMException('Synthetic interrupted promotion', failure);
            return original.call(this, value, key);
        };
    }, failure);
    await submit(page).click();
    await expect(page.getByRole('alert')).toContainText('Could not queue this intake');
    await expect(firstName(page)).toHaveValue('SyntheticAtomic');
    await page.reload(); await unlock(page);
    await expect(firstName(page)).toHaveValue('SyntheticAtomic');
    await expect(queue(page)).toHaveCount(0);
    await submit(page).evaluate((button: HTMLButtonElement) => { button.click(); button.click(); });
    await expect(queue(page).locator('span').filter({ hasText: /^pending$/ })).toHaveCount(1);
    await expect(firstName(page)).toHaveValue('');
    await page.reload(); await unlock(page);
    await expect(firstName(page)).toHaveValue('');
    await expect(queue(page).locator('span').filter({ hasText: /^pending$/ })).toHaveCount(1);
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(queue(page)).toContainText('synced');
    await page.reload(); await unlock(page);
    await expect(queue(page)).toContainText('synced');
    await expect(firstName(page)).toHaveValue('');
});

test('draft quota failure retains entered work and permits explicit recovery', async ({ page }) => {
    await provision(page);
    await page.evaluate(() => {
        const original = IDBObjectStore.prototype.put;
        document.documentElement.dataset.draftQuota = 'full';
        IDBObjectStore.prototype.put = function (this: IDBObjectStore, value: unknown, key?: IDBValidKey) {
            if (this.name === 'encrypted-records' && key === 'intake-drafts' && document.documentElement.dataset.draftQuota === 'full') throw new DOMException('Synthetic quota', 'QuotaExceededError');
            return original.call(this, value, key);
        };
    });
    await firstName(page).fill('SyntheticQuotaRetained');
    await expect(page.locator('#intake-draft-recovery').getByRole('alert')).toBeVisible();
    await expect(firstName(page)).toHaveValue('SyntheticQuotaRetained');
    await page.evaluate(() => { document.documentElement.dataset.draftQuota = 'recovered'; });
    await page.getByRole('button', { name: 'Retry encrypted draft save' }).click();
    await expect(saved(page)).toBeVisible();
    await page.reload(); await unlock(page);
    await expect(firstName(page)).toHaveValue('SyntheticQuotaRetained');
});

test('stale tabs cannot overwrite a recovered draft and can retain a separate draft', async ({ page, context }) => {
    await provision(page);
    await firstName(page).fill('SyntheticShared'); await expect(saved(page)).toBeVisible();
    const other = await context.newPage(); await other.goto('/offline-workspace'); await unlock(other);
    await expect(firstName(other)).toHaveValue('SyntheticShared');
    await firstName(page).fill('SyntheticWinningRevision'); await expect(saved(page)).toBeVisible();
    await firstName(other).fill('SyntheticOtherRevision');
    await expect(other.locator('#intake-draft-recovery').getByRole('alert')).toBeVisible();
    await expect(firstName(other)).toHaveValue('SyntheticOtherRevision');
    await other.getByRole('button', { name: 'Save as separate draft' }).click(); await expect(saved(other)).toBeVisible();
    await other.reload(); await unlock(other);
    await expect(other.getByRole('button', { name: /Recover draft saved/ })).toHaveCount(2);
    await expect(firstName(other)).toHaveValue('SyntheticWinningRevision');
    await other.getByRole('button', { name: /Recover draft saved/ }).last().click();
    await expect(firstName(other)).toHaveValue('SyntheticOtherRevision');
});

test('drafts remain encrypted, hidden on expiry and recover after reauthorization', async ({ page }) => {
    const logs: string[] = []; page.on('console', message => logs.push(message.text()));
    await provision(page);
    await expect(page.getByText('Field app ready', { exact: true })).toBeVisible({ timeout: 30_000 });
    const secret = 'SyntheticSecretDraftNarrative';
    await firstName(page).fill(secret); await expect(saved(page)).toBeVisible();
    const surfaces = await page.evaluate(async () => {
        const database = await new Promise<IDBDatabase>((resolve, reject) => { const request = indexedDB.open('kalinga-secure-offline'); request.onsuccess = () => resolve(request.result); request.onerror = () => reject(request.error); });
        const raw = await new Promise<unknown[]>(resolve => { const request = database.transaction('encrypted-records').objectStore('encrypted-records').getAll(); request.onsuccess = () => resolve(request.result); }); database.close();
        const bodies: string[] = [];
        for (const name of await caches.keys()) { const cache = await caches.open(name); for (const request of await cache.keys()) { const response = await cache.match(request); if (response) bodies.push(await response.text()); } }
        return JSON.stringify({ raw, local: { ...localStorage }, session: { ...sessionStorage }, bodies });
    });
    expect(surfaces).not.toContain(secret); expect(logs.join(' ')).not.toContain(secret);
    await page.route('**/offline-workspace/pack', async route => { const response = await route.fetch(); const body = await response.json(); body.expires_at = new Date(Date.now() - 1000).toISOString(); await route.fulfill({ response, json: body }); });
    await page.getByRole('button', { name: 'Refresh case pack' }).click();
    // An expired pack locks case details only; new intakes stay available for GIDA field work.
    await expect(page.getByText(/expired case details are locked/i)).toBeVisible();
    await expect(firstName(page)).toHaveValue(secret);
    await page.unroute('**/offline-workspace/pack');
    await page.getByRole('button', { name: 'Download case pack' }).click();
    await expect(firstName(page)).toHaveValue(secret);
});

test('interrupted draft transaction restores its last committed revision after reload', async ({ page }) => {
    await provision(page);
    await firstName(page).fill('SyntheticCommitted'); await expect(saved(page)).toBeVisible();
    await page.evaluate(() => {
        const original = IDBObjectStore.prototype.put;
        IDBObjectStore.prototype.put = function (this: IDBObjectStore, value: unknown, key?: IDBValidKey) {
            if (this.name === 'encrypted-records' && key === 'operations') throw new DOMException('Synthetic write interruption', 'AbortError');
            return original.call(this, value, key);
        };
    });
    await firstName(page).fill('SyntheticInterrupted');
    await expect(page.locator('#intake-draft-recovery').getByRole('alert')).toBeVisible();
    page.once('dialog', dialog => dialog.accept());
    await page.reload(); await unlock(page);
    await expect(firstName(page)).toHaveValue('SyntheticCommitted');
    await expect(queue(page)).toHaveCount(0);
});

test('locking and confirmed revocation clear draft narratives across open workspace tabs', async ({ page, context }) => {
    await provision(page);
    await firstName(page).fill('SyntheticCrossTabClear'); await expect(saved(page)).toBeVisible();
    const other = await context.newPage(); await other.goto('/offline-workspace'); await unlock(other);
    await expect(firstName(other)).toHaveValue('SyntheticCrossTabClear');
    await page.getByRole('button', { name: 'Lock workspace', exact: true }).click();
    await expect(firstName(other)).toHaveCount(0);
    await unlock(page); await unlock(other);
    await expect(firstName(page)).toHaveValue('SyntheticCrossTabClear');
    await page.route('**/offline-workspace/device-status', route => route.fulfill({ json: { revoked: true } }));
    await page.getByRole('button', { name: 'Lock workspace', exact: true }).click();
    await unlock(page);
    await expect(page.getByText(/This device has been revoked\. All local encrypted data/)).toBeVisible();
    await expect(firstName(other)).toHaveCount(0);
    await expect.poll(() => page.evaluate(async () => (await indexedDB.databases()).some(item => item.name === 'kalinga-secure-offline'))).toBe(false);
});

for (const width of [360, 768, 1440]) test(`intake draft recovery controls fit ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 950 });
    await provision(page);
    await firstName(page).fill('SyntheticResponsive'); await expect(saved(page)).toBeVisible();
    const box = (await page.locator('#intake-draft-recovery').boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(0); expect(box.x + box.width).toBeLessThanOrEqual(width);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
    const accessibility = await new AxeBuilder({ page }).include('#intake-draft-recovery').analyze();
    expect(accessibility.violations.filter(item => ['critical', 'serious'].includes(item.impact ?? ''))).toEqual([]);
});

test('two tabs promoting the same recovered intake create one queue identity', async ({ page, context }) => {
    await provision(page); await completeIntake(page);
    const other = await context.newPage(); await other.goto('/offline-workspace'); await unlock(other);
    await expect(firstName(other)).toHaveValue('SyntheticAtomic');
    await Promise.all([submit(page).click(), submit(other).click()]);
    await expect(firstName(page)).toHaveValue(''); await expect(firstName(other)).toHaveValue('');
    await page.reload(); await unlock(page);
    await expect(queue(page).locator('span').filter({ hasText: /^pending$/ })).toHaveCount(1);
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(queue(page)).toContainText('synced');
});

test('an intake queue remains recoverable after server authorization expires', async ({ page }) => {
    await provision(page); await completeIntake(page); await submit(page).click();
    await expect(queue(page)).toContainText('pending');
    await page.route('**/sync', route => route.fulfill({ status: 401, json: { message: 'Synthetic sensitive server error' } }));
    await page.getByRole('button', { name: 'Sync now', exact: true }).click();
    await expect(queue(page)).toContainText('failed');
    await expect(queue(page)).not.toContainText('Synthetic sensitive server error');
    await page.reload(); await unlock(page);
    await expect(queue(page)).toContainText('failed'); await expect(firstName(page)).toHaveValue('');
    await page.unroute('**/sync');
    await queue(page).getByRole('button', { name: 'Retry now', exact: true }).click();
    await expect(queue(page)).toContainText('synced');
});

test('intake fields are protected while atomic promotion is in flight', async ({ page }) => {
    await provision(page); await completeIntake(page);
    await page.evaluate(() => {
        const original = SubtleCrypto.prototype.digest;
        SubtleCrypto.prototype.digest = function (algorithm: AlgorithmIdentifier, data: BufferSource) {
            return new Promise<ArrayBuffer>((resolve, reject) => {
                window.addEventListener('synthetic-promotion-release', () => { original.call(this, algorithm, data).then(resolve, reject); }, { once: true });
            });
        };
    });
    await submit(page).click();
    await expect(submit(page)).toBeDisabled();
    await expect(firstName(page)).toBeDisabled();
    await page.evaluate(() => window.dispatchEvent(new Event('synthetic-promotion-release')));
    await expect(queue(page)).toContainText('pending');
    await expect(firstName(page)).toBeEnabled(); await expect(firstName(page)).toHaveValue('');
});

// GIDA: an intake can be done with no signal and no case pack, and sync never interrupts it.
async function provisionWithoutPack(page: Page) {
    await login(page);
    await page.goto('/offline-workspace');
    await page.getByLabel('Offline passphrase').fill('synthetic-draft-passphrase');
    await page.getByLabel('Confirm passphrase').fill('synthetic-draft-passphrase');
    await page.getByRole('button', { name: 'Set up encrypted workspace' }).click();
    await expect(page.getByText('Field app ready', { exact: true })).toBeVisible({ timeout: 30_000 });
}
const savedList = (page: Page) => page.locator('#field-saved');

test('intake works offline without a case pack and syncs after reconnection', async ({ page, context }) => {
    await provisionWithoutPack(page);
    await context.setOffline(true);
    await completeIntake(page);
    await submit(page).click();
    await expect(savedList(page)).toContainText('SyntheticAtomic Draft');
    await expect(savedList(page)).toContainText('Waiting to sync');
    await context.setOffline(false);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    await expect(savedList(page)).toContainText('Synced - case created', { timeout: 30_000 });
    await expect(page.getByText(/1 acknowledged/)).toBeVisible();
});

test('reconnection waits for the intake in progress, then syncs and notifies', async ({ page, context }) => {
    await provisionWithoutPack(page);
    await context.setOffline(true);
    await completeIntake(page);
    await submit(page).click();
    await expect(savedList(page)).toContainText('Waiting to sync');
    await firstName(page).fill('SyntheticSecondChild');
    await expect(saved(page)).toBeVisible();
    await context.setOffline(false);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    await expect(page.getByText(/Syncing waits until you save the intake/)).toBeVisible();
    await expect(savedList(page)).toContainText('Waiting to sync');
    await firstName(page).fill('');
    await page.getByPlaceholder('Last name * (e.g., Dela Cruz)', { exact: true }).fill('');
    await page.locator('input[type="date"]').first().fill('');
    // Leaving the unfinished intake empty releases the wait; sync then runs by itself.
    await expect(savedList(page)).toContainText('Synced - case created', { timeout: 30_000 });
});
