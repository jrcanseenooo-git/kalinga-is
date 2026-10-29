import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { accounts, login } from './helpers';

test('administrator downloads the exact UAT roster and a new worker signs in using the displayed code', async ({ page, browser }) => {
    test.setTimeout(120_000);
    await login(page, accounts.admin);
    await page.goto('/admin/uat');
    await expect(page.getByRole('heading', { name: 'UAT account setup', exact: true })).toBeVisible();
    await expect(page.locator('tbody tr')).toHaveCount(43);
    await page.getByRole('checkbox', { name: /I reviewed the roster/ }).check();
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Create 43 accounts and download credentials', exact: true }).click();
    const download = await downloadPromise;
    const file = await download.path();
    expect(file).not.toBeNull();
    const payload = JSON.parse(readFileSync(file!, 'utf8')) as { accounts: { uat_key: string; email: string; password: string }[] };
    expect(payload.accounts).toHaveLength(43);
    expect(new Set(payload.accounts.map(row => row.email)).size).toBe(43);
    await expect(page.getByRole('status')).toContainText('43 accounts created.');
    await page.reload();
    await expect(page.getByRole('status')).toContainText('Creation is blocked');
    const retryDownloadPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Download saved UAT credentials', exact: true }).click();
    const retryFile = await (await retryDownloadPromise).path();
    const retryPayload = JSON.parse(readFileSync(retryFile!, 'utf8'));
    expect(retryPayload.accounts).toEqual(payload.accounts);
    await page.getByRole('button', { name: 'I saved the file - remove the server copy', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Download saved UAT credentials', exact: true })).toHaveCount(0);

    const worker = payload.accounts.find(row => row.uat_key === 'car-cm01')!;
    const workerPage = await browser.newPage({ baseURL: new URL(page.url()).origin });
    try {
        await workerPage.goto('/login');
        await workerPage.getByLabel('Email', { exact: true }).fill(worker.email);
        await workerPage.locator('#password').fill(worker.password);
        await workerPage.getByRole('button', { name: 'Login Account', exact: true }).click();
        await expect(workerPage).toHaveURL(/\/two-factor\/challenge$/);
        const banner = workerPage.getByTestId('uat-code');
        await expect(banner).toContainText('does not verify email ownership');
        const code = (await banner.locator('p').nth(1).innerText()).trim();
        expect(code).toMatch(/^\d{6}$/);
        for (let i = 0; i < 6; i++) {
            await workerPage.getByLabel(`Digit ${i + 1} of 6`, { exact: true }).fill(code[i]);
        }
        await expect(workerPage).toHaveURL(/\/dashboard$/);
        await expect(workerPage.locator('#worker-dashboard')).toContainText('Paracelis');
    } finally {
        await workerPage.close();
    }
});
