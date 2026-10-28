import { expect, test } from '@playwright/test';
import { login } from './helpers';

// Simulated camera: Chromium's fake device gives a moving test picture.
test.use({ permissions: ['camera'], launchOptions: { executablePath: process.env.PW_CHROMIUM_PATH || '/opt/pw-browsers/chromium', args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] } });

test('Take photo opens the device camera in Kalinga and checks the picture', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await login(page); await page.goto('/cases/create');
    await page.getByRole('button', { name: 'Start new intake', exact: true }).click();
    let filePicker = false;
    page.on('filechooser', () => { filePicker = true; });
    await page.getByRole('button', { name: 'Take photo', exact: true }).click();
    const camera = page.getByRole('dialog', { name: 'Photograph the signed form' });
    await expect(camera).toBeVisible();
    await expect(camera.getByRole('button', { name: 'Close the camera' })).toBeFocused();
    const shutter = camera.getByRole('button', { name: 'Take the photo' });
    await expect(shutter).toBeEnabled();
    await shutter.click();
    await expect(camera).toHaveCount(0);
    expect(filePicker).toBe(false);
    await expect(page.getByRole('img', { name: 'Photo: Signed consent / assent form' })).toBeVisible();
    // The smart check reports on the picture either way.
    await expect(page.getByText(/Clear photo - ready to keep\.|Check this photo/)).toBeVisible();
    await expect(page.getByRole('checkbox', { name: 'Make the text clearer (black and white)' })).toBeVisible();
    await page.getByRole('button', { name: 'Remove photo', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Take photo', exact: true })).toBeVisible();
});

test('intake starts with address boxes unticked, labels the mobile number and never stacks empty family cards', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await login(page); await page.goto('/cases/create');
    await page.getByRole('button', { name: 'Start new intake', exact: true }).click();
    await page.getByRole('checkbox', { name: /I gave the privacy notice/ }).check();
    await page.getByRole('combobox', { name: /^How was the privacy notice given/ }).selectOption('explained_verbally');
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page.getByRole('checkbox', { name: /Permanent \(primary\) address is the same/ })).not.toBeChecked();
    await expect(page.locator('#intake-caregiver_same_address')).not.toBeChecked();
    // Child Contact Details: the child's own number, digits only, plus other ways to reach the child.
    await expect(page.getByRole('heading', { name: 'Child Contact Details' })).toBeVisible();
    await expect(page.locator('label[for="intake-phone"]')).toContainText("Child's mobile number");
    await page.locator('#intake-phone').fill('+63 917 123 4567');
    await expect(page.locator('#intake-phone')).toHaveValue('09171234567');
    await expect(page.locator('#intake-phone-hint')).toHaveText('Looks right: 11 digits.');
    await page.locator('#intake-phone').fill('0917');
    await expect(page.locator('#intake-phone-hint')).toHaveText('4 of 11 digits.');
    await page.locator('#intake-phone').fill('09171234567');
    await page.getByRole('button', { name: 'Add a way to reach the child' }).click();
    await expect(page.locator('#intake-channel-0-channel')).toHaveValue('Facebook Messenger');
    await page.locator('#intake-channel-0-handle').fill('fb.com/synthetic.child');
    await page.locator('#intake-first_name').fill('MARIA'); await page.locator('#intake-first_name').blur();
    await expect(page.locator('#intake-first_name')).toHaveValue('Maria');
    // Family composition: a second tap opens the same empty card instead of adding another.
    const add = page.getByRole('button', { name: 'Add family member', exact: true });
    await add.click(); await add.click();
    await expect(page.getByRole('button', { name: /^Family member \d/ })).toHaveCount(1);
    await expect(page.getByText('Family member 1 is still empty - fill it in first.')).toBeVisible();
    await expect(page.locator('#family-0-same_address, #family-0 input[type=checkbox]').first()).not.toBeChecked();
    // Closing the empty card removes it.
    await page.getByRole('button', { name: /^Family member 1/ }).click();
    await expect(page.getByRole('button', { name: /^Family member \d/ })).toHaveCount(0);
    // A half-filled member is not silently dropped.
    await add.click(); await page.locator('#family-0-name').fill('Pedro Cruz');
    await page.locator('#intake-last_name').fill('Cruz'); await page.locator('#intake-birthdate').fill('2012-03-04');
    await page.locator('#intake-sex').selectOption('Female');
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page.getByRole('alert', { name: 'Review intake errors' })).toContainText('Family member 1: choose the relationship to the child.');
    // The caregiver can be added to the family in one tap.
    await page.locator('#intake-primary_caregiver_name').fill('ana cruz'); await page.locator('#intake-primary_caregiver_name').blur();
    await page.getByRole('button', { name: /Add Ana Cruz.*the primary caregiver/ }).click();
    await expect(page.getByRole('button', { name: /^Ana Cruz/ })).toBeVisible();
});

test('Kalinga Assist opens from any page with tips, attention items and answers from the guides', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await login(page); await page.goto('/cases/create');
    await page.getByRole('button', { name: 'Open Kalinga Assist' }).click();
    const assist = page.getByRole('dialog', { name: 'Kalinga Assist' });
    await expect(assist).toBeVisible();
    await expect(assist).toContainText('Tips: Kalinga Intake');
    await expect(assist.getByText(/Checking\.\.\.|Needs your attention/).first()).toBeVisible();
    await assist.getByRole('searchbox', { name: 'Ask a question' }).fill('paano mag refer ng bata');
    await expect(assist.getByRole('heading', { name: 'Refer a child to a CPU member or an external agency' })).toBeVisible();
    await assist.getByRole('button', { name: 'Close', exact: true }).click();
    await expect(assist).toBeHidden();
    await expect(page.locator('#workspace-navigation').getByRole('link', { name: 'Office & signatories', exact: true })).toBeAttached();
});
