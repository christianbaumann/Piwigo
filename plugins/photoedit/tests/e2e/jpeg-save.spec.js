// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * Saving a JPEG asks first, because the save re-encodes it. The photo is the
 * marked 300x200 picture as an upright JPEG (seed --scenario=jpeg).
 *
 * Quality, orientation and pixels are the integration suite's (ApplyJpegTest).
 * That a PNG saves without asking is turn-save.spec.js': it registers no
 * dialog handler, so an unexpected dialog would be dismissed and the save lost.
 */

/** @type {ReturnType<typeof seed>} */
let fixture;

test.beforeEach(() => {
  restore();
  fixture = seed('jpeg');
});

test.afterEach(() => {
  restore();
});

test('[NEG] declining the re-encode note writes nothing', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.editButton.click();
  await picture.turnRightButton.click();

  const [dialog] = await Promise.all([page.waitForEvent('dialog'), picture.saveButton.click()]);
  expect(dialog.type()).toBe('confirm');
  await dialog.dismiss();

  await expect(picture.editMode).toHaveCount(1);
  await page.reload();
  await picture.waitForPhoto();
  const size = await picture.naturalSize();
  expect(size.width, 'the file was turned after all').toBeGreaterThan(size.height);
});

test('[HAPPY] accepting the re-encode note saves the turn', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  /** @type {string[]} */
  const dialogs = [];
  page.on('dialog', (dialog) => {
    dialogs.push(dialog.type());
    dialog.accept();
  });
  await picture.editButton.click();
  await picture.turnRightButton.click();

  await picture.saveAndWaitForReload();

  expect(dialogs).toEqual(['confirm']);
  const size = await picture.naturalSize();
  expect(size.height).toBeGreaterThan(size.width);
});
