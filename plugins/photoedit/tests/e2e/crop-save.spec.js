// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * Cropping a photo and saving it, as the webmaster. The photo is the generated
 * 300x200 PNG (seed --scenario=marked).
 *
 * Which pixels land in the file is the integration suite's (ApplyCropTest);
 * this spec covers the frame: where it starts, that dragging it sends the crop,
 * that it turns with the preview, and that Cancel removes it.
 */

/** Slack for a frame edge placed by a mouse drag: toBeCloseTo's digits. */
const RATIO_TOLERANCE_DIGITS = 1;

/** @type {ReturnType<typeof seed>} */
let fixture;

test.beforeEach(() => {
  restore();
  fixture = seed('marked');
});

test.afterEach(() => {
  restore();
});

/** @param {{width: number, height: number}} size */
function ratio(size) {
  return size.width / size.height;
}

test('[HAPPY] the frame starts as the whole photo; its left half, saved, is the shown photo', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.editButton.click();

  await expect(picture.cropFrame).toBeVisible();
  const frame = await picture.cropFrame.boundingBox();
  const photo = await picture.image.boundingBox();
  expect(frame && photo, 'anti-vacuity: nothing to measure').toBeTruthy();
  expect(Math.abs((frame?.width ?? 0) - (photo?.width ?? 0))).toBeLessThanOrEqual(1);
  expect(Math.abs((frame?.height ?? 0) - (photo?.height ?? 0))).toBeLessThanOrEqual(1);

  await picture.dragCropEdge('e', 0.5);
  await picture.saveAndWaitForReload();

  await expect(picture.editMode).toHaveCount(0);
  expect(ratio(await picture.naturalSize())).toBeCloseTo((fixture.width / 2) / fixture.height, RATIO_TOLERANCE_DIGITS);
});

test('[ST] the frame turns with the preview: left half, then a quarter turn, is the top half', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.editButton.click();

  await picture.dragCropEdge('e', 0.5);
  await picture.turnRightButton.click();
  await picture.saveAndWaitForReload();

  // the turned view is height x width; its top half is height x width / 2
  expect(ratio(await picture.naturalSize())).toBeCloseTo(fixture.height / (fixture.width / 2), RATIO_TOLERANCE_DIGITS);
});

test('[ST] Cancel removes the frame, and Save on a fresh frame writes nothing', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  /** @type {string[]} */
  const applies = [];
  page.on('request', (request) => {
    if ((request.postData() || '').includes('method=pwg.photoedit.apply')) {
      applies.push(request.postData() || '');
    }
  });

  await picture.editButton.click();
  await picture.dragCropEdge('e', 0.5);
  await picture.cancelButton.click();
  await expect(picture.cropFrame).toHaveCount(0);

  await picture.editButton.click();
  await expect(picture.cropFrame).toBeVisible();
  await picture.saveButton.click();

  await expect(picture.editMode).toHaveCount(0);
  expect(applies).toEqual([]);
  expect(ratio(await picture.naturalSize())).toBeCloseTo(fixture.width / fixture.height, RATIO_TOLERANCE_DIGITS);
});
