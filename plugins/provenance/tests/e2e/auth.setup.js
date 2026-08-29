// @ts-check
const { test: setup, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const AUTH_FILE = path.join(__dirname, '.state', 'auth.json');

/**
 * Logs in once as the plugin's own webmaster account and saves the session.
 *
 * The album properties screen is admin-only, so every spec needs this. The
 * credentials are the generated ones from local/config/provenance-test.env -
 * never a human's login, and never a literal in this file.
 */
setup('authenticate', async ({ page }) => {
  const username = process.env.PROVENANCE_TEST_WEBMASTER_USERNAME;
  const password = process.env.PROVENANCE_TEST_WEBMASTER_PASSWORD;

  if (!username || !password) {
    throw new Error(
      'Missing PROVENANCE_TEST_WEBMASTER_USERNAME / PROVENANCE_TEST_WEBMASTER_PASSWORD. ' +
        'Run `ddev exec php plugins/provenance/tests/Support/create-test-users.php`, then source ' +
        'local/config/provenance-test.env before running the E2E suite.'
    );
  }

  await page.goto('/identification.php');
  await page.fill('input[name="username"]', username);
  await page.fill('input[name="password"]', password);
  await page.click('input[name="login"]');

  // The login form re-renders itself on failure, so reaching a page without it
  // is the signal that authentication actually took - not merely that a
  // navigation happened.
  await expect(page.locator('input[name="username"]')).toHaveCount(0);

  fs.mkdirSync(path.dirname(AUTH_FILE), { recursive: true });
  await page.context().storageState({ path: AUTH_FILE });
});
