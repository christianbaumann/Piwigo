// @ts-check
const { test, expect } = require('@playwright/test');
const { seed, restore, readFileRegions } = require('./support/seed');
const { PicturePage } = require('./support/PicturePage');

/**
 * Boxes on a photo whose images.rotation is not 0, as core sets it for a JPEG
 * with EXIF Orientation 6 (code 3) or 8 (code 1).
 *
 * The oracle is the photo itself: the seeded file has one red block, and the
 * browser finds it in the pixels the server sent. A box is right when it sits on
 * the block, wherever the server turned it to - which no comparison with the
 * plugin's own rotation helpers could tell (decision 0035: both directions were
 * once wrong together, and a round trip through both still came back in place).
 *
 * Runs as the plugin's normal account - see auth.setup.js.
 */

/** How far a box may sit from the red block, as a fraction of the photo: the derivative is resampled. */
const TOLERANCE = 0.02;

/** Fewer red pixels than this means the block was not found, and every comparison would be vacuous. */
const MIN_RED_PIXELS = 100;

const NAME = 'E2E Editor Ada';

/** @param {{left: number, top: number, w: number, h: number}} actual */
function expectNear(actual, expected) {
  for (const key of ['left', 'top', 'w', 'h']) {
    expect(Math.abs(actual[key] - expected[key]), `${key}: ${actual[key]} vs ${expected[key]}`)
      .toBeLessThanOrEqual(TOLERANCE);
  }
}

test.describe('boxes on a turned photo', () => {
  test.afterEach(() => {
    restore();
  });

  for (const rotation of [3, 1]) {
    test(`a saved box is drawn on the face for rotation code ${rotation}`, async ({ page }) => {
      const seeded = seed('marked', { rotation });
      const picture = new PicturePage(page);
      await picture.goto(seeded.picture_path);
      await picture.waitForPlacement();

      const block = await picture.redBlockFractions();
      expect(block.pixels).toBeGreaterThan(MIN_RED_PIXELS);
      // Anti-vacuity: the page really shows the photo turned, block and all.
      const moved = Math.max(
        Math.abs(block.left - (seeded.marker.x - seeded.marker.w / 2)),
        Math.abs(block.top - (seeded.marker.y - seeded.marker.h / 2))
      );
      expect(moved).toBeGreaterThan(TOLERANCE * 5);

      expect(seeded.regions).toHaveLength(1);
      expectNear(await picture.boxFractions(seeded.regions[0].region_id), block);
    });
  }

  test('a box drawn on the face of a code-3 photo is stored on the face in the file', async ({ page }) => {
    const seeded = seed('marked-empty', { rotation: 3 });
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();
    await expect(picture.savedBoxes).toHaveCount(0);

    const block = await picture.redBlockFractions();
    expect(block.pixels).toBeGreaterThan(MIN_RED_PIXELS);

    await picture.enterTaggingMode();
    await picture.dragBox(block);
    await picture.typeName(NAME);
    await picture.pickerInput.press('Enter');
    await expect(picture.savedBoxes).toHaveCount(1);

    const { regions } = readFileRegions(seeded.photo_id);
    expect(regions).toHaveLength(1);
    const stored = regions[0];
    for (const key of ['x', 'y', 'w', 'h']) {
      expect(Math.abs(stored[key] - seeded.marker[key]), `${key}: ${stored[key]} vs ${seeded.marker[key]}`)
        .toBeLessThanOrEqual(TOLERANCE);
    }
  });
});
