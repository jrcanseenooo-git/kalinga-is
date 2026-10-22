import base from './playwright.foundation.config';
import { defineConfig } from '@playwright/test';
// Review-only copy: uses the pre-installed Chromium instead of the Chrome channel.
export default defineConfig({ ...base, use: { ...base.use, channel: undefined, launchOptions: { executablePath: '/opt/pw-browsers/chromium' } }, outputDir: 'test-results/review' });
