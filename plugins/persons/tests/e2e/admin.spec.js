// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');
const { seed, restore, readFileRegions } = require('./support/seed');
const { AdminPhotoPage } = require('./support/AdminPhotoPage');

/**
 * The tagging screen in the admin panel.
 *
 * The same editor as the public page, on a full-size static photo instead of
 * the picture page's derivative-swapping one. What the API does with a region
 * is asserted in AddRegionTest and not restated here; what this layer witnesses
 * is that the screen exists, is reachable only by an administrator, and puts a
 * region where it was drawn.
 *
 * Runs as the plugin's webmaster account - see the chromium-admin project in
 * playwright.config.js.
 */

/** Where the specs drag their box, as fractions of the rendered photo. */
const BOX = { left: 0.3, top: 0.25, w: 0.2, h: 0.25 };

/** How far a stored coordinate may sit from the drawn one, as a fraction. */
const COORD_TOLERANCE = 0.01;

/**
 * How far the two surfaces' rendered boxes may differ, as a fraction of the
 * photo. They show different derivatives at different sizes, so the comparison
 * is in fractions and the tolerance is the rounding of two pixel roundings.
 */
const FRACTION_TOLERANCE = 0.01;

const ADA = 'E2E Admin Ada';

test.describe('the admin tagging screen', () => {
  /** @type {ReturnType<typeof seed>} */
  let seeded;

  test.beforeEach(() => {
    seeded = seed('empty');
  });

  test.afterEach(() => {
    restore();
  });

  /**
   * The whole point of the screen: an administrator can box a face on a photo
   * large enough to see it, and the region reaches the file.
   *
   * Read back by a plain exiftool call in its own process, so neither the
   * plugin's writer nor its parser is between the assertion and the bytes.
   */
  test('an administrator draws a box and the file carries it', async ({ page }) => {
    const screen = new AdminPhotoPage(page);
    await screen.open(seeded.photo_id);
    await screen.waitForPlacement();

    // Anti-vacuity: nobody is tagged yet, so whatever is found afterwards was
    // written by this test.
    await expect(screen.savedBoxes).toHaveCount(0);
    expect(readFileRegions(seeded.photo_id).regions).toHaveLength(0);

    await screen.enterTaggingMode();
    await screen.dragBox(BOX);
    await expect(screen.picker).toBeVisible();

    await screen.typeName(ADA);
    await screen.pickerInput.press('Enter');

    await expect(screen.savedBoxes).toHaveCount(1);

    const inFile = readFileRegions(seeded.photo_id);
    expect(inFile.regions).toHaveLength(1);
    expect(inFile.regions[0].name).toBe(ADA);
    expect(inFile.persons).toContain(ADA);

    // The centre of what was drawn, in MWG's convention - the evidence that the
    // screen's display-to-storage conversion agrees with the box on screen.
    expect(Math.abs(inFile.regions[0].x - (BOX.left + BOX.w / 2))).toBeLessThan(COORD_TOLERANCE);
    expect(Math.abs(inFile.regions[0].y - (BOX.top + BOX.h / 2))).toBeLessThan(COORD_TOLERANCE);
    expect(Math.abs(inFile.regions[0].w - BOX.w)).toBeLessThan(COORD_TOLERANCE);
    expect(Math.abs(inFile.regions[0].h - BOX.h)).toBeLessThan(COORD_TOLERANCE);
  });

  /**
   * The two surfaces have to place the same region identically.
   *
   * This is the Phase 7 manual box, automated. They render different
   * derivatives at different sizes, so agreement can only be asserted in
   * fractions of the photo - which is exactly the frame of reference the
   * coordinate contract is written in.
   */
  test('a region drawn here sits where the public page draws it', async ({ page }) => {
    const screen = new AdminPhotoPage(page);
    await screen.open(seeded.photo_id);
    await screen.waitForPlacement();

    await screen.enterTaggingMode();
    await screen.dragBox(BOX);
    await screen.typeName(ADA);
    await screen.pickerInput.press('Enter');
    await expect(screen.savedBoxes).toHaveCount(1);

    await screen.open(seeded.photo_id);
    await screen.waitForPlacement();
    const regionId = Number(await screen.savedBoxes.first().getAttribute('data-person-region'));
    const onAdmin = await screen.boxFractions(regionId);

    await screen.goto(seeded.picture_path);
    await screen.settle();
    const onPublic = await screen.boxFractions(regionId);

    // Anti-vacuity: both surfaces really rendered a photo, and this is a
    // comparison between two of them rather than one measured twice.
    expect(onAdmin.imageWidth).toBeGreaterThan(1);
    expect(onPublic.imageWidth).toBeGreaterThan(1);

    expect(Math.abs(onAdmin.left - onPublic.left)).toBeLessThan(FRACTION_TOLERANCE);
    expect(Math.abs(onAdmin.top - onPublic.top)).toBeLessThan(FRACTION_TOLERANCE);
    expect(Math.abs(onAdmin.w - onPublic.w)).toBeLessThan(FRACTION_TOLERANCE);
    expect(Math.abs(onAdmin.h - onPublic.h)).toBeLessThan(FRACTION_TOLERANCE);
  });

  /**
   * Nothing else links to the screen, so the injected link is the only way in.
   *
   * PhotoModifyAnchorTest guards the anchor string; this is what witnesses the
   * prefilter actually running and the link resolving to the screen.
   */
  test('the photo properties screen links to it', async ({ page }) => {
    const screen = new AdminPhotoPage(page);
    await screen.openPhotoScreen(seeded.photo_id);

    await expect(screen.photoScreenLink).toHaveCount(1);

    await screen.photoScreenLink.click();
    await screen.waitForPlacement();

    await expect(screen.tagToggle).toHaveCount(1);
  });
});

/**
 * A logged-in account that is not an administrator.
 *
 * The screen rewrites image files for every photo an administrator can reach,
 * which is why it is administrator-only rather than behind the per-image
 * visibility gate the public editor uses.
 */
test.describe('a normal account on the admin screen', () => {
  test.use({ storageState: path.join(__dirname, '.state', 'auth.json') });

  /** @type {ReturnType<typeof seed>} */
  let seeded;

  test.beforeEach(() => {
    seeded = seed('empty');
  });

  test.afterEach(() => {
    restore();
  });

  test('is refused when it navigates there directly', async ({ page }) => {
    const screen = new AdminPhotoPage(page);
    const response = await screen.open(seeded.photo_id);

    expect(response?.status()).toBe(401);
    await expect(screen.stage).toHaveCount(0);
    await expect(screen.tagToggle).toHaveCount(0);
  });
});
