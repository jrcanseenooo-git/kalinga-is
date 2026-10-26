import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, expectNoHorizontalOverflow } from './helpers';

async function open(page: Page) {
    await login(page, { email: 'cpu@kalinga.test', password: 'password' });
    await page.goto('/mdt/collaboration');
    const card = page.locator('article').filter({ hasText: 'SYN-CPU-PRESENTATION' }).filter({ hasText: 'Review the requested health coordination' });
    await card.getByRole('link', { name: 'Open assignment', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'What you need to do', exact: true })).toBeVisible();
}

async function confirmForm(page: Page) {
    await page.getByRole('dialog').locator('#generic-submit-confirm').click();
    await expect(page.getByRole('status').filter({ hasText: 'Saved.' })).toBeVisible();
}

test('CPU sees referral instructions and selected assignment instead of the newer empty assignment', async ({ page }) => {
    await open(page);
    await expect(page.getByText('Assignment status: Action required', { exact: true })).toBeVisible();
    await expect(page.getByText('Coordinate the available appointment and document the professional recommendation.', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Confirm the health coordination schedule', exact: true })).toBeVisible();
    await expect(page.getByText('Synthetic authorized support plan summary.', { exact: true })).toBeVisible();
    await expect(page.locator('main')).not.toContainText('CPU protected identity sentinel');
    const select = page.getByLabel('Active assignment for this case', { exact: true });
    const first = await select.inputValue();
    const second = await select.locator('option').filter({ hasText: 'Separate education' }).getAttribute('value');
    await select.selectOption(second!);
    await expect(page.getByText('Assignment status: Assigned', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Confirm the health coordination schedule', exact: true })).toHaveCount(0);
    await expect(page.getByText('Synthetic authorized support plan summary.', { exact: true })).toHaveCount(0);
    await page.reload();
    await expect(page.getByText('Assignment status: Assigned', { exact: true })).toBeVisible();
    await select.selectOption(first);
    await expect(page.getByRole('heading', { name: 'Confirm the health coordination schedule', exact: true })).toBeVisible();
});

test('notes show saved findings, finalize and retain linked addenda in the selected assignment', async ({ page }) => {
    await open(page);
    await page.getByLabel('Note category', { exact: true }).selectOption({ label: 'Medical findings' });
    await page.getByLabel('Findings', { exact: true }).fill('Synthetic completed coordination findings.');
    await page.getByLabel('Recommendation', { exact: true }).fill('Synthetic follow-up recommendation.');
    await page.getByRole('button', { name: 'Save draft note', exact: true }).click();
    await confirmForm(page);
    await expect(page.getByText('Synthetic completed coordination findings.', { exact: true })).toBeVisible();
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Finalize note', exact: true }).last().click();
    await expect(page.getByRole('button', { name: 'Add addendum', exact: true }).last()).toBeVisible();
    await page.getByRole('button', { name: 'Add addendum', exact: true }).last().click();
    await page.getByLabel('Reason for addendum', { exact: true }).fill('Synthetic professional clarification.');
    await page.getByLabel('Note category', { exact: true }).selectOption({ label: 'Recommendation' });
    await page.getByLabel('Findings', { exact: true }).fill('Synthetic retained addendum findings.');
    await page.getByRole('button', { name: 'Save final addendum', exact: true }).click();
    await confirmForm(page);
    await expect(page.getByText('Synthetic retained addendum findings.', { exact: true })).toBeVisible();
    await expect(page.getByText('Synthetic completed coordination findings.', { exact: true })).toBeVisible();
});

test('unfinished findings block assignment changes and action completion', async ({ page }) => {
    await open(page);
    const select = page.getByLabel('Active assignment for this case', { exact: true });
    const original = await select.inputValue();
    await page.getByLabel('Findings', { exact: true }).fill('Synthetic unsaved findings.');
    const other = await select.locator('option').filter({ hasText: 'Separate education' }).getAttribute('value');
    await select.selectOption(other!);
    await expect(page.getByRole('alert')).toContainText('Save or discard');
    await expect(select).toHaveValue(original);
    await page.getByRole('button', { name: 'Mark complete', exact: true }).click();
    await expect(page.getByLabel('Findings', { exact: true })).toHaveValue('Synthetic unsaved findings.');
    await expect(page.getByRole('heading', { name: 'Confirm the health coordination schedule', exact: true })).toBeVisible();
});

test('revoked selected assignment clears loaded context and unfinished text without falling back', async ({ page }) => {
    await open(page);
    await page.getByLabel('Findings', { exact: true }).fill('SYNTHETIC-REVOKED-DRAFT');
    await page.route('**/cases/*/mdt?*', route => route.fulfill({ status: 403, json: { message: 'Synthetic revoked assignment' } }));
    await page.getByRole('button', { name: 'Refresh assignment', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Assignment unavailable', exact: true })).toBeVisible();
    await expect(page.getByLabel('Findings', { exact: true })).toHaveCount(0);
    await expect(page.locator('main')).not.toContainText('Contact the referring case manager');
    expect(await page.evaluate(() => JSON.stringify(localStorage) + JSON.stringify(sessionStorage) + JSON.stringify(history.state))).not.toContain('SYNTHETIC-REVOKED-DRAFT');
});

test('unconfirmed note save preserves entries and prevents automatic duplicate writes', async ({ page }) => {
    await open(page);
    let writes = 0;
    await page.route('**/cases/*/mdt/notes', async route => {
        if (route.request().method() === 'POST') { writes++; await route.fetch(); await route.abort('failed'); }
        else await route.continue();
    });
    await page.getByLabel('Note category', { exact: true }).selectOption({ label: 'Coordination' });
    await page.getByLabel('Findings', { exact: true }).fill('Synthetic note with lost acknowledgement.');
    await page.getByRole('button', { name: 'Save draft note', exact: true }).click();
    await page.getByRole('dialog').locator('#generic-submit-confirm').click();
    await expect(page.getByRole('alert')).toContainText('save outcome is unconfirmed');
    await expect(page.getByLabel('Findings', { exact: true })).toHaveValue('Synthetic note with lost acknowledgement.');
    await expect(page.getByRole('button', { name: 'Save draft note', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Refresh assignment', exact: true }).click();
    await expect(page.getByText('Synthetic note with lost acknowledgement.', { exact: true })).toBeVisible();
    expect(writes).toBe(1);
});

for (const width of [360, 390, 768, 1024, 1440, 1920]) test(`CPU workspace at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 950 });
    await open(page);
    await expectNoHorizontalOverflow(page);
    const scan = await new AxeBuilder({ page }).include('main').analyze();
    expect(scan.violations.filter(item => ['serious', 'critical'].includes(item.impact ?? ''))).toEqual([]);
    await page.screenshot({ path: `docs/implementation/evidence/cpu-2026-10-23/workspace-${width}.png`, fullPage: true, animations: 'disabled' });
});

// Terminal workflow runs last because the shared synthetic grant intentionally ends.
test('response is pinned to the original assignment and completion returns to the worklist', async ({ page }) => {
    await open(page);
    const response = page.waitForResponse(item => item.request().method() === 'PATCH' && /\/mdt\/actions\//.test(item.url()));
    await page.getByRole('button', { name: 'Mark responded', exact: true }).click();
    expect((await response).status()).toBe(204);
    await expect(page.getByText('Assignment status: Responded', { exact: true })).toBeVisible();
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Mark complete', exact: true }).click();
    await expect(page).toHaveURL(/\/mdt\/collaboration$/);
    const old = page.locator('article').filter({ hasText: 'SYN-CPU-PRESENTATION' }).filter({ hasText: 'Review the requested health coordination' });
    await expect(old).toHaveCount(0);
});
