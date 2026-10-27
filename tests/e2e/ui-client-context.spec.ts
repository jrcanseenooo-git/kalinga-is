import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, expectNoHorizontalOverflow, dismissConfirm } from './helpers';

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

test('support relationship persists and the signed consent form can be added later', async ({ page }) => {
    const panel = await openContext(page);
    await panel.getByRole('button', { name: 'Household & support', exact: true }).click();
    await panel.getByRole('textbox', { name: "Person's name", exact: true }).fill('Synthetic support person');
    await panel.getByRole('combobox', { name: 'Relationship to client', exact: true }).selectOption('Aunt');
    await panel.getByRole('textbox', { name: 'Support contact (optional)', exact: true }).fill('Synthetic agreed contact');
    await panel.getByRole('checkbox', { name: 'Record guardian flag', exact: true }).check();
    await submit(page, panel, 'Add relationship');
    await expect(panel.getByRole('article').filter({ hasText: 'Synthetic support person' })).toContainText('Guardian flag recorded');
    // The consent register is gone; the consent given at intake is shown instead.
    await expect(panel.getByRole('button', { name: 'Consent register', exact: true })).toHaveCount(0);
    await panel.getByRole('button', { name: 'Client details', exact: true }).click();
    const consent = panel.locator('section[aria-labelledby="client-consent-title"]');
    await expect(consent).toContainText('No photo of the signed form yet');
    await consent.getByRole('button', { name: 'Add the signed form', exact: true }).click();
    const png = await page.evaluate(() => { const canvas = document.createElement('canvas'); canvas.width = 800; canvas.height = 1100; const context = canvas.getContext('2d')!; context.fillStyle = '#fff'; context.fillRect(0, 0, 800, 1100); context.fillStyle = '#111'; for (let y = 80; y < 1000; y += 40) context.fillRect(60, y, 600, 6); return canvas.toDataURL('image/png').split(',')[1]; });
    await consent.locator('input[type=file]:not([capture])').setInputFiles({ name: 'form.png', mimeType: 'image/png', buffer: Buffer.from(png, 'base64') });
    await expect(consent.getByRole('img', { name: 'Photo: Signed consent / assent form' })).toBeVisible();
    await consent.getByRole('combobox', { name: 'This photo shows', exact: true }).selectOption('assent');
    await submit(page, consent, 'Save the photo');
    await expect(panel.getByRole('status').filter({ hasText: 'Signed form attached to the case.' })).toBeVisible();
    await expect(consent).toContainText('Assent form (child)');
    await expect(consent.getByRole('link', { name: 'View the signed form', exact: true })).toBeVisible();
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
    await page.getByRole('link', { name: 'Back to case registry', exact: true }).click();await dismissConfirm(page); await expect(page).toHaveURL(url);
    await expect(panel.getByRole('textbox', { name: "Person's name", exact: true })).toHaveValue('Synthetic unfinished support');
});

for (const width of [360, 390, 768, 1024, 1440, 1920]) test(`client context responsive review at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1080 }); const panel = await openContext(page);
    await expect.poll(async () => Math.round((await panel.boundingBox())!.y)).toBeGreaterThanOrEqual(64);
    await expect.poll(async () => Math.round((await panel.boundingBox())!.y)).toBeLessThan(160);
    await panel.getByRole('button', { name: 'Recorded intake context', exact: true }).click();
    for (const [view, name] of [['details', 'Client details'], ['support', 'Household & support']]) {
        await panel.getByRole('button', { name, exact: true }).click();
        await panel.evaluate(element => element.scrollIntoView({ block: 'start' }));
        await expectNoHorizontalOverflow(page);
        const scan = await new AxeBuilder({ page }).include('main').analyze();
        expect(scan.violations.filter(item => ['serious', 'critical'].includes(item.impact ?? ''))).toEqual([]);
        await page.screenshot({ path: `docs/implementation/evidence/ui-11-b/${view}-${width}.png`, animations: 'disabled' });
    }
});
