// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * E2E configuration for the persons UI.
 *
 * Lives at the plugin root rather than in tests/e2e/ so the documented command
 * - `cd plugins/persons && npx playwright test` - finds it with no --config
 * flag. testDir points at the specs.
 *
 * retries: 0 and workers: 1 are deliberate. A flaky test gets fixed or made
 * deterministic; it is never retried into green. Single worker because every
 * spec seeds and restores the same rows in the same database.
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
    baseURL: process.env.PERSONS_TEST_BASE_URL || 'http://localhost',
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
      testIgnore: /admin\.spec\.js/,
      dependencies: ['setup'],
      use: {
        ...devices['Desktop Chrome'],
        storageState: 'tests/e2e/.state/auth.json',
      },
    },
    /* The admin tagging screen is behind ACCESS_ADMINISTRATOR, so its specs
       need the webmaster session rather than the normal one. The spec that
       proves a normal account is refused overrides the state for itself. */
    {
      name: 'chromium-admin',
      testMatch: /admin\.spec\.js/,
      dependencies: ['setup'],
      use: {
        ...devices['Desktop Chrome'],
        storageState: 'tests/e2e/.state/auth-admin.json',
      },
    },
  ],
});
