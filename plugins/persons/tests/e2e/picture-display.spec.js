// @ts-check
const { test, expect } = require('@playwright/test');
const { seed, restore } = require('./support/seed');
const { PicturePage } = require('./support/PicturePage');

/**
 * How the modus theme shows the photo on the picture page today.
 *
 * Every case here is [ERR]: a characterization of the current theme, written
 * before the fit-and-zoom change (.agents/changes/2026-10-04-picture-fit-and-zoom)
 * touches it. The oracle is the implementation in themes/modus/js/photo.autosize.js
 * and picture_content_asize.tpl; no requirement confirms any of it. A red run
 * here reports a change, not a defect - decide which it is before touching the
 * expectation.
 *
 * Already covered elsewhere and not restated: overlay placement, the smallest
 * derivative making the photo narrower, and one upper-middle click navigating
 * away at both pixel ratios (overlay.spec.js).
 */

/** The viewport the design measured the current state at. */
const DESKTOP = { width: 1920, height: 1080 };

/**
 * Click points inside each navigation zone, in fractions of the rendered photo.
 *
 * Chosen to sit inside both of the theme's mechanisms: the <area> map
 * (left quarter, right quarter, upper-middle quarter) and the JavaScript
 * handler that replaces it once the map is removed (pct < 0.3, pct > 0.7, upper
 * half below a 15px dead zone).
 */
const ZONES = {
  previous: { x: 0.1, y: 0.5 },
  next: { x: 0.9, y: 0.5 },
  up: { x: 0.5, y: 0.12 },
};

/**
 * Pixel ratio 1 navigates through the <area> map, 2 through the click handler
 * (rvas_choose() drops the map on a HiDPI screen).
 */
const PIXEL_RATIOS = [
  { dpr: 1, map: true },
  { dpr: 2, map: false },
];

for (const { dpr, map } of PIXEL_RATIOS) {
  test.describe(`click zones on the photo at pixel ratio ${dpr}`, () => {
    test.use({ deviceScaleFactor: dpr, viewport: DESKTOP });

    /** @type {ReturnType<typeof seed>} */
    let seeded;

    test.beforeEach(() => {
      seeded = seed('neighbours');
    });

    test.afterEach(() => {
      restore();
    });

    /** @type {Array<[keyof typeof ZONES, (s: any) => string]>} */
    const cases = [
      ['previous', (s) => s.previous_path],
      ['next', (s) => s.next_path],
      ['up', (s) => s.album_path],
    ];

    for (const [zone, destination] of cases) {
      // [ERR] Records the current zones; no requirement confirms them.
      test(`a click in the ${zone} zone goes to the ${zone} page`, async ({ page }) => {
        const picture = new PicturePage(page);
        await picture.goto(seeded.picture_path);
        await picture.settleImage();

        // Anti-vacuity: the branch this describe block claims to cover.
        expect(await picture.hasImageMap()).toBe(map);

        const target = destination(seeded);
        expect(target).toBeTruthy();

        await picture.clickImageAt(ZONES[zone].x, ZONES[zone].y);

        await page.waitForURL((url) => url.pathname + url.search !== seeded.picture_path);
        const url = new URL(page.url());
        expect(url.pathname + url.search).toMatch(new RegExp('^' + escapeRegExp(target) + '(/|$)'));
      });
    }
  });
}

test.describe('the photo at its derivative size', () => {
  test.use({ deviceScaleFactor: 1, viewport: DESKTOP });

  /** @type {ReturnType<typeof seed>} */
  let seeded;

  test.beforeEach(() => {
    seeded = seed('overlay');
  });

  test.afterEach(() => {
    restore();
  });

  // [ERR] Records that rvas_choose() shows one derivative 1:1 at pixel ratio 1
  // and points the map at that derivative; no requirement confirms it.
  test('at pixel ratio 1 the photo carries a map and is shown at its file size', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.settleImage();

    const shown = await picture.imageDisplay();
    const derivative = (await picture.derivatives()).find((d) => shown.usemap === `#map${d.type}`);

    // Anti-vacuity: the map names one of this photo's derivatives.
    expect(derivative).toBeTruthy();
    if (!derivative) {
      return;
    }

    expect(shown.naturalWidth).toBe(derivative.w);
    expect(shown.naturalHeight).toBe(derivative.h);
    expect(shown.width).toBe(derivative.w);
    expect(shown.height).toBe(derivative.h);
  });

  // [ERR] Records that a size picked in core's size menu is shown at its own
  // pixel size; no requirement confirms it.
  test('a size chosen in the size menu is shown at its file size', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.settleImage();

    const before = await picture.imageDisplay();
    const chosen = (await picture.derivatives())[0];

    // Anti-vacuity: the menu entry has to change something to be observed.
    expect(chosen.w).not.toBe(before.naturalWidth);

    await picture.chooseSize(chosen.type);
    await picture.waitForImageNarrowerThan(before.width);
    await picture.settleImage();

    const after = await picture.imageDisplay();
    expect(after.naturalWidth).toBe(chosen.w);
    expect(after.naturalHeight).toBe(chosen.h);
    expect(after.width).toBe(chosen.w);
    expect(after.height).toBe(chosen.h);
  });

  // [ERR] Records that the slideshow shows one of the photo's derivatives; no
  // requirement confirms how it is sized, so the rendered size is not recorded.
  test('the slideshow shows the photo', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);

    await picture.startSlideshow();
    await picture.settleImage();

    const shown = await picture.imageDisplay();
    const derivative = (await picture.derivatives()).find(
      (d) => d.w === shown.naturalWidth && d.h === shown.naturalHeight
    );

    // The loaded file is a derivative of the photo, not the loading GIF.
    expect(derivative).toBeTruthy();
    expect(shown.width).toBeGreaterThan(1);
    expect(shown.height).toBeGreaterThan(1);
  });
});

/** @param {string} text */
function escapeRegExp(text) {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
