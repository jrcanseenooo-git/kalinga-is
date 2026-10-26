import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { expectNoHorizontalOverflow, login } from './helpers';

const cpu = { email: 'cpu@kalinga.test', password: 'password' };

// These online role journeys verify server authorization and navigation. The
// dedicated offline specs exercise the service worker separately.
test.use({ serviceWorkers: 'block' });

async function expectAccessibleWorkspace(page: import('@playwright/test').Page) {
    await expect(page.locator('html')).not.toHaveClass(/nprogress-busy/);
    await expectNoHorizontalOverflow(page);
    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations.filter(v => ['serious', 'critical'].includes(v.impact ?? ''))).toEqual([]);
}

test('central office follows management-to-administration flow without case navigation', async ({ page }) => {
    await login(page, { email: 'admin@kalinga.test', password: 'password' });
    await expect(page).toHaveURL(/management-dashboard/);
    await expect(page.getByText('CEFMU national management dashboard').first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'Cases', exact: true })).toHaveCount(0);
    await expectAccessibleWorkspace(page);
    await page.goto('/central-office/pipeline');
    await expect(page).toHaveURL(/central-office\/pipeline/);
    await page.goto('/admin/overview');
    await expect(page).toHaveURL(/admin\/overview/);
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/admin$/);
    await expectAccessibleWorkspace(page);
});

test('case manager follows the integrated case helping-process workspace', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/dashboard/);
    const casesLink = (page.viewportSize()?.width ?? 1280) < 1024
        ? page.getByRole('navigation', { name: 'Primary navigation' }).getByRole('link', { name: 'Cases', exact: true })
        : page.getByRole('complementary').getByRole('link', { name: 'Cases', exact: true });
    await casesLink.click();
    await expect(page).toHaveURL(/\/cases$/);
    await page.getByRole('link', { name: /DEMO-KLG-2026-0001/ }).click();
    await expect(page.getByRole('tab', { name: 'Client & Intake' })).toBeVisible();
    for (const tab of ['Safety & risk', 'Assessment', 'Case plan', 'Services & referrals', 'Progress notes', 'Closure & aftercare', 'Documents']) {
        await page.getByRole('tab', { name: tab }).click();
        await expect(page.getByRole('tab', { name: tab })).toHaveAttribute('aria-selected', 'true');
    }
    await expectAccessibleWorkspace(page);
});

test('cpu member sees only referred work and its permitted case workspace', async ({ page }) => {
    await login(page, cpu);
    await expect(page).toHaveURL(/mdt\/collaboration/);
    await expect(page.getByText('My assigned work')).toBeVisible();
    await expect(page.getByText('DEMO-KLG-2026-0001')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Cases', exact: true })).toHaveCount(0);
    await page.getByRole('link', { name: 'Open assignment', exact: true }).first().click();
    await expect(page).toHaveURL(/cases\/\d+\/mdt\/workspace/);
    await expectAccessibleWorkspace(page);
});
