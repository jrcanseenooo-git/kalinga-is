import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { expectNoHorizontalOverflow, login } from './helpers';

const REPORTS = [
    ['Kalinga Intake Sheet', 'KALINGA INTAKE SHEET'],
    ['Progress Notes', 'PROGRESS NOTES'],
    ['Intervention Plan', 'INTERVENTION PLAN'],
    ['Social Case Study Report', 'SOCIAL CASE STUDY REPORT'],
    ['Case Summary Report', 'CASE SUMMARY REPORT'],
] as const;

for (const width of [360, 1280]) {
    test(`case reports open from the case menu and stay private and readable at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await login(page);
        await page.goto('/cases?search=DEMO-KLG-2026-0001');
        await page.getByRole('link', { name: /DEMO-KLG-2026-0001/ }).first().click();
        await expect(page).toHaveURL(/\/cases\/\d+$/);
        const caseUrl = page.url();
        await page.getByRole('tab', { name: /^Kalinga forms/ }).click();
        await expect(page.getByRole('list', { name: 'Order of the Kalinga forms' }).getByRole('listitem')).toHaveCount(5);
        for (const [label, heading] of REPORTS) {
            await page.goto(caseUrl);
            const menu = page.getByRole('button', { name: 'Reports' });
            await menu.click();
            await expect(menu).toHaveAttribute('aria-expanded', 'true');
            await page.getByRole('list', { name: 'Print a case report' }).getByRole('link', { name: label, exact: true }).click();
            await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible();
            await expect(page.getByText('CONFIDENTIAL.', { exact: true })).toBeVisible();
            // Masked name by default; the full name appears only on request.
            await expect(page.getByText('Demo Aglibut')).toHaveCount(0);
            await expectNoHorizontalOverflow(page);
            const scan = await new AxeBuilder({ page }).analyze();
            expect(scan.violations.filter(item => ['serious', 'critical'].includes(item.impact ?? ''))).toEqual([]);
        }
        await expect(page.getByRole('group', { name: 'Name shown on this form' }).getByRole('button', { name: 'Masked', exact: true })).toHaveAttribute('aria-pressed', 'true');
        await page.getByRole('group', { name: 'Name shown on this form' }).getByRole('button', { name: 'Full name', exact: true }).click();
        await expect(page.getByText(/Demo Aglibut/).first()).toBeVisible();
    });
}

test('progress notes report applies a period', async ({ page }) => {
    await login(page);
    await page.goto('/cases?search=DEMO-KLG-2026-0001');
    await page.getByRole('link', { name: /DEMO-KLG-2026-0001/ }).first().click();
    await expect(page).toHaveURL(/\/cases\/\d+$/);
    const id = new URL(page.url()).pathname.split('/')[2];
    await page.goto(`/cases/${id}/reports/notes`);
    await page.getByLabel('From').fill('2020-01-01');
    await page.getByLabel('To').fill('2020-01-31');
    await page.getByRole('button', { name: 'Apply period' }).click();
    await expect(page).toHaveURL(/from=2020-01-01&to=2020-01-31/);
    await expect(page.getByText('January 1, 2020 to January 31, 2020')).toBeVisible();
    await expect(page.getByText('No progress notes were recorded for this period.')).toBeVisible();
});
