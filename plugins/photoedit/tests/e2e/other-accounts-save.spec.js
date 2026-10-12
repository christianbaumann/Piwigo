// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { statePath } = require('./support/accounts');
const { seed, restore } = require('./support/seed');

/**
 * An account that is not the webmaster saves an edit through the same editor,
 * with the same questions first (decision 0059). What the server reports to
 * each role is the integration suite's (ApplyTurnTest, ApplyJpegTest,
 * ApplyRegionsTest); this covers the dialogs and the reload in the browser.
 */

/** toBeCloseTo's digits for a frame edge placed by a mouse drag. */
const RATIO_TOLERANCE_DIGITS = 1;

test.afterEach(() => {
  restore();
});

/**
 * @param {import('@playwright/test').Browser} browser
 * @param {'ADMIN'|'NORMAL'} role
 * @param {(picture: PicturePage) => Promise<void>} body
 */
async function as(browser, role, body) {
  const context = await browser.newContext({ storageState: statePath(role) });
  try {
    await body(new PicturePage(await context.newPage()));
  } finally {
    await context.close();
  }
}

for (const role of /** @type {const} */ (['NORMAL', 'ADMIN'])) {
  test(`[HAPPY] ${role.toLowerCase()}: a JPEG turn asks about the re-encode, then saves`, async ({ browser }) => {
    restore();
    const fixture = seed('jpeg');
    await as(browser, role, async (picture) => {
      /** @type {string[]} */
      const dialogs = [];
      picture.page.on('dialog', (dialog) => {
        dialogs.push(dialog.type());
        dialog.accept();
      });
      await picture.goto(fixture.picture_path);
      await picture.editButton.click();
      await picture.turnRightButton.click();

      await picture.saveAndWaitForReload();

      expect(dialogs).toEqual(['confirm']);
      const size = await picture.naturalSize();
      expect(size.height).toBeGreaterThan(size.width);
    });
  });

  test(`[HAPPY] ${role.toLowerCase()}: a crop that cuts a person away warns by name, then saves`, async ({ browser }) => {
    restore();
    const fixture = seed('regions');
    await as(browser, role, async (picture) => {
      /** @type {string[]} */
      const messages = [];
      picture.page.on('dialog', (dialog) => {
        messages.push(dialog.message());
        dialog.accept();
      });
      await picture.goto(fixture.picture_path);
      await picture.editButton.click();
      await picture.dragCropEdge('e', 0.5);

      await picture.saveAndWaitForReload();

      expect(messages).toHaveLength(1);
      expect(messages[0]).toContain(fixture.cut_name);
      expect(messages[0]).not.toContain(fixture.kept_name);
      const size = await picture.naturalSize();
      expect(size.width / size.height).toBeCloseTo((fixture.width / 2) / fixture.height, RATIO_TOLERANCE_DIGITS);
    });
  });
}
