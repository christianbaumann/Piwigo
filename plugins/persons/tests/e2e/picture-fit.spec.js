// @ts-check
const { test, expect } = require('@playwright/test');
const { seed, restore } = require('./support/seed');
const { PicturePage } = require('./support/PicturePage');

/**
 * The photo on the picture page fitted to its area, and `100 %` against the
 * original (.agents/changes/2026-10-04-picture-fit-and-zoom, task 02).
 *
 * The oracle is the design, not the implementation: fit is the largest size
 * with the photo's aspect ratio inside the area PicturePage.fitArea() reads,
 * 100 % is the photo's piwigo_images size, and the file loaded is the smallest
 * one that covers the display size on the screen's pixel ratio.
 */

const DESKTOP = { width: 1920, height: 1080 };
const PHONE = { width: 390, height: 844 };
const PHONE_LANDSCAPE = { width: 844, height: 390 };

/** How much narrower the area is made, as a scrollbar would, and then some. */
const AREA_SHRINK_PX = 200;
/** Roughly what a mobile browser's address bar takes when it shows. */
const ADDRESS_BAR_PX = 56;

/** Rounding to whole pixels may leave the limiting side this far short of the area. */
const FIT_TOLERANCE_PX = 1;

/**
 * Asserts the photo is fitted: inside the area, touching it on one side, and in
 * the seeded photo's aspect ratio.
 *
 * @param {{width: number, height: number}} shown
 * @param {{width: number, height: number}} area
 * @param {{width: number, height: number}} photo
 */
function expectFitted(shown, area, photo) {
  expect(shown.width).toBeLessThanOrEqual(area.width + FIT_TOLERANCE_PX);
  expect(shown.height).toBeLessThanOrEqual(area.height + FIT_TOLERANCE_PX);
  const gap = Math.min(Math.abs(area.width - shown.width), Math.abs(area.height - shown.height));
  expect(gap).toBeLessThanOrEqual(FIT_TOLERANCE_PX);
  // One pixel of rounding on either side, scaled by the other side.
  const skew = Math.abs(shown.width * photo.height - shown.height * photo.width);
  expect(skew).toBeLessThanOrEqual(Math.max(photo.width, photo.height));
}

test.describe('fit on a desktop screen', () => {
  test.use({ viewport: DESKTOP, deviceScaleFactor: 1 });

  test.afterEach(() => {
    restore();
  });

  // [HAPPY]
  test('a large photo touches the area on its limiting side', async ({ page }) => {
    const seeded = seed('empty');
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.settleImage();

    const area = await picture.fitArea();
    // Anti-vacuity: the photo is larger than the area, so fit has to shrink it.
    expect(seeded.width).toBeGreaterThan(area.width);

    expectFitted(await picture.imageDisplay(), area, seeded);
  });

  // [BVA] below the area: the original is smaller on both sides.
  test('a small photo is upscaled to fill the area', async ({ page }) => {
    const seeded = seed('empty', { source: 'small' });
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.settleImage();

    const area = await picture.fitArea();
    expect(seeded.width).toBeLessThan(area.width);
    expect(seeded.height).toBeLessThan(area.height);

    const shown = await picture.imageDisplay();
    expectFitted(shown, area, seeded);
    expect(shown.width).toBeGreaterThan(seeded.width);
  });

  // [BVA] above the area: of the files larger than the display size, the smallest.
  test('a large photo loads the smallest file that covers the display size', async ({ page }) => {
    const seeded = seed('empty');
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.settleImage();

    const shown = await picture.imageDisplay();
    // The display size is the fitted one; without it, a file shown 1:1 would
    // trivially be the smallest one covering itself.
    expectFitted(shown, await picture.fitArea(), seeded);
    const derivatives = await picture.derivatives();
    const index = derivatives.findIndex(
      (d) => d.w >= shown.width - FIT_TOLERANCE_PX && d.h >= shown.height - FIT_TOLERANCE_PX
    );

    // Anti-vacuity: a smaller derivative exists below the display size, so
    // "the largest that fits" and "the smallest that covers" are different files.
    expect(index).toBeGreaterThan(0);
    expect(derivatives[index - 1].w).toBeLessThan(shown.width);

    expect(shown.naturalWidth).toBe(derivatives[index].w);
    expect(shown.naturalHeight).toBe(derivatives[index].h);
  });
});

test.describe('fit on a high-density screen', () => {
  test.use({ viewport: DESKTOP, deviceScaleFactor: 2 });

  test.afterEach(() => {
    restore();
  });

  // [BVA] the file covers the display size in device pixels, not CSS pixels.
  test('the loaded file covers the display size at pixel ratio 2', async ({ page }) => {
    const seeded = seed('empty');
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.settleImage();

    const area = await picture.fitArea();
    const shown = await picture.imageDisplay();
    expectFitted(shown, area, seeded);

    const derivatives = await picture.derivatives();
    const largest = Math.max(seeded.width, derivatives[derivatives.length - 1].w);
    expect(shown.naturalWidth).toBeGreaterThanOrEqual(Math.min(largest, shown.width * 2 - FIT_TOLERANCE_PX));
  });
});

test.describe('100 % and back', () => {
  test.use({ viewport: DESKTOP, deviceScaleFactor: 1 });

  test.afterEach(() => {
    restore();
  });

  // [ST] fit -> 100 % -> fit
  test('100 % shows the original size and Einpassen returns to fit', async ({ page }) => {
    const seeded = seed('empty');
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.settleImage();

    await picture.zoomToNatural();
    const natural = await picture.imageDisplay();
    expect(natural.width).toBe(seeded.width);
    expect(natural.height).toBe(seeded.height);
    expect(natural.naturalWidth).toBe(seeded.width);

    await picture.zoomToFit();
    expectFitted(await picture.imageDisplay(), await picture.fitArea(), seeded);
  });

  // [NEG] a click on a photo zoomed past fit stays on the page; [HAPPY] in fit it navigates.
  test('a click at 100 % does not navigate, a click in fit does', async ({ page }) => {
    const seeded = seed('neighbours');
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.settleImage();

    await picture.zoomToNatural();
    // Anti-vacuity: the photo really is past fit, and nothing is left to an <area>.
    expect((await picture.imageDisplay()).width).toBe(seeded.width);
    expect(await picture.hasImageMap()).toBe(false);

    await picture.markDocument();
    await picture.clickVisiblePhotoAt(0.9, 0.5);
    expect(await picture.sameDocument()).toBe(true);

    await picture.zoomToFit();
    await picture.clickImageAt(0.9, 0.5);
    await page.waitForURL((url) => url.pathname + url.search === seeded.next_path);
  });

  // [ST] nothing is remembered from one photo to the next
  test('the next photo opens in fit', async ({ page }) => {
    const seeded = seed('neighbours');
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.settleImage();

    await picture.zoomToNatural();
    expect((await picture.imageDisplay()).width).toBe(seeded.width);

    await picture.nextLink.click();
    await page.waitForURL((url) => url.pathname + url.search === seeded.next_path);
    await picture.settleImage();

    // The neighbours are copies of the same source, so they share its size.
    expectFitted(await picture.imageDisplay(), await picture.fitArea(), seeded);
  });
});

test.describe('re-fitting', () => {
  test.afterEach(() => {
    restore();
  });

  test.describe('on a desktop screen', () => {
    test.use({ viewport: DESKTOP, deviceScaleFactor: 1 });

    // [ST] the area narrows with no window resize, as when a page scrollbar appears
    test('the photo follows its area when only the area narrows', async ({ page }) => {
      const seeded = seed('empty');
      const picture = new PicturePage(page);
      await picture.goto(seeded.picture_path);
      await picture.settleImage();
      const before = await picture.imageDisplay();

      await picture.narrowAreaBy(AREA_SHRINK_PX);
      await picture.settleImage();

      const area = await picture.fitArea();
      // Anti-vacuity: the area really is narrower than the photo was.
      expect(area.width).toBeLessThan(before.width - FIT_TOLERANCE_PX);
      expectFitted(await picture.imageDisplay(), area, seeded);
    });
  });

  test.describe('on a phone held sideways', () => {
    test.use({ viewport: PHONE_LANDSCAPE, deviceScaleFactor: 1 });

    // [NEG] a height-only change on the narrow layout - the address bar
    // showing and hiding while scrolling - does not resize the photo
    test('a change of the window height alone leaves the photo as it is', async ({ page }) => {
      const seeded = seed('empty');
      const picture = new PicturePage(page);
      await picture.goto(seeded.picture_path);
      await picture.settleImage();
      const before = await picture.imageDisplay();

      // Anti-vacuity: the photo is limited by height here, so a re-fit on a
      // height change would change its size.
      const area = await picture.fitArea();
      expect(Math.abs(before.height - area.height)).toBeLessThanOrEqual(FIT_TOLERANCE_PX);

      await page.setViewportSize({ width: PHONE_LANDSCAPE.width, height: PHONE_LANDSCAPE.height - ADDRESS_BAR_PX });
      await picture.settleImage();

      const after = await picture.imageDisplay();
      expect(after.width).toBe(before.width);
      expect(after.height).toBe(before.height);
    });
  });
});

test.describe('fit on a phone', () => {
  test.use({ viewport: PHONE, deviceScaleFactor: 1 });

  test.afterEach(() => {
    restore();
  });

  test('the photo fills the width and there is no + or -', async ({ page }) => {
    const seeded = seed('empty');
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.settleImage();

    const area = await picture.fitArea();
    const shown = await picture.imageDisplay();
    expectFitted(shown, area, seeded);
    expect(Math.abs(shown.width - area.width)).toBeLessThanOrEqual(FIT_TOLERANCE_PX);

    // + and - are in the page, for a window that widens, but not shown.
    await expect(picture.zoomFitButton).toHaveCount(1);
    await expect(picture.zoomInButton).toHaveCount(1);
    await expect(picture.zoomOutButton).toHaveCount(1);
    await expect(picture.zoomInButton).toBeHidden();
    await expect(picture.zoomOutButton).toBeHidden();
  });
});

test.describe('the zoom buttons on a phone', () => {
  test.use({ viewport: PHONE, deviceScaleFactor: 1 });

  test.afterEach(() => {
    restore();
  });

  // [HAPPY] text-only entries line up with the labels of the entries that have an icon
  test('their labels line up with the other entries in the action menu', async ({ page }) => {
    const seeded = seed('empty');
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.openActionMenu();

    const lefts = await picture.actionLabelLefts();
    // Anti-vacuity: there are other labels to line up with, and they agree.
    expect(lefts.others.length).toBeGreaterThan(0);
    const column = lefts.others[0];
    for (const left of lefts.others) {
      expect(Math.abs(left - column)).toBeLessThanOrEqual(FIT_TOLERANCE_PX);
    }

    expect(lefts.zoom).toHaveLength(2);
    for (const left of lefts.zoom) {
      expect(Math.abs(left - column)).toBeLessThanOrEqual(FIT_TOLERANCE_PX);
    }
  });
});

test.describe('the slideshow', () => {
  test.use({ viewport: DESKTOP, deviceScaleFactor: 1 });

  test.afterEach(() => {
    restore();
  });

  test('shows the photo fitted and has no zoom control', async ({ page }) => {
    const seeded = seed('empty');
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);

    await picture.startSlideshow();
    await picture.settleImage();

    expectFitted(await picture.imageDisplay(), await picture.fitArea(), seeded);
    await expect(picture.zoomFitButton).toHaveCount(0);
    await expect(picture.zoomNaturalButton).toHaveCount(0);
    await expect(picture.zoomInButton).toHaveCount(0);
    await expect(picture.zoomOutButton).toHaveCount(0);
  });
});
