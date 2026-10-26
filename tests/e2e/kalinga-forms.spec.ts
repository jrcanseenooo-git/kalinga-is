import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { expectNoHorizontalOverflow, login } from './helpers';

/** Phase 4: KaVAT, the Kalinga forms and the grants log in the case workspace. */
async function openCase(page: Page, number = 'DEMO-KLG-2026-0002') {
    await login(page);
    await page.goto(`/cases?search=${number}`);
    await page.getByRole('link', { name: new RegExp(number) }).first().click();
    await expect(page).toHaveURL(/\/cases\/\d+$/);
}

test('case manager answers the KaVAT with a live score and finalizes it', async ({ page }) => {
    await openCase(page);
    await page.getByRole('tab', { name: /^KaVAT/ }).click();
    const panel = page.locator('#case-panel-kavat');
    const start = panel.getByRole('button', { name: /Start (the|a new) KaVAT/ });
    if (await start.count()) {
        await start.click();
    }
    await expect(panel.getByRole('heading', { name: /Part 1 - Empowerment/ })).toBeVisible();
    // The worked example in the guidelines: Part 1 = 65, Part 2 = 88, final 26.92.
    for (let n = 1; n <= 25; n++) await panel.locator(`input[name="kavat-p1-${n}"][value="${[1, 2, 3, 6, 7].includes(n) ? 'no' : 'maybe'}"]`).check();
    const part2: Record<string, string> = { living_arrangement: '1', family_support: '1', dependents: '1', education: '1', economic_support: '1', breadwinner: 'yes', income: 'yes', partner_age_gap: '1', age_at_union: 'minor', decision: '1', violence: 'yes', safety: 'no' };
    for (const [key, value] of Object.entries(part2)) await panel.locator(`input[name="kavat-p2-${key}"][value="${value}"]`).check();
    const score = panel.getByRole('complementary', { name: 'Live KaVAT score' });
    await expect(score).toContainText('26.92');
    await expect(score).toContainText('High Priority (Eligible)');
    await expect(score.getByRole('status')).toHaveText('All answers saved', { timeout: 10_000 });
    await expectNoHorizontalOverflow(page);
    const scan = await new AxeBuilder({ page }).include('#case-panel-kavat').analyze();
    expect(scan.violations.filter(item => ['critical', 'serious'].includes(item.impact ?? ''))).toEqual([]);
    await score.getByRole('button', { name: 'Finalize' }).click();
    await expect(panel.getByRole('heading', { name: 'KaVAT history' })).toBeVisible();
    await expect(panel.getByRole('table')).toContainText('26.92');
});

test('Kalinga forms save as the case manager types and keep their order', async ({ page }) => {
    await openCase(page);
    await page.getByRole('tab', { name: /^Kalinga forms/ }).click();
    const panel = page.locator('#case-panel-forms');
    const steps = panel.getByRole('list', { name: 'Order of the Kalinga forms' }).getByRole('listitem');
    await expect(steps).toHaveCount(5);
    await expect(steps.nth(0)).toContainText('Intake Sheet');
    await expect(steps.nth(4)).toContainText('Social Case Study Report');
    await steps.nth(1).getByRole('button', { name: /Write|Continue/ }).click();
    const text = 'Synthetic presenting problem written in the Case Summary Report.';
    await panel.getByRole('textbox', { name: 'Presenting problem / reason for referral' }).fill(text);
    await expect(panel.getByRole('status').filter({ hasText: /^Saved/ })).toBeVisible({ timeout: 10_000 });
    await page.reload();
    await page.getByRole('tab', { name: /^Kalinga forms/ }).click();
    await steps.nth(1).getByRole('button', { name: /Continue/ }).click();
    await expect(panel.getByRole('textbox', { name: 'Presenting problem / reason for referral' })).toHaveValue(text);

    await steps.nth(3).getByRole('button', { name: /Write|Continue/ }).click();
    const plan = panel.getByRole('article');
    await plan.locator('textarea[id^="plan-"][id$="-problem"]:visible').first().fill('Stopped schooling after the union');
    await expect(plan.getByRole('status').filter({ hasText: /^Saved/ })).toBeVisible({ timeout: 10_000 });
    await plan.getByRole('button', { name: /Finalize version/ }).click();
    await expect(steps.nth(3)).toContainText('Finalized version 1');
    await expectNoHorizontalOverflow(page);
});

test('grants log records a grant and totals it', async ({ page }) => {
    await openCase(page);
    await page.getByRole('tab', { name: /^Progress notes & referrals/ }).click();
    const grants = page.getByRole('region', { name: 'Grants log' });
    await grants.getByRole('button', { name: 'Record a grant' }).click();
    await grants.getByLabel('Type of grant').selectOption({ label: 'Educational assistance' });
    await grants.getByLabel('Amount (PHP)').fill('2500');
    await grants.getByRole('button', { name: 'Save grant' }).click();
    await expect(grants.getByRole('table')).toContainText('Educational assistance');
    await expect(page.getByRole('heading', { name: 'Referrals to CPU members', exact: false })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Referrals to external agencies', exact: false })).toBeVisible();
});
