import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, expectNoHorizontalOverflow } from './helpers';

async function confirmIfAsked(page: Page) {
    const confirm = page.locator('#generic-submit-confirm');
    if (await confirm.isVisible({ timeout: 1500 }).catch(() => false)) await confirm.click();
}

// Referrals are conducted from a row of the Intervention Plan (prefilled from the row) and
// are kept as progress notes, where their status is tracked.
for (const width of [360, 768, 1440]) test(`referral conducted from the Intervention Plan follows the lifecycle at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 });
    await login(page);
    await page.goto('/cases?search=DEMO-KLG');
    await page.getByRole('link', { name: /DEMO-KLG-/ }).first().click();
    await expect(page).toHaveURL(/\/cases\/\d+$/);
    await page.getByRole('tab', { name: /^Kalinga forms/ }).click();
    const forms = page.locator('#case-panel-forms');
    await forms.getByRole('list', { name: 'Order of the Kalinga forms' }).getByRole('listitem').nth(3).getByRole('button', { name: /Write|Continue|Read/ }).click();
    const problem = forms.locator('textarea[id$="-0-problem"]:visible');
    if (!(await problem.inputValue()).trim()) {
        await problem.fill('Needs legal counseling on the union');
        await expect(forms.getByRole('status').filter({ hasText: /^Saved/ })).toBeVisible({ timeout: 10_000 });
    }
    await forms.getByRole('button', { name: 'Conduct a referral for plan item 1' }).click();
    const dialog = page.getByRole('dialog', { name: /Conduct a referral - plan item 1/ });
    await expect(dialog.locator('#plan-ref-body')).toHaveValue(/Referral for Intervention Plan item 1/);
    await dialog.getByRole('radio', { name: 'Referral to external agency' }).check();
    await dialog.locator('#plan-ref-to').selectOption("Public Attorney's Office (PAO)");
    await dialog.locator('#plan-ref-purpose').selectOption('Legal counseling');
    await expect(dialog.locator('#plan-ref-category')).toHaveValue('Legal assistance');
    await dialog.locator('#plan-ref-basis').selectOption('legal_obligation');
    const reason = `Synthetic referral reason ${width}`;
    await dialog.locator('#plan-ref-body').fill(reason);
    await expectNoHorizontalOverflow(page);
    await dialog.getByRole('button', { name: 'Send the referral', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(forms.getByRole('region', { name: /Referrals to external agencies/ }).or(forms.locator('section[aria-labelledby="case-referrals-external"]'))).toContainText('Plan item 1');

    await page.getByRole('tab', { name: /^Progress notes/ }).click();
    const card = page.getByRole('article').filter({ hasText: reason });
    await expect(card).toContainText("Referred to Public Attorney's Office (PAO) - Legal counseling");
    await expect(card).toContainText('Sent');
    await card.getByRole('button', { name: 'Track / update', exact: true }).click();
    const progress = async (status: string, text: string) => {
        await card.getByLabel('Next referral status', { exact: true }).selectOption(status);
        if (status === 'COMPLETED') await card.getByLabel('Outcome').fill('Synthetic legal counseling received.');
        const saved = page.waitForResponse(item => /\/referrals\/\d+\/status$/.test(new URL(item.url()).pathname) && item.request().method() === 'PATCH');
        await card.getByRole('button', { name: 'Record status', exact: true }).click();
        await confirmIfAsked(page);
        expect((await saved).status()).toBeLessThan(400);
        await expect(card).toContainText(text);
        if (!(await card.getByLabel('Next referral status', { exact: true }).isVisible().catch(() => false)) && status !== 'COMPLETED') await card.getByRole('button', { name: 'Track / update', exact: true }).click();
    };
    await progress('ACCEPTED', 'Accepted');
    await progress('IN_PROGRESS', 'In progress');
    await progress('COMPLETED', 'Completed');
    await expect(card.getByLabel('Next referral status', { exact: true })).toHaveCount(0);
    await page.reload();
    await page.getByRole('tab', { name: /^Progress notes/ }).click();
    const reloaded = page.getByRole('article').filter({ hasText: reason });
    await expect(reloaded).toContainText('Completed');
    await reloaded.getByRole('button', { name: 'Track / update', exact: true }).click();
    await expect(reloaded.getByRole('list', { name: 'Referral progress' })).toBeVisible();
    await expect(reloaded).toContainText('Synthetic legal counseling received.');
    await expectNoHorizontalOverflow(page);
    const accessibility = await new AxeBuilder({ page }).include('main').analyze();
    expect(accessibility.violations.filter(item => ['critical', 'serious'].includes(item.impact ?? ''))).toEqual([]);
});
