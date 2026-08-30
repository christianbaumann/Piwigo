// @ts-check
const { test, expect } = require('@playwright/test');
const { seed, restore } = require('./support/seed');
const { PicturePage } = require('./support/PicturePage');

/**
 * The read-only person overlay on the public picture page, in a real browser.
 *
 * This is the layer that can witness what no other can: whether the boxes sit
 * where the regions say, given that modus rewrites the photo's src, width and
 * height underneath them on load and on every resize. Everything about *what*
 * the server emitted is asserted in PicturePageSourceTest and is not restated
 * here.
 *
 * Runs as the plugin's normal (non-administrator) account - see auth.setup.js.
 */

/** How far a box may sit from where the region says, in CSS pixels. */
const TOLERANCE_PX = 2;

/** The two widths the layout is measured at. Wide enough apart to force a different derivative. */
const WIDE = { width: 1400, height: 900 };
const NARROW = { width: 800, height: 700 };

test.describe('person region overlay', () => {
  /** @type {ReturnType<typeof seed>} */
  let seeded;

  test.beforeEach(() => {
    seeded = seed('overlay');
  });

  test.afterEach(() => {
    restore();
  });

  test('every region is drawn as a box', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    expect(seeded.regions.length).toBeGreaterThan(0);
    await expect(picture.boxes).toHaveCount(seeded.regions.length);

    for (const region of seeded.regions) {
      await expect(picture.box(region.region_id)).toHaveCount(1);
    }
  });

  for (const size of [WIDE, NARROW]) {
    test(`box geometry matches the region at ${size.width}px`, async ({ page }) => {
      await page.setViewportSize(size);

      const picture = new PicturePage(page);
      await picture.goto(seeded.picture_path);
      await picture.waitForPlacement();

      const image = await picture.imageRect();
      expect(image.width).toBeGreaterThan(TOLERANCE_PX);

      for (const region of seeded.regions) {
        const box = await picture.boxRect(region.region_id);

        expect(Math.abs(box.left - (image.left + region.left * image.width))).toBeLessThanOrEqual(TOLERANCE_PX);
        expect(Math.abs(box.top - (image.top + region.top * image.height))).toBeLessThanOrEqual(TOLERANCE_PX);
        expect(Math.abs(box.width - region.w * image.width)).toBeLessThanOrEqual(TOLERANCE_PX);
        expect(Math.abs(box.height - region.h * image.height)).toBeLessThanOrEqual(TOLERANCE_PX);
      }
    });
  }

  /**
   * The in-place redraw: the same page, a smaller derivative loaded into the
   * same element, and the boxes are on the faces rather than on where the faces
   * used to be.
   *
   * Driven through the theme's own changeImgSrc(), which is what the derivative
   * switch box calls - not a synthetic src assignment. A viewport resize is not
   * used here: whether it changes the rendered size at all depends on how large
   * the fixture photo happens to be, so it cannot carry an anti-vacuity check.
   */
  test('the boxes follow the photo when a smaller derivative is loaded', async ({ page }) => {
    await page.setViewportSize(WIDE);

    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    const before = await picture.imageRect();

    await picture.switchToSmallestDerivative();
    await picture.waitForImageNarrowerThan(before.width);
    await picture.waitForPlacement();

    const after = await picture.imageRect();
    expect(after.width).toBeLessThan(before.width);

    for (const region of seeded.regions) {
      const box = await picture.boxRect(region.region_id);

      expect(Math.abs(box.left - (after.left + region.left * after.width))).toBeLessThanOrEqual(TOLERANCE_PX);
      expect(Math.abs(box.top - (after.top + region.top * after.height))).toBeLessThanOrEqual(TOLERANCE_PX);
      expect(Math.abs(box.width - region.w * after.width)).toBeLessThanOrEqual(TOLERANCE_PX);
      expect(Math.abs(box.height - region.h * after.height)).toBeLessThanOrEqual(TOLERANCE_PX);
    }
  });

  /**
   * The overlay must not capture the click the theme's own navigation handler
   * is waiting for. It sits on top of the photo, so without pointer-events:none
   * the right-hand quarter of the photo would silently stop advancing.
   */
  test('clicking the photo outside a box still navigates', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    const before = page.url();
    const image = await picture.imageRect();

    // The upper-middle strip, which both of the theme's navigation branches
    // send to the album: the <area> map's second rectangle, and the JavaScript
    // handler's "pct between 0.3 and 0.7, top half, below the 15px dead zone".
    // Clear of both seeded boxes.
    await page.mouse.click(image.left + image.width * 0.5, image.top + image.height * 0.12);

    await page.waitForURL((url) => url.href !== before);
    expect(page.url()).not.toBe(before);
  });

  /** Each name links to the person's gallery page, which is the mirrored tag's. */
  test('the person row lists the names', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);

    await expect(picture.personRow).toHaveCount(1);

    for (const region of seeded.regions) {
      await expect(picture.personRow.getByRole('link', { name: region.name })).toHaveCount(1);
    }
  });
});
