import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, expectNoHorizontalOverflow } from './helpers';

async function openContext(page: Page) {
    await login(page);
    await page.goto('/cases?search=SYN-CONTEXT-2026-0001');
    await page.getByRole('link', { name: /SYN-CONTEXT-2026-0001/ }).first().click();
    await page.getByRole('tab', { name: 'Client & Intake', exact: true }).click();
    return page.locator('#case-panel-people');
}

test('client details have labelled editing and actual intake and caregiver readback', async ({ page }) => {
    const panel = await openContext(page);
    await expect(panel.getByRole('textbox', { name: 'Given name', exact: true })).toHaveValue('Synthetic');
    await panel.getByRole('button', { name: 'Recorded intake context', exact: true }).click();
    await expect(panel.getByText('Early union', { exact: true })).toBeVisible();
    await expect(panel.getByText('Synthetic trusted caregiver', { exact: true })).toBeVisible();
    await expect(panel.getByText('Synthetic safe contact guidance.', { exact: true })).toBeVisible();
});

async function submit(page: Page, panel: ReturnType<Page['locator']>, name: string) {
    await panel.getByRole('button', { name, exact: true }).click();
    await page.getByRole('dialog').locator('#generic-submit-confirm').click();
}

test('client update retains rejected fields and reloads acknowledged real values', async ({ page }) => {
    const panel = await openContext(page);
    await panel.getByRole('textbox', { name: 'Family name', exact: true }).fill('Context updated');
    await panel.getByRole('textbox', { name: 'Contact number (optional)', exact: true }).fill('123');
    await submit(page, panel, 'Save details');
    await expect(panel.getByRole('alert').first()).toContainText('phone');
    await expect(panel.getByRole('alert').first()).toBeFocused();
    await expect(panel.getByRole('textbox', { name: 'Family name', exact: true })).toHaveValue('Context updated');
    await panel.getByRole('textbox', { name: 'Contact number (optional)', exact: true }).fill('09171234567');
    await submit(page, panel, 'Save details');
    await expect(panel.getByRole('status').filter({ hasText: 'Client and case details saved.' })).toBeVisible();
    await page.reload(); await page.getByRole('tab', { name: 'Client & Intake', exact: true }).click();
    await expect(panel.getByRole('textbox', { name: 'Family name', exact: true })).toHaveValue('Context updated');
    await expect(panel.getByRole('textbox', { name: 'Contact number (optional)', exact: true })).toHaveValue('09171234567');
});

test('support relationship and consent purposes persist and withdrawal preserves history', async ({ page }) => {
    const panel = await openContext(page);
    await panel.getByRole('button', { name: 'Household & support', exact: true }).click();
    await panel.getByRole('textbox', { name: "Person's name", exact: true }).fill('Synthetic support person');
    await panel.getByRole('combobox', { name: 'Relationship to client', exact: true }).selectOption('Aunt');
    await panel.getByRole('textbox', { name: 'Support contact (optional)', exact: true }).fill('Synthetic agreed contact');
    await panel.getByRole('checkbox', { name: 'Record guardian flag', exact: true }).check();
    await submit(page, panel, 'Add relationship');
    await expect(panel.getByRole('article').filter({ hasText: 'Synthetic support person' })).toContainText('Guardian flag recorded');
    await panel.getByRole('button', { name: 'Consent register', exact: true }).click();
    await panel.getByRole('combobox', { name: 'Lawful basis', exact: true }).selectOption('__other__');
    await panel.getByRole('textbox', { name: 'Specify other', exact: true }).fill('Synthetic documented basis');
    await panel.getByRole('textbox', { name: 'Documented purposes', exact: true }).fill('Synthetic case management\nSynthetic limited coordination');
    await submit(page, panel, 'Record consent');
    const entry = panel.getByRole('article').filter({ hasText: 'Synthetic documented basis' });
    await expect(entry).toContainText('Synthetic limited coordination');
    await expect(entry).toContainText('External sharing permission not recorded');
    page.once('dialog', dialog => dialog.dismiss()); await entry.getByRole('button', { name: 'Record withdrawal', exact: true }).click();
    await expect(entry.getByRole('button', { name: 'Record withdrawal', exact: true })).toBeVisible();
    page.once('dialog', dialog => dialog.accept()); await entry.getByRole('button', { name: 'Record withdrawal', exact: true }).click();
    await expect(entry).toContainText('Withdrawn'); await expect(entry.getByRole('button')).toHaveCount(0);
    await page.reload(); await page.getByRole('tab', { name: 'Client & Intake', exact: true }).click();
    await panel.getByRole('button', { name: 'Consent register', exact: true }).click();
    await expect(entry).toContainText('Withdrawn'); await expect(entry).toContainText('Synthetic case management');
    expect(await page.evaluate(() => JSON.stringify(localStorage) + JSON.stringify(sessionStorage))).not.toContain('Synthetic documented basis');
    await panel.getByRole('button', { name: 'Household & support', exact: true }).click();
    await expect(panel.getByRole('article').filter({ hasText: 'Synthetic support person' })).toContainText('Synthetic agreed contact');
});

test('client context unfinished work survives activity changes and cancelled leave', async ({ page }) => {
    const panel = await openContext(page); const url = page.url();
    await panel.getByRole('button', { name: 'Household & support', exact: true }).click();
    await panel.getByRole('textbox', { name: "Person's name", exact: true }).fill('Synthetic unfinished support');
    await panel.getByRole('button', { name: 'Client details', exact: true }).click();
    await page.getByRole('tab', { name: 'Overview', exact: true }).click();
    await page.getByRole('tab', { name: 'Client & Intake', exact: true }).click();
    await panel.getByRole('button', { name: 'Household & support', exact: true }).click();
    await expect(panel.getByRole('textbox', { name: "Person's name", exact: true })).toHaveValue('Synthetic unfinished support');
    let prompted = false; page.once('dialog', async dialog => { prompted = true; await dialog.dismiss(); });
    await page.getByRole('link', { name: 'Back to case registry', exact: true }).click();
    await expect.poll(() => prompted).toBe(true); await expect(page).toHaveURL(url);
    await expect(panel.getByRole('textbox', { name: "Person's name", exact: true })).toHaveValue('Synthetic unfinished support');
});

for (const width of [360, 390, 768, 1024, 1440, 1920]) test(`client context responsive review at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1080 }); const panel = await openContext(page);
    await expect.poll(async () => Math.round((await panel.boundingBox())!.y)).toBeGreaterThanOrEqual(64);
    await expect.poll(async () => Math.round((await panel.boundingBox())!.y)).toBeLessThan(160);
    await panel.getByRole('button', { name: 'Recorded intake context', exact: true }).click();
    for (const [view, name] of [['details', 'Client details'], ['support', 'Household & support'], ['consent', 'Consent register']]) {
        const select = panel.getByRole('combobox', { name: 'Client context activity', exact: true });
        if (await select.isVisible()) await select.selectOption(view);
        else await panel.getByRole('button', { name, exact: true }).click();
        await panel.evaluate(element => element.scrollIntoView({ block: 'start' }));
        await expectNoHorizontalOverflow(page);
        const scan = await new AxeBuilder({ page }).include('main').analyze();
        expect(scan.violations.filter(item => ['serious', 'critical'].includes(item.impact ?? ''))).toEqual([]);
        await page.screenshot({ path: `docs/implementation/evidence/ui-11-b/${view}-${width}.png`, animations: 'disabled' });
    }
});
