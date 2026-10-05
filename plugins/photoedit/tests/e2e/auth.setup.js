// @ts-check
const { test: setup, expect } = require('@playwright/test');
const fs = require('fs');
const { statePath } = require('./support/accounts');

/**
 * Logs in each test account and saves its session.
 *
 * The credentials are the generated ones from local/config/photoedit-test.env -
 * never a human's login, and never a literal in this file.
 */
for (const role of ['WEBMASTER', 'ADMIN', 'NORMAL']) {
  setup(`authenticate ${role.toLowerCase()}`, async ({ page }) => {
    const username = process.env[`PHOTOEDIT_TEST_${role}_USERNAME`];
    const password = process.env[`PHOTOEDIT_TEST_${role}_PASSWORD`];

    if (!username || !password) {
      throw new Error(
        `Missing PHOTOEDIT_TEST_${role}_USERNAME / PHOTOEDIT_TEST_${role}_PASSWORD. ` +
          'Run `ddev exec php plugins/photoedit/tests/Support/create-test-users.php`, then source ' +
          'local/config/photoedit-test.env before running the E2E suite.'
      );
    }

    await page.goto('/identification.php');
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', password);
    await page.click('input[name="login"]');

    // The login form re-renders itself on failure, so its absence is the
    // signal that authentication took.
    await expect(page.locator('input[name="username"]')).toHaveCount(0);

    const file = statePath(role);
    fs.mkdirSync(require('path').dirname(file), { recursive: true });
    await page.context().storageState({ path: file });
  });
}
