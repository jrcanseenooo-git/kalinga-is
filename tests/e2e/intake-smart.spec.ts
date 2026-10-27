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
    await expect(page.getByRole('img', { name: 'Photo of the signed form' })).toBeVisible();
    // The smart check reports on the picture either way.
    await expect(page.getByText(/Clear photo - ready to keep\.|Check this photo/)).toBeVisible();
    await expect(page.getByRole('checkbox', { name: 'Make the text clearer (black and white)' })).toBeVisible();
    await page.getByRole('button', { name: 'Remove photo', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Take photo', exact: true })).toBeVisible();
});
