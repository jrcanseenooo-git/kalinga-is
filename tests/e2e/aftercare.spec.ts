import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { expectNoHorizontalOverflow, login } from './helpers';

/** Aftercare plan written from Closure & aftercare (follow-up visits come after closure). */
for (const width of [390, 1280]) test(`aftercare plan is written and shown at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 });
    await login(page);
    await page.goto('/cases?search=DEMO-KLG-2026-0001');
    await page.getByRole('link', { name: /DEMO-KLG-2026-0001/ }).first().click();
    await expect(page).toHaveURL(/\/cases\/\d+$/);
    await page.getByRole('tab', { name: /^Closure & aftercare/ }).click();
    const panel = page.locator('section[aria-labelledby="aftercare-title"]');
    if (await panel.getByRole('button', { name: 'Update the plan' }).count()) await panel.getByRole('button', { name: 'Update the plan' }).click();
    await panel.getByLabel('Where and with whom the child will live').fill(`With the mother (${width})`);
    await panel.getByLabel('Services that continue').fill('ALS classes');
    await panel.locator('#aftercare-frequency').selectOption('bimonthly');
    await expectNoHorizontalOverflow(page);
    await panel.getByRole('button', { name: 'Save the aftercare plan' }).click();
    await expect(panel).toContainText(`With the mother (${width})`);
    await expect(panel).toContainText('Every 2 months');
    await expect(panel).toContainText('Follow-up visits are recorded after the case is closed.');
    const scan = await new AxeBuilder({ page }).include('section[aria-labelledby="aftercare-title"]').analyze();
    expect(scan.violations.filter(item => ['critical', 'serious'].includes(item.impact ?? ''))).toEqual([]);
});
