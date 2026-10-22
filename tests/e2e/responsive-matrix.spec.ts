import { expect, test } from '@playwright/test';
import { expectNoHorizontalOverflow, login } from './helpers';

test.use({ serviceWorkers: 'block' });

for (const width of [360, 390, 768, 1440]) {
    test(`case-management shell remains usable at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: width < 800 ? 844 : 1000 });
        await login(page);
        await page.goto('/dashboard');
        await expect(page.getByRole('heading').first()).toBeVisible();
        await expectNoHorizontalOverflow(page);

        await page.goto('/cases');
        await expect(page.getByRole('link', { name: /DEMO-KLG-2026-0001/ })).toBeVisible();
        await expectNoHorizontalOverflow(page);

        await page.getByRole('link', { name: /DEMO-KLG-2026-0001/ }).click();
        await expect(page.getByRole('tab', { name: 'Client & Intake' })).toBeVisible();
        await expectNoHorizontalOverflow(page);
    });
}
