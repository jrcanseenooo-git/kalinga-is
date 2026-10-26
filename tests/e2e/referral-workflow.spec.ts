import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, expectNoHorizontalOverflow } from './helpers';

async function confirmIfAsked(page: Page) {
    const confirm = page.locator('#generic-submit-confirm');
    if (await confirm.isVisible({ timeout: 1500 }).catch(() => false)) await confirm.click();
}

// Referrals are written as progress notes (earlier Kalinga design) and tracked from the note.
for (const width of [360, 768, 1440]) test(`referral from a progress note follows the lifecycle at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 });
    await login(page);
    await page.goto('/cases?search=DEMO-KLG');
    await page.getByRole('link', { name: /DEMO-KLG-/ }).first().click();
    await expect(page).toHaveURL(/\/cases\/\d+$/);
    await page.getByRole('tab', { name: /Progress notes/ }).first().click();
    await page.getByRole('button', { name: 'Refer', exact: true }).click();
    await page.getByRole('radio', { name: 'Referral to external agency' }).check();
    const form = page.locator('form[aria-labelledby="note-entry-title"]');
    await expect(form.getByRole('heading', { name: 'Refer the child' })).toBeVisible();
    await form.locator('#note-ref-to').selectOption("Public Attorney's Office (PAO)");
    await form.locator('#note-ref-purpose').selectOption('Legal counseling');
    await expect(form.locator('#note-ref-category')).toHaveValue('Legal assistance');
    await form.locator('#note-ref-basis').selectOption('legal_obligation');
    const reason = `Synthetic referral reason ${width}`;
    await form.locator('#monitor-note-body').fill(reason);
    await form.getByRole('button', { name: 'Save note and send referral', exact: true }).click();
    await confirmIfAsked(page);
    await expect(page.getByRole('status').filter({ hasText: 'Referral recorded and sent' }).first()).toBeVisible();

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
    await page.getByRole('tab', { name: /Progress notes/ }).first().click();
    const reloaded = page.getByRole('article').filter({ hasText: reason });
    await expect(reloaded).toContainText('Completed');
    await reloaded.getByRole('button', { name: 'Track / update', exact: true }).click();
    await expect(reloaded.getByRole('list', { name: 'Referral progress' })).toBeVisible();
    await expect(reloaded).toContainText('Synthetic legal counseling received.');
    await expectNoHorizontalOverflow(page);
    const accessibility = await new AxeBuilder({ page }).include('main').analyze();
    expect(accessibility.violations.filter(item => ['critical', 'serious'].includes(item.impact ?? ''))).toEqual([]);
});
