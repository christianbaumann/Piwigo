// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { statePath } = require('./support/accounts');
const { seed, restore } = require('./support/seed');

/**
 * Who sees the edit button in the rendered page. The integration suite proves
 * the same from the page source; this proves no script adds it afterwards.
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

test('[HAPPY] the webmaster sees the edit button', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);

  await expect(picture.editButton).toBeVisible();
  // Not "Bearbeiten": core's own edit button already carries that name.
  await expect(picture.editButton).toHaveText('Drehen/Zuschneiden');
});

for (const [label, state] of [
  ['an administrator who is not a webmaster', statePath('ADMIN')],
  ['a normal user', statePath('NORMAL')],
  ['a guest', undefined],
]) {
  test(`[NEG] ${label} has no edit button`, async ({ browser }) => {
    const context = await browser.newContext({ storageState: state ?? { cookies: [], origins: [] } });
    try {
      const picture = new PicturePage(await context.newPage());
      await picture.goto(fixture.picture_path);

      await expect(picture.editButton).toHaveCount(0);
      await expect(picture.controls).toHaveCount(0);
    } finally {
      await context.close();
    }
  });
}
