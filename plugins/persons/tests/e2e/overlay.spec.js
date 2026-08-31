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

/** The widths the stepped resize walks through, wide to narrow. */
const RESIZE_STEPS = [1200, 1000, 900, 800, 620];

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
   * The manual box Phase 5 opened: resize across a derivative-switch threshold
   * and the boxes stay on the faces.
   *
   * Stepped rather than jumped, because that is what was asked to be checked by
   * hand, and because each step is its own settled state to assert on. The
   * widths are chosen so at least one of them crosses a threshold - which the
   * spec proves rather than assumes: it waits for the photo to actually render
   * narrower before measuring, and fails if it never does.
   */
  test('the boxes track the photo across a stepped resize', async ({ page }) => {
    await page.setViewportSize(WIDE);

    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    let previous = await picture.imageRect();
    let switches = 0;

    for (const width of RESIZE_STEPS) {
      await page.setViewportSize({ width, height: WIDE.height });
      await picture.settle();

      const image = await picture.imageRect();
      // Either direction: the theme grows the photo as well as shrinking it, and
      // a crossing is a crossing.
      if (image.width !== previous.width) {
        switches += 1;
      }
      previous = image;

      for (const region of seeded.regions) {
        const box = await picture.boxRect(region.region_id);

        expect(Math.abs(box.left - (image.left + region.left * image.width))).toBeLessThanOrEqual(TOLERANCE_PX);
        expect(Math.abs(box.top - (image.top + region.top * image.height))).toBeLessThanOrEqual(TOLERANCE_PX);
        expect(Math.abs(box.width - region.w * image.width)).toBeLessThanOrEqual(TOLERANCE_PX);
        expect(Math.abs(box.height - region.h * image.height)).toBeLessThanOrEqual(TOLERANCE_PX);
      }
    }

    // Anti-vacuity: without a real derivative switch somewhere in the walk this
    // test would only be re-measuring one unchanged layout several times. It has
    // already earned its keep once - it failed while settle() was reading the
    // layout from before the resize, which every geometry assertion here would
    // otherwise have passed on.
    expect(switches).toBeGreaterThan(0);
  });

  /** The boxes stay out of the way until the visitor looks at the photo. */
  test('a box is hidden until the photo is hovered', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    const first = seeded.regions[0].region_id;

    expect((await picture.boxStyle(first)).opacity).toBe(0);

    await picture.hoverStage();
    await expect
      .poll(async () => (await picture.boxStyle(first)).opacity)
      .toBeGreaterThan(0.9);
  });

  /**
   * Hovering a name dims the photo outside that box.
   *
   * Implemented with `.person-box:has(.person-box-label:hover)`, and the label
   * is the only part of the overlay that takes pointer events - the boxes stay
   * transparent to them so the theme's navigation keeps working. A `:has()` that
   * stopped matching would leave the page looking almost right, so nothing but a
   * computed-style assertion would report it.
   */
  test('hovering a name dims the photo outside that box', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    const first = seeded.regions[0].region_id;
    const image = await picture.imageRect();

    await picture.hoverStage();
    const undimmed = await picture.dimSpread(first);

    await picture.label(first).hover();

    await expect
      .poll(async () => picture.dimSpread(first))
      .toBeGreaterThan(image.width);

    // Anti-vacuity: the dim has to be something the hover switched on, not a
    // shadow the box carries all the time.
    expect(undimmed).toBeLessThan(image.width);
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

/**
 * A region whose AppliedToDimensions no longer describes the photo.
 *
 * MWG says a consumer SHOULD ignore such a region; this plugin flags it instead,
 * because silently dropping a face is worse for a gallery owner than showing a
 * doubtful one. Whether it *looks* different is a computed-style fact - the page
 * source only carries a class name - so it is asserted here and nowhere else.
 */
test.describe('a stale person region', () => {
  /** @type {ReturnType<typeof seed>} */
  let seeded;

  test.beforeEach(() => {
    seeded = seed('stale');
  });

  test.afterEach(() => {
    restore();
  });

  test('is drawn dashed, dimmed and with an explanation', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    // Anti-vacuity: the scenario is only worth anything if the page really did
    // decide these regions are stale.
    expect(seeded.regions.length).toBeGreaterThan(0);
    for (const region of seeded.regions) {
      expect(region.stale).toBe(true);
    }

    await picture.hoverStage();

    for (const region of seeded.regions) {
      await expect
        .poll(async () => (await picture.boxStyle(region.region_id)).opacity)
        .toBeGreaterThan(0);

      const style = await picture.boxStyle(region.region_id);
      expect(style.borderStyle).toBe('dashed');
      expect(style.opacity).toBeLessThan(1);
      expect(style.title).not.toBe('');
    }
  });
});

/**
 * The HiDPI branch of rvas_choose().
 *
 * At devicePixelRatio > 1 it rescales the photo and *removes* the <area> map
 * (themes/modus/js/photo.autosize.js:57-66), so navigation falls to the theme's
 * own click handler and the photo's rendered size no longer equals the
 * derivative's pixel size. Both are things the overlay sits on top of.
 */
test.describe('the overlay on a HiDPI screen', () => {
  test.use({ deviceScaleFactor: 2 });

  /** @type {ReturnType<typeof seed>} */
  let seeded;

  test.beforeEach(() => {
    seeded = seed('overlay');
  });

  test.afterEach(() => {
    restore();
  });

  test('places the boxes on the rescaled photo', async ({ page }) => {
    await page.setViewportSize(WIDE);

    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    // Anti-vacuity: proves the HiDPI branch was really taken. It is the branch
    // that drops the map, so a run that kept it was a 1x run wearing this name.
    expect(await picture.hasImageMap()).toBe(false);

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

  /**
   * With the map gone, navigation is the theme's own click handler - the branch
   * the 1x spec never reaches. The overlay must not swallow that click either.
   */
  test('leaves the theme click handler able to navigate', async ({ page }) => {
    await page.setViewportSize(WIDE);

    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    expect(await picture.hasImageMap()).toBe(false);

    const before = page.url();
    const image = await picture.imageRect();

    await page.mouse.click(image.left + image.width * 0.5, image.top + image.height * 0.12);

    await page.waitForURL((url) => url.href !== before);
    expect(page.url()).not.toBe(before);
  });
});
