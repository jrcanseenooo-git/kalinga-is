import { defineConfig } from '@playwright/test';
import base from './playwright.foundation.config';

export default defineConfig({ ...base, testMatch: ['cpu-workspace.spec.ts'], outputDir: 'test-results/cpu-workspace' });
