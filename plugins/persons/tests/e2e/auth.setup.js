// @ts-check
const { test: setup, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const AUTH_FILE = path.join(__dirname, '.state', 'auth.json');

/**
 * Logs in once as the plugin's own normal account and saves the session.
 *
 * Not the webmaster: the public overlay is what these specs are about, and it
 * is shown to any logged-in, non-guest user. Running as an administrator would
 * hide a permission mistake that only a normal account can find.
 *
 * The credentials are the generated ones from local/config/persons-test.env -
 * never a human's login, and never a literal in this file.
 */
setup('authenticate', async ({ page }) => {
  const username = process.env.PERSONS_TEST_NORMAL_USERNAME;
  const password = process.env.PERSONS_TEST_NORMAL_PASSWORD;

  if (!username || !password) {
    throw new Error(
      'Missing PERSONS_TEST_NORMAL_USERNAME / PERSONS_TEST_NORMAL_PASSWORD. ' +
        'Run `ddev exec php plugins/persons/tests/Support/create-test-users.php`, then source ' +
        'local/config/persons-test.env before running the E2E suite.'
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
