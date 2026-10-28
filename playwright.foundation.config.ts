import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    testMatch: ['aftercare.spec.ts', 'calendar-team.spec.ts', 'foundation.spec.ts', 'offline.spec.ts', 'offline-sync.spec.ts', 'offline-entry.spec.ts', 'case-reports.spec.ts', 'intake-drafts.spec.ts', 'referral-workflow.spec.ts', 'provider-directory.spec.ts', 'intake-navigation.spec.ts', 'ui-transform.spec.ts', 'ui-planning.spec.ts', 'ui-interventions.spec.ts', 'ui-monitoring.spec.ts', 'ui-client-context.spec.ts', 'ui-conferences.spec.ts', 'closure-integrity.spec.ts', 'ui-closure.spec.ts', 'ui-connect.spec.ts', 'connect-private.spec.ts', 'cpu-workspace.spec.ts', 'kalinga-forms.spec.ts', 'analytics-dashboard.spec.ts', 'intake-smart.spec.ts'],
    workers: 1,
    timeout: 60_000,
    reporter: 'list',
    outputDir: 'test-results/foundation',
    use: {
        baseURL: 'http://127.0.0.1:8017',
        channel: 'chrome',
        serviceWorkers: 'block',
        screenshot: 'only-on-failure',
    },
    webServer: {
        command: 'node tests/e2e/foundation-server.mjs',
        url: 'http://127.0.0.1:8017/up',
        reuseExistingServer: false,
        timeout: 120_000,
    },
});

