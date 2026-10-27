import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { expectNoHorizontalOverflow, login } from './helpers';

/** Phase 6: to-do dashboard and the drill-down analytics map (self-hosted boundaries). */
for (const width of [390, 1280]) {
    test(`dashboard is a to-do list and analytics drills down at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        const external: string[] = [];
        page.on('request', request => { const url = new URL(request.url()); if (!['127.0.0.1', 'localhost'].includes(url.hostname)) external.push(url.hostname); });
        await login(page);
        await page.goto('/dashboard');
        await expect(page.getByRole('heading', { name: /your to-do list/i })).toBeVisible();
        await expect(page.getByText('Case intake trend')).toHaveCount(0);
        await page.screenshot({ path: test.info().outputPath('dashboard.png'), fullPage: true });
        await expectNoHorizontalOverflow(page);
        let scan = await new AxeBuilder({ page }).include('main').analyze();
        expect(scan.violations.filter(item => ['critical', 'serious'].includes(item.impact ?? ''))).toEqual([]);

        await page.goto('/analytics');
        const map = page.getByRole('region', { name: /Philippines/ }).or(page.locator('section[aria-labelledby="drill-map-title"]'));
        await expect(page.locator('section[aria-labelledby="drill-map-title"] svg[role="img"]')).toBeVisible({ timeout: 15000 });
        const first = page.locator('section[aria-labelledby="drill-map-title"] ul button').first();
        const name = (await first.locator('span').first().textContent())?.trim() ?? '';
        await first.click();
        await expect(page.getByRole('navigation', { name: 'Area' })).toContainText(name);
        await expect(page.locator('section[aria-labelledby="drill-map-title"] h2')).toContainText(name);
        await page.screenshot({ path: test.info().outputPath('analytics-drilled.png'), fullPage: true });
        await expectNoHorizontalOverflow(page);
        scan = await new AxeBuilder({ page }).include('main').analyze();
        expect(scan.violations.filter(item => ['critical', 'serious'].includes(item.impact ?? ''))).toEqual([]);
        await page.getByRole('button', { name: 'Up one level' }).click();
        await expect(page.getByRole('navigation', { name: 'Area' })).not.toContainText(name);
        expect(external, 'No outside map or tile service is contacted').toEqual([]);
        void map;
    });
}
