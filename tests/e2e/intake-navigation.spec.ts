import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, expectNoHorizontalOverflow, acceptConfirm } from './helpers';

const next = (page: Page) => page.getByRole('button', { name: 'Continue', exact: true }).click();
const firstName = (page: Page) => page.getByRole('textbox', { name: 'First name *', exact: true });

async function privacy(page: Page) {
    await page.getByRole('checkbox', { name: /I gave the privacy notice/ }).check();
    await page.getByRole('combobox', { name: /^How was the privacy notice given/ }).selectOption('explained_verbally');
    await next(page);
}
async function child(page: Page, first: string) {
    await firstName(page).fill(first);
    await page.getByRole('textbox', { name: 'Last name *', exact: true }).fill('SyntheticRecovery');
    await page.getByLabel('Date of birth *').fill('2014-02-16');
    await page.getByRole('combobox', { name: 'Sex *', exact: true }).selectOption('Female');
    await next(page);
}

for (const width of [360, 768, 1440]) test(`online intake recovers an earlier-step server error and creates a case at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 });
    await login(page); await page.goto('/cases/create');
    await page.getByRole('button', { name: 'Start new intake', exact: true }).click();
    await privacy(page);
    await child(page, 'SyntheticPlaceholder');
    // A name longer than the server allows (the field normally stops at 80 characters).
    await page.getByRole('button', { name: 'Back', exact: true }).click();
    await firstName(page).evaluate((input: HTMLInputElement, value) => { input.removeAttribute('maxlength'); input.value = value; input.dispatchEvent(new Event('input', { bubbles: true })); }, 'S'.repeat(81));
    await next(page);
    await page.getByRole('checkbox', { name: /^Early union/ }).check();
    await page.getByRole('radio', { name: /^Yes, now/ }).check();
    await page.getByRole('spinbutton', { name: /Partner's age/ }).fill('19');
    await next(page);
    await page.getByRole('checkbox', { name: /^Age or power imbalance/ }).check();
    await expect(page.getByText(/Suggested level: Moderate/)).toBeVisible();

    const submit = async () => {
        await page.getByRole('button', { name: 'Create case folder', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Create the case folder?', exact: true });
        await expect(dialog).toBeVisible();
        await dialog.getByRole('button', { name: 'Review again', exact: true }).focus();
        await page.keyboard.press('Tab');
        await expect(dialog.getByRole('button', { name: 'Create case folder', exact: true })).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(dialog).not.toBeVisible();
        await page.getByRole('button', { name: 'Create case folder', exact: true }).click();
        await dialog.getByRole('button', { name: 'Create case folder', exact: true }).click();
    };
    await submit();
    const errors = page.getByRole('alert', { name: 'Review intake errors', exact: true });
    await expect(errors).toBeFocused();
    await errors.getByRole('button').first().click();
    await expect(firstName(page)).toBeFocused();
    await expect(firstName(page)).toHaveValue('S'.repeat(81));
    await firstName(page).fill('SyntheticVerified');
    await next(page); await next(page);
    await expect(page.getByRole('checkbox', { name: /^Age or power imbalance/ })).toBeChecked();
    await expectNoHorizontalOverflow(page);
    const accessibility = await new AxeBuilder({ page }).include('main').analyze();
    expect(accessibility.violations.filter(item => ['critical', 'serious'].includes(item.impact ?? ''))).toEqual([]);
    await submit();
    await expect(page).toHaveURL(/\/cases\/\d+$/);
    await expect(page.locator('main')).toContainText('SyntheticVerified');
});

test('local checks announce errors, keep work between steps and ask why a level is lower than suggested', async ({ page }) => {
    await login(page); await page.goto('/cases/create');
    await page.getByRole('button', { name: 'Start new intake', exact: true }).click();
    await next(page);
    const summary = page.getByRole('alert', { name: 'Review intake errors', exact: true });
    await expect(summary).toBeFocused();
    await expect(page.getByRole('button', { name: /Safety triage/ })).toBeDisabled();
    await summary.getByRole('button').first().click();
    await expect(page.getByRole('checkbox', { name: /I gave the privacy notice/ })).toBeFocused();
    await privacy(page);
    await expect(page.locator('fieldset > legend').first()).toBeFocused();
    await child(page, 'SyntheticLevel');
    await page.getByRole('checkbox', { name: /^Early marriage/ }).check();
    await expect(page.getByRole('checkbox', { name: /^Civil marriage/ })).toBeVisible();
    await page.getByRole('checkbox', { name: /^Civil marriage/ }).check();
    await next(page);
    await page.getByRole('checkbox', { name: /^Immediate danger/ }).check();
    await expect(page.getByRole('radio', { name: /^Critical/ })).toBeChecked();
    await page.getByRole('radio', { name: /^Low/ }).check();
    await page.getByRole('button', { name: 'Create case folder', exact: true }).click();
    await expect(summary).toBeFocused();
    await summary.getByRole('button').first().click();
    const reason = page.getByRole('textbox', { name: /Why is the level lower than suggested/ });
    await expect(reason).toBeFocused();
    await reason.fill('Synthetic reason: the child is staying with a safe relative.');
    await page.getByRole('button', { name: 'Back', exact: true }).click();
    await expect(page.getByRole('checkbox', { name: /^Civil marriage/ })).toBeChecked();
    await next(page);
    await expect(reason).toHaveValue('Synthetic reason: the child is staying with a safe relative.');
    await page.getByRole('button', { name: 'Create case folder', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Create the case folder?', exact: true })).toBeVisible();
});

test('an intake can be paused, found in Saved intakes, resumed and discarded', async ({ page }) => {
    await login(page); await page.goto('/cases/create');
    await page.getByRole('button', { name: 'Start new intake', exact: true }).click();
    await privacy(page);
    await firstName(page).fill('Paused');
    await page.getByRole('textbox', { name: 'Last name *', exact: true }).fill('Ramos');
    await page.getByRole('textbox', { name: 'Alias or nickname', exact: true }).fill('Pau');
    await page.getByRole('button', { name: 'Save and continue later', exact: true }).click();
    const saved = page.getByRole('region', { name: /Saved intakes/ });
    await expect(saved).toContainText('P**R**OS');
    await expect(saved).toContainText('Stopped at step 2 of 4');
    await expect(saved).not.toContainText('Ramos');
    await saved.getByRole('link', { name: 'Continue', exact: true }).first().click();
    await expect(firstName(page)).toHaveValue('Paused');
    await expect(page.getByRole('textbox', { name: 'Alias or nickname', exact: true })).toHaveValue('Pau');
    await page.goto('/cases/create');
    await saved.getByRole('button', { name: /Discard saved intake P\*\*R\*\*OS/ }).click();await acceptConfirm(page);
    await expect(saved).not.toContainText('P**R**OS');
});
