import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000';
const e2eEnvironment = {
    ...process.env,
    APP_ENV: 'testing',
    APP_DEBUG: 'false',
    MFA_ENFORCEMENT: 'false',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.resolve('database/e2e.sqlite'),
    CACHE_STORE: 'array',
    SESSION_DRIVER: 'file',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
};

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    timeout: 120_000,
    reporter: [['list'], ['html', { open: 'never' }]],
    globalSetup: './tests/e2e/global-setup.ts',
    use: {
        baseURL,
        channel: 'chrome',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        // Keep browser verification runnable in restricted/offline field-build
        // environments where Playwright's optional ffmpeg helper is unavailable.
        // Traces and screenshots still preserve actionable failure evidence.
        video: 'off',
        ignoreHTTPSErrors: false,
        navigationTimeout: 45_000,
    },
    projects: [
        { name: 'desktop-chrome', use: { ...devices['Desktop Chrome'], channel: 'chrome' } },
        { name: 'mobile-chrome', use: { ...devices['Pixel 7'], channel: 'chrome' } },
    ],
    webServer: process.env.PLAYWRIGHT_BASE_URL ? undefined : [
        {
            command: 'php artisan serve --host=127.0.0.1 --port=8000 --no-reload',
            url: `${baseURL}/up`,
            reuseExistingServer: false,
            timeout: 120_000,
            env: e2eEnvironment,
        },
        {
            command: 'npm run dev -- --host 127.0.0.1',
            url: 'http://127.0.0.1:5173/@vite/client',
            reuseExistingServer: false,
            timeout: 120_000,
        },
    ],
});
