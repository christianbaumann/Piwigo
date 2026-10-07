// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * E2E configuration for the photoinfo UI.
 *
 * At the plugin root so `cd plugins/photoinfo && npx playwright test` needs no
 * --config. retries: 0 and workers: 1 are deliberate: a flaky test gets fixed,
 * never retried into green, and every spec seeds the same database.
 *
 * Specs run as the webmaster by default; a spec about another account sets its
 * own storage state.
 */
module.exports = defineConfig({
  testDir: './tests/e2e',
  retries: 0,
  workers: 1,
  fullyParallel: false,
  forbidOnly: true,
  timeout: 30_000,
  expect: { timeout: 5_000 },
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.PHOTOINFO_TEST_BASE_URL || 'http://localhost',
    trace: 'on',
    screenshot: 'only-on-failure',
    actionTimeout: 10_000,
    navigationTimeout: 15_000,
  },
  projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.js/,
    },
    {
      name: 'chromium',
      testMatch: /.*\.spec\.js/,
      dependencies: ['setup'],
      use: {
        ...devices['Desktop Chrome'],
        storageState: 'tests/e2e/.state/auth-webmaster.json',
      },
    },
  ],
});
