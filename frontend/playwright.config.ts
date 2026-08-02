/**
 * Playwright config — single Chromium project, dev-server autostart.
 * Run with: npx playwright test
 */
import { defineConfig, devices } from '@playwright/test';

const externalBaseURL = process.env['SYNAPSE_E2E_BASE_URL'];
const baseURL = externalBaseURL ?? 'http://localhost:5173';

export default defineConfig({
  testDir: './e2e',
  // Live-stack E2E is stateful (shared dev account, audit rows, rate
  // limits) — run serially for determinism.
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env['CI'],
  retries: process.env['CI'] !== undefined ? 2 : 0,
  reporter: 'list',
  use: {
    baseURL,
    trace: 'on-first-retry',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  webServer: externalBaseURL === undefined
    ? {
        command: 'npm run dev',
        url: 'http://localhost:5173',
        reuseExistingServer: !process.env['CI'],
        timeout: 60_000,
      }
    : undefined,
});
