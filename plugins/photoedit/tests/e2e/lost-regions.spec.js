// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * The warning before a crop removes a person marking. The photo is the marked
 * 300x200 PNG with one face in the left half and one in the right half
 * (seed --scenario=regions); cropping to the left half cuts the second away.
 *
 * Which regions the server reports, and what the write does with them, is the
 * integration suite's (ApplyRegionsTest); this covers the dialog.
 */

/** toBeCloseTo's digits for a frame edge placed by a mouse drag. */
const RATIO_TOLERANCE_DIGITS = 1;

/** @type {ReturnType<typeof seed>} */
let fixture;

test.beforeEach(() => {
  restore();
  fixture = seed('regions');
});

test.afterEach(() => {
  restore();
});

test('[NEG] the warning names the person cut away, and declining it writes nothing', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.editButton.click();
  await picture.dragCropEdge('e', 0.5);

  const [dialog] = await Promise.all([page.waitForEvent('dialog'), picture.saveButton.click()]);
  const message = dialog.message();
  await dialog.dismiss();

  expect(message).toContain(fixture.cut_name);
  expect(message).not.toContain(fixture.kept_name);
  await expect(picture.editMode).toHaveCount(1);
  await page.reload();
  await picture.waitForPhoto();
  const size = await picture.naturalSize();
  expect(size.width / size.height).toBeCloseTo(fixture.width / fixture.height, RATIO_TOLERANCE_DIGITS);
});

test('[HAPPY] accepting the warning saves the crop', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  page.on('dialog', (dialog) => dialog.accept());
  await picture.editButton.click();
  await picture.dragCropEdge('e', 0.5);

  await picture.saveAndWaitForReload();

  const size = await picture.naturalSize();
  expect(size.width / size.height).toBeCloseTo((fixture.width / 2) / fixture.height, RATIO_TOLERANCE_DIGITS);
});
