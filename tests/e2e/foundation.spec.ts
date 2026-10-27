import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { login, expectNoHorizontalOverflow } from './helpers';

test('Connect loads older authorized history and searches without query text in URLs', async ({ page }) => {
    await login(page, { email: 'cpu@kalinga.test', password: 'password' });
    await page.goto('/cpu/coordination');
    const messages = page.getByRole('region', { name: 'Coordination messages', exact: true });
    await expect(messages.getByRole('article')).toHaveCount(50);
    await expect(messages).not.toContainText('Synthetic older history sentinel');
    await page.getByRole('button', { name: 'Load earlier messages', exact: true }).click();
    await expect(messages).toContainText('Synthetic older history sentinel');
    await expect(page.getByRole('button', { name: 'Load earlier messages', exact: true })).toHaveCount(0);
    await page.getByLabel('Search authorized coordination messages', { exact: true }).fill('older history sentinel');
    const responsePromise = page.waitForResponse(response => /\/search$/.test(new URL(response.url()).pathname));
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    const response = await responsePromise;
    expect(response.request().method()).toBe('POST');
    expect(new URL(response.url()).search).toBe('');
    expect(response.status()).toBe(200);
    await expect(messages.getByRole('article')).toHaveCount(1);
    await expect(messages).toContainText('Synthetic older history sentinel');
});

for (const width of [360, 768, 1440]) test(`Connect sends a JSON-backed message and retains it after reload at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 });
    await login(page, { email: 'cpu@kalinga.test', password: 'password' });
    await page.goto('/cpu/coordination');
    // The demo setup gives the CPU member the City of Tabuk circle; the fixture adds its synthetic history there.
    await expect(page.getByLabel('Coordination circle')).toContainText('City of Tabuk CPU Coordination Circle');
    const body = `Synthetic operational follow-up ${Date.now()}`;
    await page.getByLabel('New message', { exact: true }).fill(body);
    await page.getByRole('button', { name: 'Send message', exact: true }).click();
    const confirmation = page.locator('#generic-submit-confirm');
    if (await confirmation.isVisible()) await confirmation.click();
    await expect(page.getByLabel('New message', { exact: true })).toHaveValue('');
    await expect(page.getByRole('region', { name: 'Coordination messages', exact: true })).toContainText(body);
    await page.reload();
    await expect(page.getByRole('region', { name: 'Coordination messages', exact: true })).toContainText(body);
    await expectNoHorizontalOverflow(page);
    await expect(page.locator('#nprogress')).toHaveCount(0);
    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations.filter(item => ['serious', 'critical'].includes(item.impact ?? ''))).toEqual([]);
    await page.screenshot({ path: `test-results/foundation/connect-${width}.png`, fullPage: true });
});

test('Connect keeps a failed send in the composer and allows recovery', async ({ page }) => {
    await login(page, { email: 'cpu@kalinga.test', password: 'password' });
    await page.goto('/cpu/coordination');
    await page.getByLabel('New message', { exact: true }).fill('Synthetic connection recovery');
    await page.route('**/cpu/coordination/*/messages', route => route.request().method() === 'POST' ? route.abort('internetdisconnected') : route.continue());
    await page.getByRole('button', { name: 'Send message', exact: true }).click();
    await page.locator('#generic-submit-confirm').click();
    await expect(page.getByRole('alert')).toContainText('Check your connection');
    await expect(page.getByLabel('New message', { exact: true })).toHaveValue('Synthetic connection recovery');
    await page.unroute('**/cpu/coordination/*/messages');
    // An unconfirmed send is never retried automatically: refresh, review, then allow the send.
    await page.getByRole('button', { name: 'Refresh conversation', exact: true }).click();
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Review complete - allow send', exact: true }).click();
    await page.getByRole('button', { name: 'Send message', exact: true }).click();
    await page.locator('#generic-submit-confirm').click();
    await expect(page.getByRole('region', { name: 'Coordination messages', exact: true })).toContainText('Synthetic connection recovery');
});

test('Connect records only a reviewed summary through the active MDT grant', async ({ page }) => {
    await login(page, { email: 'cpu@kalinga.test', password: 'password' });
    const caseHref = await page.getByRole('link', { name: 'Open assignment', exact: true }).first().getAttribute('href');
    const caseId = caseHref!.match(/cases\/(\d+)\//)![1];
    await page.goto('/cpu/coordination');
    await page.getByLabel('New message', { exact: true }).fill('Synthetic operational capture source');
    await page.getByRole('button', { name: 'Send message', exact: true }).click();
    await page.locator('#generic-submit-confirm').click();
    const article = page.getByRole('article').filter({ hasText: 'Synthetic operational capture source' });
    await article.getByRole('button', { name: 'Record as note' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    const reference = dialog.getByRole('combobox', { name: 'Authorized case reference', exact: true });
    await expect.poll(() => reference.locator(`option[value="${caseId}"]`).count()).toBe(1);
    await reference.selectOption(caseId);
    await dialog.getByLabel('Record type').selectOption('mdt_note');
    await dialog.getByLabel('Professional summary').fill('Synthetic reviewed MDT summary');
    await dialog.getByRole('checkbox').check();
    const responsePromise = page.waitForResponse(response => /\/capture$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST');
    await dialog.getByRole('button', { name: 'Confirm record creation' }).click();
    const confirmation = page.locator('#generic-submit-confirm');
    if (await confirmation.isVisible()) await confirmation.click();
    expect((await responsePromise).status()).toBe(201);
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole('status')).toContainText('Reviewed summary recorded');
});

for (const width of [360, 768, 1440]) {
    test(`flat shell keyboard and navigation at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await login(page);
        await expect(page.getByRole('heading', { name: /your to-do list/i })).toBeVisible();
        await expectNoHorizontalOverflow(page);
        const sidebar = page.locator('#workspace-navigation');
        const toggle = page.locator('[aria-controls="workspace-navigation"]');
        if (width < 1024) {
            await expect(sidebar).toHaveAttribute('inert', '');
            await toggle.click();
            await expect(sidebar.getByRole('button', { name: 'Close navigation' })).toBeFocused();
            await page.keyboard.press('Shift+Tab');
            await expect(sidebar.getByRole('button', { name: 'Log out' })).toBeFocused();
            await page.keyboard.press('Escape');
            await expect(toggle).toBeFocused();
            await toggle.click();
        }
        await expect(sidebar.getByRole('link', { name: 'Kalinga Connect', exact: true })).toHaveAttribute('href', /\/cpu\/coordination$/);
        await expect(sidebar.getByRole('link', { name: 'Office profile & signatories', exact: true })).toHaveAttribute('href', /\/lgu-profile$/);
        const calendar = sidebar.getByRole('link', { name: 'Calendar', exact: true });
        await expect(calendar).toHaveAttribute('href', /\/calendar$/);
        await calendar.click();
        await expect(page).toHaveURL(/\/calendar$/);
        await expectNoHorizontalOverflow(page);
        if (width < 1024) await toggle.click();
        await sidebar.getByRole('button', { name: 'Log out' }).click();
        await expect(page.getByRole('dialog', { name: 'Sign out of Kalinga' })).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.getByRole('dialog')).not.toBeVisible();
        await page.goto('/dashboard');
        // Keyboard users can only tab once the app has rendered; wait for it.
        await page.getByRole('link', { name: 'Skip to main content' }).waitFor({ state: 'attached' });
        await page.keyboard.press('Tab');
        await expect(page.getByRole('link', { name: 'Skip to main content' })).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(page.locator('#main-content')).toBeFocused();
        await expect(page.locator('#nprogress')).toHaveCount(0);
        const results = await new AxeBuilder({ page }).analyze();
        expect(results.violations.filter(item => ['serious', 'critical'].includes(item.impact ?? ''))).toEqual([]);
        await page.screenshot({ path: `test-results/foundation/shell-${width}.png`, fullPage: true });
    });
}

test('CPU member can reach Connect independently from referred cases', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await login(page, { email: 'cpu@kalinga.test', password: 'password' });
    const sidebar = page.locator('#workspace-navigation');
    await expect(sidebar.getByRole('link', { name: 'My referred cases' })).toHaveAttribute('href', /\/mdt\/collaboration$/);
    await sidebar.getByRole('link', { name: 'Kalinga Connect', exact: true }).click();
    await expect(page).toHaveURL(/\/cpu\/coordination$/);
    await expect(sidebar.getByRole('link', { name: 'Cases', exact: true })).toHaveCount(0);
});

for (const width of [360, 768, 1440]) {
    test(`aggregate analytics suppression is readable at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await login(page, { email: 'admin@kalinga.test', password: 'password' });
        for (const url of ['/analytics', '/quarterly-monitoring']) {
            await page.goto(url);
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            await expect(page.getByText('Suppressed', { exact: true }).first()).toBeVisible();
            await expectNoHorizontalOverflow(page);
            if (url === '/analytics') {
                const card = page.locator('article').filter({ has: page.getByRole('heading', { name: 'Monthly intake', exact: true }) });
                await card.getByText('View data table', { exact: true }).click();
                const monthly = card.getByRole('table');
                await expect(monthly.getByRole('row')).toHaveCount(7);
                const suppressedRows = monthly.getByRole('row').filter({ hasText: 'Suppressed' });
                expect(await suppressedRows.count()).toBeGreaterThan(0);
                await expect(suppressedRows.locator('[aria-hidden="true"]')).toHaveCount(0);
            }
            await expect(page.locator('[style*="NaN"]')).toHaveCount(0);
            const accessibility = await new AxeBuilder({ page }).analyze();
            expect(accessibility.violations.filter(item => ['serious', 'critical'].includes(item.impact ?? ''))).toEqual([]);
            await page.screenshot({ path: `test-results/foundation/aggregate-${url.slice(1)}-${width}.png`, fullPage: true });
        }
    });
}

for (const width of [360, 768, 1440]) {
    test(`working report draft and download at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await login(page);
        await page.goto('/cases?search=DEMO-KLG');
        await page.getByRole('link', { name: /DEMO-KLG-/ }).first().click();
        await expect(page).toHaveURL(/\/cases\/\d+$/);
        const reportUrl = `${new URL(page.url()).pathname}/social-case-management-report`;
        const response = await page.goto(reportUrl);
        expect(response?.status()).toBe(200);
        expect(response?.headers()['cache-control']).toContain('no-store');
        await expect(page.getByText('DRAFT - Not approved for official release', { exact: true })).toBeVisible();
        await expect(page.getByText('working-summary-v1', { exact: true })).toBeVisible();
        await expectNoHorizontalOverflow(page);
        const download = page.waitForEvent('download');
        await page.getByRole('link', { name: 'Download draft CSV' }).click();
        expect((await download).suggestedFilename()).toMatch(/^draft-social-case-management-/);
        await expect(page.locator('#nprogress')).toHaveCount(0);
        const results = await new AxeBuilder({ page }).analyze();
        expect(results.violations.filter(item => ['serious', 'critical'].includes(item.impact ?? ''))).toEqual([]);
        await page.screenshot({ path: `test-results/foundation/draft-report-${width}.png`, fullPage: true });
    });
}

for (const width of [360, 768, 1440]) {
    test(`worker to-do list drills down at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await login(page);
        await expect(page.getByRole('heading', { name: /your to-do list/i })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'My cases requiring action' })).toBeVisible();
        await expectNoHorizontalOverflow(page);
        await expect(page.locator('#nprogress')).toHaveCount(0);
        const results = await new AxeBuilder({ page }).analyze();
        expect(results.violations.filter(item => ['serious', 'critical'].includes(item.impact ?? ''))).toEqual([]);
        await page.screenshot({ path: `test-results/foundation/worker-todo-${width}.png`, fullPage: true });
        await page.getByRole('region', { name: 'Coming up' }).getByRole('link', { name: 'Calendar', exact: true }).click();
        await expect(page).toHaveURL(/\/calendar$/);
        await expect(page.getByRole('heading', { name: 'Calendar', exact: true })).toBeVisible();
        await expectNoHorizontalOverflow(page);
        await page.goto('/dashboard');
        await page.getByRole('navigation', { name: 'Shortcuts' }).getByRole('link', { name: /My cases/ }).click();
        await expect(page).toHaveURL(/\/cases$/);
        await expectNoHorizontalOverflow(page);
    });
}

for (const width of [360, 768, 1440]) {
    test(`case list filters persist and remain accessible at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await login(page);
        await page.goto('/cases');
        await page.getByRole('button', { name: 'Filters' }).click();
        await page.getByLabel('Priority', { exact: true }).selectOption('high');
        await page.getByLabel('Sort cases', { exact: true }).selectOption('opened_asc');
        await page.getByRole('button', { name: 'Search', exact: true }).click();
        await expect(page).toHaveURL(/priority=high/);
        await expect(page).toHaveURL(/sort=opened_asc/);
        await expect(page.getByLabel('Priority', { exact: true })).toHaveValue('high');
        await expectNoHorizontalOverflow(page);
        await expect(page.locator('#nprogress')).toHaveCount(0);
        const results = await new AxeBuilder({ page }).analyze();
        expect(results.violations.filter(item => ['serious', 'critical'].includes(item.impact ?? ''))).toEqual([]);
        await page.screenshot({ path: `test-results/foundation/case-filters-${width}.png`, fullPage: true });
        await page.getByRole('link', { name: 'Clear filters', exact: true }).click();
        await expect(page).toHaveURL(/\/cases$/);
        await expect(page.getByLabel('Priority', { exact: true })).toHaveValue('');
    });
}

test.describe('Philippine task schedule', () => {
    test.use({ timezoneId: 'America/New_York' });
    test('calendar keeps Philippine due date even on a different device timezone', async ({ page }) => {
        const today = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
        const nextDay = new Date(`${today}T00:00:00Z`);
        nextDay.setUTCDate(nextDay.getUTCDate() + 1);
        const tomorrow = nextDay.toISOString().slice(0, 10);
        await page.setViewportSize({ width: 390, height: 1000 });
        await login(page);
        await page.goto('/calendar');
        await page.getByRole('button', { name: 'Add activity', exact: true }).first().click();
        const dialog = page.getByRole('dialog', { name: 'Add activity' });
        await dialog.getByLabel('Case (optional)', { exact: true }).selectOption({ index: 1 });
        await dialog.getByRole('textbox', { name: 'Title', exact: true }).fill('Synthetic Philippine boundary task');
        await dialog.getByRole('textbox', { name: 'Date and time (Philippine time)', exact: true }).fill(`${tomorrow}T01:00`);
        const response = page.waitForResponse(item => new URL(item.url()).pathname === '/tasks' && item.request().method() === 'POST');
        await dialog.getByRole('button', { name: 'Add to calendar', exact: true }).click();
        expect((await response).status()).toBeLessThan(400);
        await expect(dialog).not.toBeVisible();
        if (today.slice(0, 7) !== tomorrow.slice(0, 7)) await page.getByRole('button', { name: 'Next month', exact: true }).click();
        await page.getByRole('button', { name: 'Agenda', exact: true }).click();
        const item = page.locator('.agenda-item', { hasText: 'Synthetic Philippine boundary task' });
        await expect(item).toContainText('1:00');
        await expectNoHorizontalOverflow(page);
        await page.screenshot({ path: 'test-results/foundation/philippine-calendar.png', fullPage: true });
    });
});

for (const width of [360, 768, 1440]) {
    test(`case workspace recorded actions and keyboard tabs at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await login(page);
        await page.goto('/cases?search=DEMO-KLG');
        await page.getByRole('link', { name: /DEMO-KLG-/ }).first().click();
        await expect(page).toHaveURL(/\/cases\/\d+$/);
        const documentResponse = await page.request.get(new URL(page.url()).pathname);
        const documentHtml = await documentResponse.text();
        const encodedPage = documentHtml.match(/data-page="([^"]+)"/)?.[1];
        expect(encodedPage).toBeTruthy();
        const pageData = JSON.parse(encodedPage!.replaceAll('&quot;', '"').replaceAll('&#039;', "'").replaceAll('&amp;', '&'));
        const response = await page.request.get(new URL(page.url()).pathname, { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': pageData.version } });
        expect(response.status()).toBe(200);
        const { props } = await response.json();
        const openTasks = props.caseRecord.status === 'CLOSED' ? [] : props.tasks.filter((task: { status: string }) => task.status === 'open');
        openTasks.sort((left: { due_at: string | null; id: number }, right: { due_at: string | null; id: number }) =>
            (left.due_at ? Date.parse(left.due_at) : Infinity) - (right.due_at ? Date.parse(right.due_at) : Infinity) || left.id - right.id);
        const workspace = page.getByRole('region', { name: 'Case workspace sections' });
        if (openTasks.length) {
            await expect(workspace).toContainText('Next recorded task');
            await expect(workspace).toContainText(openTasks[0].title);
            await workspace.getByRole('button', { name: 'View case tasks', exact: true }).click();
            await expect(page.locator('#case-open-tasks')).toBeFocused();
        } else await expect(workspace).toContainText('Next step');
        const tabs = page.getByRole('tablist', { name: 'Case record sections' });
        await tabs.getByRole('tab', { name: 'Overview', exact: true }).click();
        await page.keyboard.press('ArrowRight');
        await expect(tabs.getByRole('tab', { name: 'Client & Intake', exact: true })).toBeFocused();
        await expect(tabs.getByRole('tab', { name: 'Client & Intake', exact: true })).toHaveAttribute('aria-selected', 'true');
        await page.keyboard.press('End');
        await expect(tabs.getByRole('tab', { name: 'Documents', exact: true })).toBeFocused();
        await page.keyboard.press('Home');
        await expect(tabs.getByRole('tab', { name: 'Overview', exact: true })).toBeFocused();
        await expectNoHorizontalOverflow(page);
        await expect(page.locator('#nprogress')).toHaveCount(0);
        const results = await new AxeBuilder({ page }).include('[aria-label="Case workspace sections"]').analyze();
        expect(results.violations.filter(item => ['serious', 'critical'].includes(item.impact ?? ''))).toEqual([]);
        await page.screenshot({ path: `test-results/foundation/case-workspace-${width}.png`, fullPage: true });
    });
}



