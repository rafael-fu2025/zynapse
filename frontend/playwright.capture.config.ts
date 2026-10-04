/**
 * Capture config — screen-recording harness for the working-tree updates.
 *
 * Deliberately separate from `playwright.config.ts`:
 *
 *  - `testDir` is `./capture`, not `./e2e`, so a plain `npx playwright test`
 *    (and therefore CI) never picks these up. This harness takes screenshots
 *    and writes an evidence folder; it is not an assertion suite and must not
 *    gate a build.
 *  - Reuses the same dev-server autostart and Chromium project as the e2e
 *    config so a capture can never disagree with the suite about the target.
 *
 * Run:
 *   SYNAPSE_E2E=1 SYNAPSE_E2E_EMAIL=... SYNAPSE_E2E_PASSWORD=... \
 *     npx playwright test -c playwright.capture.config.ts
 */
import { defineConfig, devices } from '@playwright/test';

const externalBaseURL = process.env['SYNAPSE_E2E_BASE_URL'];
const baseURL = externalBaseURL ?? 'http://localhost:5173';

export default defineConfig({
  testDir: './capture',
  // The live stack is stateful (shared dev account, audit rows, rate limits) —
  // run serially, same reasoning as the e2e config.
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  // Generous: cold PHP dev server + a fresh Vite chunk per route.
  timeout: 180_000,
  expect: { timeout: 20_000 },
  outputDir: './capture/.artifacts',
  use: {
    baseURL,
    // Default capture resolution. Element close-ups raise this per-shot via
    // `deviceScaleFactor` at context creation — see capture/recorder.ts.
    viewport: { width: 1440, height: 900 },
    // Keep the trace off; the recorder already writes an explicit evidence
    // folder, and traces would duplicate it in bulk.
    trace: 'off',
    video: 'off',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
    },
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
