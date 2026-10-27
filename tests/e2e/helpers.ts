import { expect, type Page } from '@playwright/test';

export const accounts = {
    worker: { email: 'worker@kalinga.test', password: 'password' },
    admin: { email: 'admin@kalinga.test', password: 'password' },
};

export async function login(page: Page, account = accounts.worker) {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Email').fill(account.email);
    await page.locator('#password').fill(account.password);
    const loginResponse = page.waitForResponse(response =>
        response.request().method() === 'POST' && new URL(response.url()).pathname === '/login',
    );
    await page.getByRole('button', { name: 'Login Account' }).click();
    const response = await loginResponse;
    expect(response.status(), `Login request failed for ${account.email}`).toBeLessThan(400);
    await expect(page).not.toHaveURL(/\/login$/, { timeout: 30_000 });
    await expect(page.locator('#workspace-navigation')).toBeAttached();
}

export async function expectNoHorizontalOverflow(page: Page) {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
}

/** Answer Kalinga's own confirmation dialog (askConfirm), which replaced the browser confirm box. */
export async function acceptConfirm(page: Page) {
    const dialog = page.getByRole('dialog').filter({ has: page.locator('#confirm-dialog-accept') });
    await dialog.locator('#confirm-dialog-accept').click();
    await dialog.waitFor({ state: 'hidden' });
}
export async function dismissConfirm(page: Page) {
    const dialog = page.getByRole('dialog').filter({ has: page.locator('#confirm-dialog-accept') });
    await dialog.getByRole('button').first().click();
    await dialog.waitFor({ state: 'hidden' });
}
