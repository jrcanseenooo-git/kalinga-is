import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { expectNoHorizontalOverflow, login } from './helpers';

/** Field mode records a full progress note (type, time, action, next steps) and finds cases by name or number. */
for (const width of [390, 1280]) test(`field progress note with type and next steps at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 });
    await login(page);
    await page.goto('/offline-workspace');
    await page.getByLabel('Offline passphrase').fill('field-device-passphrase');
    await page.getByLabel('Confirm passphrase').fill('field-device-passphrase');
    await page.getByRole('button', { name: 'Set up encrypted workspace' }).click();
    await page.getByRole('button', { name: 'Download case pack' }).click();
    await expect(page.getByText(/authorized case record\(s\) encrypted/i)).toBeVisible();

    await page.getByLabel('Find a case').fill('DEMO-KLG-2026-0001');
    const list = page.locator('#field-cases aside');
    await expect(list.getByRole('button')).toHaveCount(1);
    await list.getByRole('button').first().click();
    await page.getByLabel('Record type').selectOption('other');
    const save = page.getByRole('button', { name: 'Save encrypted update on this device' });
    await page.getByLabel('Case note', { exact: true }).fill(`Synthetic field note ${width}`);
    await expect(save).toBeDisabled();
    await page.getByLabel('Specify the record type').fill('Barangay council meeting');
    await page.getByLabel('Next steps (optional)').fill('Follow up next week.');
    await expectNoHorizontalOverflow(page);
    await save.click();
    await expect(page.getByLabel('Case note', { exact: true })).toHaveValue('');
    const queue = page.getByRole('heading', { name: 'Local encrypted queue', exact: true }).locator('..').locator('..');
    await expect(queue).toContainText(/pending|synced/);
    const scan = await new AxeBuilder({ page }).include('#field-cases').analyze();
    expect(scan.violations.filter(item => ['critical', 'serious'].includes(item.impact ?? ''))).toEqual([]);
});
