// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { statePath } = require('./support/accounts');
const { seed, restore } = require('./support/seed');

/**
 * Who sees the edit button in the rendered page: every logged-in account that
 * may see the photo (decision 0059). The integration suite proves the same, and
 * the refusals, from the page source; this proves no script hides it afterwards.
 */

/** @type {ReturnType<typeof seed>} */
let fixture;

test.beforeEach(() => {
  restore();
  fixture = seed('photo');
});

test.afterEach(() => {
  restore();
});

for (const [label, state] of [
  ['the webmaster', statePath('WEBMASTER')],
  ['an administrator who is not a webmaster', statePath('ADMIN')],
  ['a normal user', statePath('NORMAL')],
]) {
  test(`[HAPPY] ${label} sees the edit button`, async ({ browser }) => {
    const context = await browser.newContext({ storageState: state });
    try {
      const picture = new PicturePage(await context.newPage());
      await picture.goto(fixture.picture_path);

      await expect(picture.editButton).toBeVisible();
      // Not "Bearbeiten": core's own edit button already carries that name.
      await expect(picture.editButton).toHaveText('Drehen/Zuschneiden');
    } finally {
      await context.close();
    }
  });
}
