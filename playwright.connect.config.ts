import { defineConfig } from '@playwright/test';
import base from './playwright.foundation.config';

// Uses a fresh synthetic database and the installed Chrome channel on Windows.
export default defineConfig({
    ...base,
    testMatch: ['ui-connect.spec.ts', 'connect-private.spec.ts'],
    outputDir: 'test-results/connect',
});
