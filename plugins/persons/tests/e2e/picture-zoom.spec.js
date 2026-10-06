// @ts-check
const { test, expect } = require('@playwright/test');
const { seed, restore } = require('./support/seed');
const { PicturePage } = require('./support/PicturePage');
const { SKINS, MIN_SKINS } = require('./support/skins');

/**
 * Continuous zoom on the picture page: `+`/`−` in ×1.25 steps between fit and
 * 400 % of the original, the keys `+`, `-` and `0`, and Ctrl+wheel
 * (.agents/changes/2026-10-04-picture-fit-and-zoom, task 03).
 *
 * The oracle is the design: a step multiplies the displayed size by
 * ZOOM_STEP, fit is the lower bound, MAX_ZOOM times the photo's
 * piwigo_images size the upper one, and the file loaded is the smallest that
 * covers the display size.
 */

const DESKTOP = { width: 1920, height: 1080 };
const PHONE = { width: 390, height: 844 };

/** The design's zoom step and upper bound. */
const ZOOM_STEP = 1.25;
const MAX_ZOOM = 4;

/** Each size is floored to whole pixels, and fit's own floor is scaled by the step. */
const ROUNDING_PX = 2;
/** How far the centred photo point may drift across a step, as a fraction of the photo. */
const CENTRE_TOLERANCE = 0.01;
/** How far a box may sit from where the region says, in CSS pixels; as in overlay.spec.js. */
const BOX_TOLERANCE_PX = 2;
/** More presses than fit to 400 % takes on the large fixture (about 11). */
const MAX_PRESSES = 30;
/** One notch of a mouse wheel in Chromium. */
const WHEEL_NOTCH = 100;

/** Where #theImage is scrolled to before the centring step: off-centre on both axes. */
const OFF_CENTRE = { x: 0.3, y: 0.7 };
/**
 * Far enough down that the top of #theImage leaves the window by about half
 * the area's height - where a middle taken from the area's own top, rather
 * than its visible part, is off by enough to show past CENTRE_TOLERANCE.
 */
const PAGE_SCROLL_PX = 600;
/** A window large enough that fit outgrows one zoom step from the DESKTOP window. */
const LARGE_DESKTOP = { width: 2600, height: 1500 };

const NAME_PICKER_BOX = { left: 0.25, top: 0.3, w: 0.2, h: 0.25 };

test.use({ viewport: DESKTOP, deviceScaleFactor: 1 });

test.afterEach(() => {
  restore();
});

/** @param {PicturePage} picture */
async function openFitted(picture, scenario = 'empty') {
  const seeded = seed(scenario);
  await picture.goto(seeded.picture_path);
  await picture.settleImage();
  return { seeded, fit: await picture.imageDisplay() };
}

/**
 * @param {{width: number}} shown
 * @param {number} width
 */
function expectWidth(shown, width) {
  expect(Math.abs(shown.width - width)).toBeLessThanOrEqual(ROUNDING_PX);
}

test.describe('the zoom buttons', () => {
  // [ST] fit -> + -> − -> fit
  test('+ from fit zooms by one step and − returns to fit', async ({ page }) => {
    const picture = new PicturePage(page);
    const { fit } = await openFitted(picture);

    await picture.zoomIn();
    expectWidth(await picture.imageDisplay(), fit.width * ZOOM_STEP);

    await picture.zoomOut();
    const back = await picture.imageDisplay();
    expect(back.width).toBe(fit.width);
    expect(back.height).toBe(fit.height);
  });

  // [BVA] the upper bound, and one press past it
  test('+ stops at 400 % of the original', async ({ page }) => {
    const picture = new PicturePage(page);
    const { seeded, fit } = await openFitted(picture);

    let previous = fit.width;
    let presses = 0;
    for (; presses < MAX_PRESSES; presses += 1) {
      await picture.zoomIn();
      const width = (await picture.imageDisplay()).width;
      if (width === previous) {
        break;
      }
      previous = width;
    }

    // Anti-vacuity: it took several steps, and the loop stopped on a press that changed nothing.
    expect(presses).toBeGreaterThan(1);
    expect(presses).toBeLessThan(MAX_PRESSES);
    const top = await picture.imageDisplay();
    expect(top.width).toBe(seeded.width * MAX_ZOOM);
    expect(top.height).toBe(seeded.height * MAX_ZOOM);
  });

  // [BVA] the lower bound: − in fit changes nothing
  test('− stops at fit', async ({ page }) => {
    const picture = new PicturePage(page);
    const { fit } = await openFitted(picture);

    await picture.zoomOut();
    const shown = await picture.imageDisplay();
    expect(shown.width).toBe(fit.width);
    expect(shown.height).toBe(fit.height);
  });

  // [BVA] the display size crosses the loaded file's own size
  test('zooming past the loaded file switches to a bigger one', async ({ page }) => {
    const picture = new PicturePage(page);
    const { fit } = await openFitted(picture);

    await picture.zoomIn();
    const zoomed = await picture.imageDisplay();
    // Anti-vacuity: one step really takes the display past the file fit loaded.
    expect(zoomed.width).toBeGreaterThan(fit.naturalWidth);

    expect(zoomed.naturalWidth).toBeGreaterThan(fit.naturalWidth);
    expect(zoomed.naturalWidth).toBeGreaterThanOrEqual(zoomed.width - ROUNDING_PX);
  });

  // [HAPPY] a step keeps the photo point at the middle of the area where it was
  test('a step keeps the same photo point in the middle', async ({ page }) => {
    const picture = new PicturePage(page);
    await openFitted(picture);

    await picture.zoomIn();
    await picture.zoomIn();
    await picture.scrollAreaTo(OFF_CENTRE.x, OFF_CENTRE.y);
    const before = await picture.centredPhotoPoint();
    // Anti-vacuity: the scroll moved the middle off the photo's centre on both axes.
    expect(Math.abs(before.x - 0.5)).toBeGreaterThan(CENTRE_TOLERANCE);
    expect(Math.abs(before.y - 0.5)).toBeGreaterThan(CENTRE_TOLERANCE);

    await picture.zoomIn();
    const after = await picture.centredPhotoPoint();
    expect(Math.abs(after.x - before.x)).toBeLessThanOrEqual(CENTRE_TOLERANCE);
    expect(Math.abs(after.y - before.y)).toBeLessThanOrEqual(CENTRE_TOLERANCE);
  });

  // [BVA] the area's top is above the window: the middle is that of the visible part
  test('a step keeps the same photo point in the middle with the page scrolled', async ({ page }) => {
    const picture = new PicturePage(page);
    await openFitted(picture);

    await picture.zoomIn();
    await picture.zoomIn();
    await picture.lengthenPageBy(DESKTOP.height);
    // Anti-vacuity: the page really scrolled past the area's top.
    expect(await picture.scrollPageTo(PAGE_SCROLL_PX)).toBe(PAGE_SCROLL_PX);
    expect((await picture.imageRect()).top).toBeLessThan(0);
    await picture.scrollAreaTo(OFF_CENTRE.x, OFF_CENTRE.y);
    const before = await picture.centredPhotoPoint();

    // The key, not the button: clicking the button would scroll the toolbar back into view.
    await picture.pressKey('+');
    expect((await picture.scrollPositions()).pageY).toBe(PAGE_SCROLL_PX);
    const after = await picture.centredPhotoPoint();
    expect(Math.abs(after.x - before.x)).toBeLessThanOrEqual(CENTRE_TOLERANCE);
    expect(Math.abs(after.y - before.y)).toBeLessThanOrEqual(CENTRE_TOLERANCE);
  });

  // [BVA] a small photo at 100 % is below fit: − must not enlarge it
  test('− on a small photo at 100 % changes nothing', async ({ page }) => {
    const seeded = seed('empty', { source: 'small' });
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.settleImage();

    await picture.zoomToNatural();
    const natural = await picture.imageDisplay();
    // Anti-vacuity: 100 % really is smaller than fit for this photo.
    expect(natural.width).toBe(seeded.width);
    expect(natural.width).toBeLessThan((await picture.fitArea()).width);

    await picture.zoomOut();
    expect((await picture.imageDisplay()).width).toBe(natural.width);
  });

  // [ST] a step the area outgrew is fit: it does not come back when the area shrinks again
  test('a zoom the area outgrew stays fitted when the window shrinks back', async ({ page }) => {
    const picture = new PicturePage(page);
    const { fit } = await openFitted(picture);

    await picture.zoomIn();
    await page.setViewportSize(LARGE_DESKTOP);
    await picture.settleImage();
    // Anti-vacuity: in the large window fit is past the one step taken before.
    expect((await picture.imageDisplay()).width).toBeGreaterThan(fit.width * ZOOM_STEP);

    await page.setViewportSize(DESKTOP);
    await picture.settleImage();
    const back = await picture.imageDisplay();
    expect(back.width).toBe(fit.width);
    expect(back.height).toBe(fit.height);
  });
});

test.describe('the zoom keys', () => {
  // [ST] + and - step, 0 returns to fit from any zoom
  test('+ and - step the zoom and 0 returns to fit', async ({ page }) => {
    const picture = new PicturePage(page);
    const { fit } = await openFitted(picture);

    await picture.pressKey('+');
    expectWidth(await picture.imageDisplay(), fit.width * ZOOM_STEP);
    await picture.pressKey('-');
    expect((await picture.imageDisplay()).width).toBe(fit.width);

    await picture.pressKey('+');
    await picture.pressKey('+');
    expectWidth(await picture.imageDisplay(), fit.width * ZOOM_STEP * ZOOM_STEP);
    await picture.pressKey('0');
    expect((await picture.imageDisplay()).width).toBe(fit.width);

    await picture.zoomToNatural();
    await picture.pressKey('0');
    expect((await picture.imageDisplay()).width).toBe(fit.width);
  });

  // [NEG] the persons picker takes the key as text
  test('typing + into the persons name picker does not zoom', async ({ page }) => {
    const picture = new PicturePage(page);
    const { fit } = await openFitted(picture);
    // Anti-vacuity: on this page the key does zoom while no input has the focus.
    await picture.pressKey('+');
    expectWidth(await picture.imageDisplay(), fit.width * ZOOM_STEP);
    await picture.pressKey('0');

    await picture.enterTaggingMode();
    await picture.dragBox(NAME_PICKER_BOX);
    await picture.pickerInput.waitFor({ state: 'visible' });
    await picture.pickerInput.focus();
    await picture.pressKey('+');

    await expect(picture.pickerInput).toHaveValue('+');
    expect((await picture.imageDisplay()).width).toBe(fit.width);
  });
});

test.describe('the mouse wheel', () => {
  // [HAPPY] Ctrl+wheel zooms the photo, not the page
  test('Ctrl+wheel up over the photo zooms in', async ({ page }) => {
    const picture = new PicturePage(page);
    const { fit } = await openFitted(picture);

    const wheel = await picture.wheelOverPhoto(-WHEEL_NOTCH, { ctrl: true });

    expectWidth(await picture.imageDisplay(), fit.width * ZOOM_STEP);
    expect(wheel.defaultPrevented).toBe(true);
  });

  // [NEG] a plain wheel keeps scrolling the page
  test('a plain wheel scrolls the page and does not zoom', async ({ page }) => {
    const picture = new PicturePage(page);
    const { fit } = await openFitted(picture);
    const before = await picture.scrollPositions();
    expect(before.pageY).toBe(0);

    const wheel = await picture.wheelOverPhoto(WHEEL_NOTCH);

    expect(wheel.defaultPrevented).toBe(false);
    // mouse.wheel() does not wait for the scroll it causes.
    await expect.poll(async () => (await picture.scrollPositions()).pageY).toBeGreaterThan(0);
    expect((await picture.imageDisplay()).width).toBe(fit.width);
  });
});

test.describe('the person overlay', () => {
  // [HAPPY] the boxes follow the photo past 100 %
  test('the face boxes stay on their regions above 100 %', async ({ page }) => {
    const picture = new PicturePage(page);
    const { seeded } = await openFitted(picture, 'overlay');
    expect(seeded.regions.length).toBeGreaterThan(0);

    await picture.zoomToNatural();
    await picture.zoomIn();
    await picture.settle();

    const image = await picture.imageRect();
    // Anti-vacuity: the photo really is shown above its original size.
    expect(image.width).toBeGreaterThan(seeded.width);

    for (const region of seeded.regions) {
      const box = await picture.boxRect(region.region_id);
      expect(Math.abs(box.left - (image.left + region.left * image.width))).toBeLessThanOrEqual(BOX_TOLERANCE_PX);
      expect(Math.abs(box.top - (image.top + region.top * image.height))).toBeLessThanOrEqual(BOX_TOLERANCE_PX);
      expect(Math.abs(box.width - region.w * image.width)).toBeLessThanOrEqual(BOX_TOLERANCE_PX);
      expect(Math.abs(box.height - region.h * image.height)).toBeLessThanOrEqual(BOX_TOLERANCE_PX);
    }
  });
});

test.describe('the zoom control in every skin', () => {
  // Anti-vacuity: the skin list was read at all.
  test('the skins are found', () => {
    expect(SKINS.length).toBeGreaterThanOrEqual(MIN_SKINS);
    expect(SKINS).toContain('dark');
  });

  for (const skin of SKINS) {
    // [ECP] one partition per skin: with and without a stylesheet of its own
    test(`works and reads like the toolbar in ${skin}`, async ({ page }) => {
      const seeded = seed('empty');
      const picture = new PicturePage(page);
      await picture.goto(`${seeded.picture_path}&skin=${skin}`);
      await picture.settleImage();

      const look = await picture.zoomControlLook();
      expect(look.iconColor).not.toBeNull();
      for (let i = 0; i < look.buttons.length; i += 1) {
        const button = look.buttons[i];
        expect(button.color, `${button.id} label colour`).toBe(look.iconColor);
        expect(button.top, `${button.id} in the toolbar row`).toBe(look.buttons[0].top);
        expect(button.textLeft, `${button.id} label inside its button`).toBeGreaterThanOrEqual(button.left);
        expect(button.textRight, `${button.id} label inside its button`).toBeLessThanOrEqual(button.right);
        if (i > 0) {
          expect(button.left, `${button.id} clear of the one before`).toBeGreaterThanOrEqual(look.buttons[i - 1].right);
        }
      }

      await picture.zoomToNatural();
      const widths = await picture.overflowWidths();
      // Anti-vacuity: the photo at 100 % is wider than its area.
      expect(widths.areaScroll).toBeGreaterThan(widths.areaClient);
      expect(widths.areaScrolls).toBe(true);
      expect(widths.pageScroll).toBeLessThanOrEqual(widths.window);

      await page.setViewportSize(PHONE);
      await expect(picture.zoomInButton).toBeHidden();
      await expect(picture.zoomOutButton).toBeHidden();

      await picture.openActionMenu();
      const menu = await picture.zoomControlLook();
      expect(menu.textColor).not.toBeNull();
      for (const button of menu.buttons.filter((b) => ['zoomFit', 'zoomNatural'].includes(b.id))) {
        expect(button.color, `${button.id} label colour in the action menu`).toBe(menu.textColor);
      }
    });
  }
});
