import { expect, test, type BrowserContext } from '@playwright/test';
import { expectNoHorizontalOverflow, login } from './helpers';

// context.setOffline() does not stop service-worker fetches in Chromium, so also
// fail every request at the context level (this covers the service worker).
async function noSignal(context: BrowserContext, off: boolean) {
    await context.setOffline(off);
    if (off) await context.route('**/*', route => route.abort('internetdisconnected'));
    else await context.unrouteAll({ behavior: 'ignoreErrors' });
}

test.describe('opening Kalinga with no signal', () => {
    test.use({ serviceWorkers: 'allow' });

    test('sign-in and home pages open Field mode; other pages point to it', async ({ page, context }) => {
        await login(page);
        await page.goto('/offline-workspace');
        await page.getByLabel('Offline passphrase').fill('entry-device-passphrase');
        await page.getByLabel('Confirm passphrase').fill('entry-device-passphrase');
        await page.getByRole('button', { name: 'Set up encrypted workspace' }).click();
        await expect.poll(() => page.evaluate(() => Boolean(navigator.serviceWorker.controller)), { timeout: 30_000 }).toBe(true);
        await expect.poll(() => page.evaluate(async () => Boolean(await caches.match(new URL('/offline-workspace', location.origin).href))), { timeout: 30_000 }).toBe(true);

        await noSignal(context, true);
        for (const entry of ['/login', '/dashboard', '/']) {
            await page.goto(entry);
            await expect(page).toHaveURL(/\/offline-workspace$/);
            await expect(page.getByRole('heading', { name: 'Unlock offline workspace' })).toBeVisible();
        }

        await page.goto('/cases');
        await expect(page.getByRole('heading', { name: 'No signal - keep working in Field mode' })).toBeVisible();
        await expect(page.getByText('You are offline')).toHaveCount(0);
        await expectNoHorizontalOverflow(page);
        await page.getByRole('link', { name: 'Open Field mode' }).click();
        await expect(page).toHaveURL(/\/offline-workspace$/);
        await expect(page.getByRole('heading', { name: 'Unlock offline workspace' })).toBeVisible();
        await noSignal(context, false);
    });

    test('a device without Field mode explains how to set it up', async ({ page, context }) => {
        await page.goto('/login');
        await page.evaluate(async () => { await navigator.serviceWorker.register('/sw.js'); await navigator.serviceWorker.ready; });
        await page.reload();
        await expect.poll(() => page.evaluate(() => Boolean(navigator.serviceWorker.controller)), { timeout: 30_000 }).toBe(true);
        await noSignal(context, true);
        await page.goto('/login');
        await expect(page.getByRole('heading', { name: 'No signal right now' })).toBeVisible();
        await expect(page.getByText('Field mode is not set up on this device yet')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Open Field mode' })).toBeHidden();
        await noSignal(context, false);
    });
});
