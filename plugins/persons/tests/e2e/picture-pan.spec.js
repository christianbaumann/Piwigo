// @ts-check
const { test, expect } = require('@playwright/test');
const { seed, restore } = require('./support/seed');
const { PicturePage } = require('./support/PicturePage');

/**
 * Drag-to-pan on the picture page: a mouse drag on a photo bigger than its
 * area scrolls the area, except while the persons editor is drawing
 * (.agents/changes/2026-10-04-picture-fit-and-zoom, task 04).
 *
 * The oracle is the design: the photo follows the pointer, so the area scrolls
 * by the drag distance against it.
 */

const DESKTOP = { width: 1920, height: 1080 };

/** Scroll positions are whole pixels, the pointer's travel is not. */
const SCROLL_TOLERANCE_PX = 1;

/** A drag across the middle of the visible photo, up and to the left. */
const DRAG_FROM = { x: 0.6, y: 0.6 };
const DRAG_TO = { x: 0.4, y: 0.4 };
/**
 * With #theImage scrolled to its right end, a drag to the right across the
 * visible part. The theme's click zones are fractions of the whole photo, and
 * the pointer stays on the photo point it pressed, which here is in the zone
 * that goes to the next photo.
 */
const NEXT_ZONE_FROM = { x: 0.8, y: 0.5 };
const NEXT_ZONE_TO = { x: 0.95, y: 0.5 };
/** A pointer travel well past any click slop, small enough to stay inside the area. */
const PAN_PX = 60;

test.use({ viewport: DESKTOP, deviceScaleFactor: 1 });

test.afterEach(() => {
  restore();
});

/**
 * Opens the seeded photo at 100 %, with #theImage scrolled to the middle so a
 * drag can move it either way.
 *
 * @param {PicturePage} picture
 */
async function openAtNatural(picture, scenario = 'empty') {
  const seeded = seed(scenario);
  await picture.goto(seeded.picture_path);
  await picture.settleImage();
  await picture.zoomToNatural();
  await picture.scrollAreaTo(0.5, 0.5);
  const start = await picture.scrollPositions();
  // Anti-vacuity: the photo overflows its area on both axes, with room to scroll back.
  expect(start.areaLeft).toBeGreaterThan(0);
  expect(start.areaTop).toBeGreaterThan(0);
  return { seeded, start };
}

/**
 * @param {{areaLeft: number, areaTop: number}} before
 * @param {{areaLeft: number, areaTop: number}} after
 * @param {{dx: number, dy: number}} drag
 */
function expectPannedBy(before, after, drag) {
  expect(Math.abs(after.areaLeft - (before.areaLeft - drag.dx))).toBeLessThanOrEqual(SCROLL_TOLERANCE_PX);
  expect(Math.abs(after.areaTop - (before.areaTop - drag.dy))).toBeLessThanOrEqual(SCROLL_TOLERANCE_PX);
}

test.describe('drag-to-pan', () => {
  // [HAPPY] the photo follows the pointer
  test('a drag at 100 % scrolls the area by the drag distance', async ({ page }) => {
    const picture = new PicturePage(page);
    const { start } = await openAtNatural(picture);
    expect(await picture.photoCursor()).toBe('grab');

    const drag = await picture.dragVisiblePhoto(DRAG_FROM, DRAG_TO);

    // Anti-vacuity: the drag travelled on both axes.
    expect(Math.abs(drag.dx)).toBeGreaterThan(SCROLL_TOLERANCE_PX);
    expect(Math.abs(drag.dy)).toBeGreaterThan(SCROLL_TOLERANCE_PX);
    expectPannedBy(start, await picture.scrollPositions(), drag);
    // The grabbing cursor of the drag is gone again.
    expect(await picture.photoCursor()).toBe('grab');
  });

  // [NEG] nothing overflows in fit, so nothing offers a pan
  test('in fit the photo offers no pan', async ({ page }) => {
    const seeded = seed('empty');
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.settleImage();

    expect(await picture.areaOverflows()).toBe(false);
    expect(await picture.photoCursor()).not.toBe('grab');
  });

  // [ERR] a release the page never saw - a context menu took it - ends the drag
  test('a mouse move with no button held ends the drag', async ({ page }) => {
    const picture = new PicturePage(page);
    const { start } = await openAtNatural(picture);
    const pressed = await picture.pressVisiblePhoto(DRAG_FROM);

    await picture.moveWithNoButtonTo(pressed.x - PAN_PX, pressed.y - PAN_PX);

    expect(await picture.scrollPositions()).toEqual(start);
    expect(await picture.photoCursor()).toBe('grab');
    await picture.releaseMouse();
  });

  // [ST] a zoom step during a drag: the drag goes on from where the step left the photo
  test('a zoom step during a drag does not throw the photo back', async ({ page }) => {
    const picture = new PicturePage(page);
    await openAtNatural(picture);
    const pressed = await picture.pressVisiblePhoto({ x: 0.5, y: 0.5 });
    await picture.moveMouseTo(pressed.x - PAN_PX, pressed.y - PAN_PX);

    await picture.pressKey('+');
    const zoomed = await picture.scrollPositions();
    await picture.moveMouseTo(pressed.x - 2 * PAN_PX, pressed.y - 2 * PAN_PX);

    expectPannedBy(zoomed, await picture.scrollPositions(), { dx: -PAN_PX, dy: -PAN_PX });
    await picture.releaseMouse();
  });

  // [ST] tagging mode takes the drag for a region; leaving it gives it back to panning
  test('in tagging mode a drag draws a region, after it a drag pans again', async ({ page }) => {
    const picture = new PicturePage(page);
    await openAtNatural(picture);

    await picture.enterTaggingMode();
    // Clicking the toggle below the photo scrolled #theImage to bring it into view.
    await picture.scrollAreaTo(0.5, 0.5);
    const start = await picture.scrollPositions();
    await picture.dragVisiblePhoto(DRAG_FROM, DRAG_TO);

    await expect(picture.draft).toBeVisible();
    expect(await picture.scrollPositions()).toEqual(start);

    await picture.exitTaggingMode();
    await picture.scrollAreaTo(0.5, 0.5);
    const before = await picture.scrollPositions();
    const drag = await picture.dragVisiblePhoto(DRAG_FROM, DRAG_TO);
    expectPannedBy(before, await picture.scrollPositions(), drag);
  });

  // [NEG] releasing a drag over the next-photo zone is not a click on it
  test('a drag released over the next-photo zone does not navigate', async ({ page }) => {
    const picture = new PicturePage(page);
    await openAtNatural(picture, 'neighbours');
    await picture.scrollAreaTo(1, 0.5);
    const start = await picture.scrollPositions();
    await picture.markDocument();

    const drag = await picture.dragVisiblePhoto(NEXT_ZONE_FROM, NEXT_ZONE_TO);

    // Anti-vacuity: the drag really panned, so it was taken as one.
    expectPannedBy(start, await picture.scrollPositions(), drag);
    expect(await picture.sameDocument()).toBe(true);
  });

  // [NEG] fitted during the drag, the release lands on a photo that navigates on click
  test('a drag released after 0 fitted the photo does not navigate', async ({ page }) => {
    const picture = new PicturePage(page);
    await openAtNatural(picture, 'neighbours');
    await picture.scrollAreaTo(1, 0.5);
    await picture.markDocument();
    const pressed = await picture.pressVisiblePhoto(NEXT_ZONE_TO);

    await picture.pressKey('0');
    const image = await picture.imageRect();
    // Anti-vacuity: the release is on the fitted photo, in its next-photo zone.
    expect(pressed.x).toBeGreaterThan(image.left + image.width * NEXT_ZONE_FROM.x);
    expect(pressed.x).toBeLessThan(image.left + image.width);
    await picture.releaseMouse();

    expect(await picture.sameDocument()).toBe(true);
  });
});
