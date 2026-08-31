// @ts-check
const { test: setup, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const AUTH_FILE = path.join(__dirname, '.state', 'auth.json');
const ADMIN_AUTH_FILE = path.join(__dirname, '.state', 'auth-admin.json');

/**
 * Logs in and saves the session.
 *
 * The credentials are the generated ones from local/config/persons-test.env -
 * never a human's login, and never a literal in this file.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} role the role's half of the environment variable names
 * @param {string} file where the session is saved
 */
async function login(page, role, file) {
  const username = process.env[`PERSONS_TEST_${role}_USERNAME`];
  const password = process.env[`PERSONS_TEST_${role}_PASSWORD`];

  if (!username || !password) {
    throw new Error(
      `Missing PERSONS_TEST_${role}_USERNAME / PERSONS_TEST_${role}_PASSWORD. ` +
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

  fs.mkdirSync(path.dirname(file), { recursive: true });
  await page.context().storageState({ path: file });
}

/**
 * The account the public specs run as.
 *
 * Not the webmaster: the public overlay is what those specs are about, and it
 * is shown to any logged-in, non-guest user. Running as an administrator would
 * hide a permission mistake that only a normal account can find.
 */
setup('authenticate', async ({ page }) => {
  await login(page, 'NORMAL', AUTH_FILE);
});

/**
 * The account the admin screen specs run as.
 *
 * That screen sits behind check_status(ACCESS_ADMINISTRATOR), so it needs a
 * second session - and the refusal case needs the normal one above, which is
 * why both exist rather than one being given whatever rights the newest spec
 * happens to want.
 */
setup('authenticate as administrator', async ({ page }) => {
  await login(page, 'WEBMASTER', ADMIN_AUTH_FILE);
});
