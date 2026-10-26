import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { login, expectNoHorizontalOverflow } from './helpers';

async function open(page: Page) {
    await login(page);
    await page.goto('/cpu/coordination');
}

async function direct(page: Page, name = 'Kalinga CPU Member') {
    const memberButton = page.getByRole('button', { name: `Message ${name}`, exact: true });
    if (!await memberButton.isVisible()) await page.getByRole('button', { name: 'Members', exact: true }).click();
    await memberButton.click();
    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Coordination messages', exact: true })).toHaveAttribute('aria-busy', 'false');
}

async function send(page: Page, body: string) {
    await page.getByLabel('New message', { exact: true }).fill(body);
    await page.getByRole('button', { name: 'Send message', exact: true }).click();
    await page.getByRole('dialog').locator('#generic-submit-confirm').click();
    await expect(page.getByRole('region', { name: 'Coordination messages', exact: true })).toContainText(body);
}

test('composer keeps selected chips and text when focusing and closing the options panel', async ({ page }) => {
    await open(page);
    const options = page.locator('details.composer-options');
    await options.locator(':scope > summary').click();
    const select = page.getByRole('combobox', { name: 'Case reference (optional)', exact: true });
    await expect(select.locator('option').filter({ hasText: 'DEMO-KLG-2026-0001' })).toHaveCount(1);
    const value = await select.locator('option').filter({ hasText: 'DEMO-KLG-2026-0001' }).getAttribute('value');
    await select.selectOption(value!);
    await page.getByLabel('Urgent', { exact: true }).check();
    await page.getByLabel('Follow-up', { exact: true }).check();
    await options.locator('label').filter({ hasText: '@Kalinga CPU Member' }).click();
    await expect(page.getByLabel('@Kalinga CPU Member', { exact: true })).toBeChecked();
    await page.getByLabel('New message', { exact: true }).fill('Synthetic retained composer draft.');
    await expect(options).not.toHaveAttribute('open');
    await expect(page.getByRole('button', { name: 'Remove case reference', exact: true })).toContainText('DEMO-KLG-2026-0001');
    await expect(page.getByRole('button', { name: 'Remove mention of Kalinga CPU Member', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Remove urgent flag', exact: true }).click();
    await options.locator(':scope > summary').click();
    await expect(page.getByLabel('Urgent', { exact: true })).not.toBeChecked();
    await expect(page.getByLabel('Follow-up', { exact: true })).toBeChecked();
    await expect(page.getByLabel('New message', { exact: true })).toHaveValue('Synthetic retained composer draft.');
});

test('private messages are readable by the recipient and absent from group and third member conversations', async ({ page, browser }) => {
    await open(page);
    await page.getByLabel('New message', { exact: true }).fill('Synthetic unsent group draft.');
    await direct(page);
    await expect(page.getByLabel('New message', { exact: true })).toHaveValue('');
    const body = 'SYNTHETIC-PRIVATE-SCHEDULE-ONLY';
    await send(page, body);
    await expect(page.getByRole('button', { name: 'Record as note', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: /^Back to .*CPU Coordination Circle$/ }).click();
    await expect(page.getByLabel('New message', { exact: true })).toHaveValue('Synthetic unsent group draft.');
    await expect(page.getByRole('region', { name: 'Coordination messages', exact: true })).not.toContainText(body);
    const context = await browser.newContext();
    const recipient = await context.newPage();
    await login(recipient, { email: 'cpu@kalinga.test', password: 'password' });
    await recipient.goto('/cpu/coordination');
    await direct(recipient, 'Kalinga Social Worker');
    await expect(recipient.getByRole('region', { name: 'Coordination messages', exact: true })).toContainText(body);
    await context.close();
    const thirdContext = await browser.newContext();
    const third = await thirdContext.newPage();
    await login(third, { email: 'supervisor@kalinga.test', password: 'password' });
    await third.goto('/cpu/coordination');
    await direct(third, 'Kalinga Social Worker');
    await expect(third.getByRole('region', { name: 'Coordination messages', exact: true })).not.toContainText(body);
    await thirdContext.close();
});

test('direct-message lost acknowledgement prevents automatic retry and preserves the draft', async ({ page }) => {
    await open(page);
    await direct(page);
    let writes = 0;
    await page.route('**/cpu/coordination/*/direct/*/messages', async route => {
        if (route.request().method() === 'POST') { writes++; await route.fetch(); await route.abort('failed'); }
        else await route.continue();
    });
    const body = 'Synthetic private uncertain acknowledgement.';
    await page.getByLabel('New message', { exact: true }).fill(body);
    await page.getByRole('button', { name: 'Send message', exact: true }).click();
    await page.getByRole('dialog').locator('#generic-submit-confirm').click();
    await expect(page.getByRole('alert')).toContainText('send outcome could not be confirmed');
    await expect(page.getByLabel('New message', { exact: true })).toHaveValue(body);
    await expect(page.getByRole('button', { name: 'Send message', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Refresh conversation', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Coordination messages', exact: true })).toContainText(body);
    expect(writes).toBe(1);
});

for (const width of [360, 390, 768, 1024, 1440, 1920]) test(`flat composer and direct messaging at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await open(page);
    const options = page.locator('details.composer-options');
    await options.locator(':scope > summary').click();
    await page.getByLabel('Urgent', { exact: true }).check();
    await expectNoHorizontalOverflow(page);
    await expect(page.getByLabel('New message', { exact: true })).toBeInViewport();
    const openScan = await new AxeBuilder({ page }).include('main').analyze();
    expect(openScan.violations.filter(item => ['serious', 'critical'].includes(item.impact ?? ''))).toEqual([]);
    await expect(page.getByRole('group', { name: 'Case reference selection', exact: true })).toHaveAttribute('aria-busy', 'false');
    await page.screenshot({ path: `docs/implementation/evidence/connect-2026-10-23/options-${width}.png`, animations: 'disabled' });
    await page.getByLabel('New message', { exact: true }).fill('Synthetic responsive draft.');
    await expect(options).not.toHaveAttribute('open');
    await expectNoHorizontalOverflow(page);
    await page.screenshot({ path: `docs/implementation/evidence/connect-2026-10-23/composer-${width}.png`, animations: 'disabled' });
    let scan = await new AxeBuilder({ page }).include('main').analyze();
    expect(scan.violations.filter(item => ['serious', 'critical'].includes(item.impact ?? ''))).toEqual([]);
    await direct(page);
    await expect(page.getByLabel('New message', { exact: true })).toBeInViewport();
    await expectNoHorizontalOverflow(page);
    scan = await new AxeBuilder({ page }).include('main').analyze();
    expect(scan.violations.filter(item => ['serious', 'critical'].includes(item.impact ?? ''))).toEqual([]);
    await page.screenshot({ path: `docs/implementation/evidence/connect-2026-10-23/direct-${width}.png`, animations: 'disabled' });
});
