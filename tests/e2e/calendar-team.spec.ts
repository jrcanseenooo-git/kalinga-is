import { expect, test } from '@playwright/test';
import { expectNoHorizontalOverflow, login } from './helpers';

/** Calendar: invite people by name or role; the supervisor sees the case managers' activities and reviews moves. */
test('case manager finds the supervisor and CPU members in Invite people', async ({ page }) => {
    await login(page);
    await page.goto('/calendar');
    await page.getByRole('button', { name: 'Add activity', exact: true }).click();
    const invite = page.locator('#event-invite');
    await invite.fill('supervisor');
    await expect(page.locator('#event-invite-results')).toContainText('Kalinga Supervisor');
    await invite.fill('kalinga cpu');
    await expect(page.locator('#event-invite-results')).toContainText('Kalinga CPU Member');
});

for (const width of [390, 1280]) test(`supervisor sees a moved activity of the case manager and marks it reviewed at ${width}px`, async ({ page, browser }) => {
    await page.setViewportSize({ width, height: 900 });
    await login(page);
    await page.goto('/calendar');
    await page.getByRole('button', { name: 'Add activity', exact: true }).click();
    const title = `Synthetic home visit ${width}-${Date.now()}`;
    await page.locator('#event-title').fill(title);
    const due = new Date(Date.now() + 60 * 60000).toLocaleString('sv-SE', { timeZone: 'Asia/Manila' }).slice(0, 16).replace(' ', 'T');
    await page.locator('#event-when').fill(due);
    await page.getByRole('button', { name: 'Add to calendar' }).click();
    await expect(page.getByRole('dialog')).toBeHidden();
    // Move it with a reason.
    await page.getByRole('group', { name: 'Calendar view' }).getByRole('button').nth(1).click();
    await page.locator('.agenda-item').filter({ hasText: title }).first().click();
    await page.getByRole('dialog').getByRole('button', { name: 'Edit' }).click();
    const later = new Date(Date.now() + 26 * 3600000).toLocaleString('sv-SE', { timeZone: 'Asia/Manila' }).slice(0, 16).replace(' ', 'T');
    await page.locator('#event-when').fill(later);
    await page.locator('#event-reason').fill('I could not go that day.');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('dialog')).toBeHidden();
    await page.goto('/dashboard');
    await expect(page.getByRole('region', { name: 'Activities you moved' }).or(page.locator('section[aria-labelledby="todo-moves"]'))).toContainText(title);

    const context = await browser.newContext({ viewport: { width, height: 900 } });
    const supervisor = await context.newPage();
    await login(supervisor, { email: 'supervisor@kalinga.test', password: 'password' });
    await supervisor.goto('/dashboard');
    const moves = supervisor.locator('section[aria-labelledby="todo-moves"]');
    const item = moves.getByRole('listitem').filter({ hasText: title });
    await expect(item).toContainText('I could not go that day.');
    await expectNoHorizontalOverflow(supervisor);
    await item.getByRole('button', { name: 'Mark reviewed' }).click();
    await expect(moves.getByRole('listitem').filter({ hasText: title })).toHaveCount(0);
    await supervisor.goto('/calendar');
    await expect(supervisor.getByText("Show my case managers' activities")).toBeVisible();
    await context.close();
});
