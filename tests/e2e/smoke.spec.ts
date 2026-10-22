import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { expectNoHorizontalOverflow, login } from './helpers';

test('public login is accessible and does not overflow', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: 'Login account' })).toBeVisible();
    await expectNoHorizontalOverflow(page);
    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations.filter(v => ['serious', 'critical'].includes(v.impact ?? ''))).toEqual([]);
});

test('invalid credentials do not disclose account state', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Email').fill('unknown@kalinga.test');
    await page.locator('#password').fill('incorrect-password');
    await page.getByRole('button', { name: 'Login Account' }).click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByText(/credentials|unable|sign in/i).first()).toBeVisible();
});

test('worker can sign in to the casework dashboard', async ({ page }) => {
    await login(page);
    await expect(page.getByText('Kalinga Social Worker')).toBeVisible();
    await expectNoHorizontalOverflow(page);
});

test('public dashboard has no critical accessibility violations', async ({ page }) => {
    await page.goto('/public-dashboard');
    await expectNoHorizontalOverflow(page);
    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations.filter(v => v.impact === 'critical')).toEqual([]);
});
