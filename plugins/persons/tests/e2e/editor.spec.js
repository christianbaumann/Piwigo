// @ts-check
const { test, expect } = require('@playwright/test');
const { seed, restore, readFileRegions, setExiftool, personCounts } = require('./support/seed');
const { PicturePage } = require('./support/PicturePage');

/**
 * Tagging a person on the public picture page, in a real browser.
 *
 * This is the only layer that can witness the interaction at all: the box comes
 * off a mouse drag, the name off a keyboard, and what reaches the API is
 * computed from the photo's rendered box. What the API does once it has those
 * numbers is asserted in AddRegionTest and is not restated here.
 *
 * Every scenario starts from an untagged photo of its own, so what the file
 * holds afterwards was put there by the browser.
 *
 * Runs as the plugin's normal (non-administrator) account - see auth.setup.js.
 */

/** Where the specs drag their boxes, as fractions of the rendered photo. */
const FIRST_BOX = { left: 0.25, top: 0.3, w: 0.2, h: 0.25 };
const SECOND_BOX = { left: 0.6, top: 0.3, w: 0.15, h: 0.2 };

/** Below PERSONS_MIN_BOX_FRACTION on both axes, which is 0.01. */
const TOO_SMALL_BOX = { left: 0.5, top: 0.5, w: 0.004, h: 0.004 };

/** How far a stored coordinate may sit from the drawn one, as a fraction. */
const COORD_TOLERANCE = 0.01;

/** How far a redrawn box may sit from where it was drawn, in CSS pixels. */
const TOLERANCE_PX = 2;

/** Box positions the picker placement is checked at - corners included. */
const PICKER_POSITIONS = [
  { left: 0.05, top: 0.05, w: 0.18, h: 0.2 },
  { left: 0.75, top: 0.05, w: 0.18, h: 0.2 },
  { left: 0.05, top: 0.7, w: 0.18, h: 0.2 },
  { left: 0.75, top: 0.7, w: 0.18, h: 0.2 },
  { left: 0.4, top: 0.4, w: 0.2, h: 0.2 },
];

const ADA = 'E2E Editor Ada';
const GRACE = 'E2E Editor Grace';

test.describe('tagging a person', () => {
  /** @type {ReturnType<typeof seed>} */
  let seeded;

  test.beforeEach(() => {
    seeded = seed('empty');
  });

  test.afterEach(() => {
    restore();
  });

  /**
   * The whole loop: draw, name somebody who does not exist yet, commit, and
   * find the box still there on a fresh load of the page - which is the only
   * evidence that the name went to the server rather than staying in the DOM.
   */
  test('a drawn box survives a reload with its name on it', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    // Anti-vacuity: the scenario is only worth anything if nobody is tagged yet.
    await expect(picture.savedBoxes).toHaveCount(0);

    await picture.enterTaggingMode();
    await picture.dragBox(FIRST_BOX);
    await expect(picture.picker).toBeVisible();

    await picture.typeName(ADA);

    const drawn = await picture.draftRect();

    await picture.pickerInput.press('Enter');

    await expect(picture.savedBoxes).toHaveCount(1);

    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    await expect(picture.savedBoxes).toHaveCount(1);
    expect(await picture.savedNames()).toEqual([ADA]);

    // The manual box Phase 6 opened: the box has to come back where it was
    // drawn, not merely come back. This is the whole round trip - display box to
    // MWG's pre-rotation centre origin, into the file, out again, and back to a
    // fraction of the photo's rendered size.
    const image = await picture.imageRect();
    expect(image.width).toBeGreaterThan(TOLERANCE_PX);

    const regionId = Number(await picture.savedBoxes.first().getAttribute('data-person-region'));
    const reloaded = await picture.boxRect(regionId);

    expect(Math.abs(reloaded.left - drawn.left)).toBeLessThanOrEqual(TOLERANCE_PX);
    expect(Math.abs(reloaded.top - drawn.top)).toBeLessThanOrEqual(TOLERANCE_PX);
    expect(Math.abs(reloaded.width - drawn.width)).toBeLessThanOrEqual(TOLERANCE_PX);
    expect(Math.abs(reloaded.height - drawn.height)).toBeLessThanOrEqual(TOLERANCE_PX);
  });

  /**
   * The manual box Phase 6's other half opened: the picker must not sit on the
   * face being named, wherever on the photo that face is.
   *
   * Four candidate positions are scored by how much of the drawn box they cover,
   * and a regression here - a candidate list that collapses to one, a clamp that
   * pushes the picker back over the box - leaves a page that looks fine until
   * you tag somebody near an edge.
   */
  test('the picker never covers the box being named', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    await picture.enterTaggingMode();

    // Anti-vacuity: several genuinely different positions, including the corners
    // where a single hard-coded placement would run out of room.
    expect(PICKER_POSITIONS.length).toBeGreaterThan(1);

    for (const box of PICKER_POSITIONS) {
      await picture.dragBox(box);
      await expect(picture.picker).toBeVisible();

      const drawn = await picture.draftRect();
      const picker = await picture.pickerRect();

      expect(picker.width).toBeGreaterThan(0);

      const overlapW = Math.max(
        0,
        Math.min(drawn.left + drawn.width, picker.left + picker.width) - Math.max(drawn.left, picker.left)
      );
      const overlapH = Math.max(
        0,
        Math.min(drawn.top + drawn.height, picker.top + picker.height) - Math.max(drawn.top, picker.top)
      );

      expect(overlapW * overlapH).toBe(0);

      await picture.pickerInput.press('Escape');
    }
  });

  /**
   * The box has to reach the image file, not only the index - the file is the
   * source of truth this plugin is built on.
   *
   * Read back by a plain exiftool call in its own process, started after the
   * browser finished, so neither the plugin's writer nor its parser is between
   * the assertion and the bytes.
   */
  test('the region is in the file, where an independent reader finds it', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    const before = readFileRegions(seeded.photo_id);
    // Anti-vacuity: whatever is found afterwards was written during this test.
    expect(before.regions).toHaveLength(0);

    await picture.enterTaggingMode();
    await picture.dragBox(FIRST_BOX);
    await picture.typeName(ADA);
    await picture.pickerInput.press('Enter');
    await expect(picture.savedBoxes).toHaveCount(1);

    const after = readFileRegions(seeded.photo_id);

    expect(after.regions).toHaveLength(1);
    expect(after.regions[0].name).toBe(ADA);
    expect(after.regions[0].type).toBe('Face');
    expect(after.persons).toContain(ADA);

    // The centre of what was drawn, in MWG's convention. This is what proves the
    // browser's display-to-storage conversion agrees with the box on screen.
    expect(Math.abs(after.regions[0].x - (FIRST_BOX.left + FIRST_BOX.w / 2))).toBeLessThan(COORD_TOLERANCE);
    expect(Math.abs(after.regions[0].y - (FIRST_BOX.top + FIRST_BOX.h / 2))).toBeLessThan(COORD_TOLERANCE);
    expect(Math.abs(after.regions[0].w - FIRST_BOX.w)).toBeLessThan(COORD_TOLERANCE);
    expect(Math.abs(after.regions[0].h - FIRST_BOX.h)).toBeLessThan(COORD_TOLERANCE);
  });

  /** Esc is the way out of a box drawn by accident, and it must write nothing. */
  test('Esc removes the drawn box and writes nothing', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    await picture.enterTaggingMode();
    await picture.dragBox(FIRST_BOX);

    // Anti-vacuity: there has to be a box for Esc to remove.
    await expect(picture.draft).toHaveCount(1);
    await expect(picture.picker).toBeVisible();

    await picture.pickerInput.press('Escape');

    await expect(picture.draft).toHaveCount(0);
    await expect(picture.picker).toBeHidden();
    await expect(picture.savedBoxes).toHaveCount(0);

    expect(readFileRegions(seeded.photo_id).regions).toHaveLength(0);
  });

  /**
   * Two faces on one photo. The write is a merge into whatever the file already
   * holds, so the interesting failure is the second write silently replacing the
   * first - which the page would show as one box and nothing would flag.
   */
  test('a second person does not remove the first', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    await picture.enterTaggingMode();

    await picture.dragBox(FIRST_BOX);
    await picture.typeName(ADA);
    await picture.pickerInput.press('Enter');
    await expect(picture.savedBoxes).toHaveCount(1);

    await picture.dragBox(SECOND_BOX);
    await picture.typeName(GRACE);
    await picture.pickerInput.press('Enter');
    await expect(picture.savedBoxes).toHaveCount(2);

    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    expect((await picture.savedNames()).sort()).toEqual([ADA, GRACE].sort());

    const inFile = readFileRegions(seeded.photo_id);
    expect(inFile.regions.map((region) => region.name).sort()).toEqual([ADA, GRACE].sort());
  });

  /**
   * The other half of the picker: a person the gallery already knows is picked
   * out of the list rather than typed again, and the region lands on that
   * person's existing row.
   *
   * The box is drawn, named, and removed again first, because getList
   * deliberately never offers somebody already on this photo
   * (ws_functions.inc.php) - so the person has to exist and be absent from the
   * picture, which is exactly the state a person tagged on another photo is in.
   */
  test('picking an existing person from the list tags that same person', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    await picture.enterTaggingMode();

    await picture.dragBox(FIRST_BOX);
    await picture.typeName(ADA);
    await picture.pickerInput.press('Enter');
    await expect(picture.savedBoxes).toHaveCount(1);

    const [firstRegion] = await picture.savedRegionIds();
    expect(firstRegion, 'the committed box carries no region id').toBeTruthy();
    await picture.deleteButton(firstRegion).click();
    await expect(picture.savedBoxes).toHaveCount(0);

    // A fragment out of the middle of the name, so what comes back is a search
    // hit on the index rather than an echo of what was typed.
    await picture.dragBox(SECOND_BOX);
    await picture.searchForExisting(ADA.slice(6, 12), ADA);
    await picture.existingPickerOption(ADA).click();
    await expect(picture.savedBoxes).toHaveCount(1);

    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();
    expect(await picture.savedNames()).toEqual([ADA]);

    const inFile = readFileRegions(seeded.photo_id);
    expect(inFile.regions.map((region) => region.name)).toEqual([ADA]);

    // What a second person row would take is not on trial here - piwigo_persons
    // carries a UNIQUE index on name, so the database forbids one outright
    // (measured 2026-08-31). What is on trial is that the picked entry commits
    // the person the index already holds: the region has to hang off that row.
    const counts = personCounts();
    expect(counts[ADA], 'the person the box was tagged with is not in the index').toBeDefined();
    expect(counts[ADA].regions, 'the region did not land on the existing person').toBe(1);
    expect(counts[ADA].photos).toBe(1);
  });

  /**
   * A stray click is a box of nearly no size. It is refused with something the
   * user can read, rather than saved as a region nobody can see or hit again.
   */
  test('a box below the minimum is refused with a visible message', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    await picture.enterTaggingMode();
    await picture.dragBox(TOO_SMALL_BOX);

    await expect(picture.editorMessage).not.toBeEmpty();
    await expect(picture.picker).toBeHidden();
    await expect(picture.draft).toHaveCount(0);
    await expect(picture.savedBoxes).toHaveCount(0);
  });
});

/**
 * Taking a person off a photo again.
 *
 * The affordance only exists while tagging, because outside that mode the whole
 * overlay is transparent to pointer events so the theme's navigation keeps
 * working - a delete button that is visible but unclickable would be worse than
 * none.
 */
test.describe('removing a person', () => {
  /** @type {ReturnType<typeof seed>} */
  let seeded;

  test.beforeEach(() => {
    seeded = seed('overlay');
  });

  test.afterEach(() => {
    restore();
  });

  test('the delete control is reachable only while tagging', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    // Anti-vacuity: there has to be a box carrying the control.
    expect(seeded.regions.length).toBeGreaterThan(0);
    const first = seeded.regions[0].region_id;

    await expect(picture.deleteButton(first)).toBeHidden();

    await picture.enterTaggingMode();
    await expect(picture.deleteButton(first)).toBeVisible();
  });

  test('deleting a box removes it from the page and from the file', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    const before = readFileRegions(seeded.photo_id);
    expect(before.regions.length).toBe(seeded.regions.length);

    const doomed = seeded.regions[0];
    const survivor = seeded.regions[1];

    await picture.enterTaggingMode();
    await picture.deleteButton(doomed.region_id).click();

    await expect(picture.box(doomed.region_id)).toHaveCount(0);
    await expect(picture.box(survivor.region_id)).toHaveCount(1);

    const after = readFileRegions(seeded.photo_id);
    expect(after.regions.map((region) => region.name)).toEqual([survivor.name]);
    expect(after.persons).not.toContain(doomed.name);
  });
});

/**
 * A server that cannot write metadata into image files.
 *
 * Offering an action that can only fail is worse than not offering it, so the
 * button is disabled with the reason on it. The state is forced rather than
 * waited for - see setExiftool().
 */
test.describe('an install without exiftool', () => {
  /** @type {ReturnType<typeof seed>} */
  let seeded;

  test.beforeEach(() => {
    seeded = seed('overlay');
    setExiftool('missing');
  });

  test.afterEach(() => {
    setExiftool('present');
    restore();
  });

  test('offers the editor disabled, with the reason on it', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    // Anti-vacuity: the button is on the page at all - it is the *disabled*
    // state being asserted, not the absent one.
    await expect(picture.tagToggle).toHaveCount(1);
    await expect(picture.tagToggle).toBeDisabled();
    await expect(picture.tagToggle).toHaveAttribute('title', /.+/);
  });
});

/**
 * A visitor who is not logged in.
 *
 * Faces are personal data and decision 0019 keeps every read of them behind a
 * login, so the editor must not even be offered. Asserted with a context that
 * carries no session at all, rather than by logging out.
 */
test.describe('a guest on the picture page', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  /** @type {ReturnType<typeof seed>} */
  let seeded;

  test.beforeEach(() => {
    seeded = seed('overlay');
  });

  test.afterEach(() => {
    restore();
  });

  test('is offered no way to tag anybody', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);

    // Anti-vacuity: this is the photo page, and it does carry regions - they are
    // simply not shown to a visitor without a login.
    await expect(picture.image).toHaveCount(1);
    expect(seeded.regions.length).toBeGreaterThan(0);

    await expect(picture.tagToggle).toHaveCount(0);
    await expect(picture.stage).toHaveCount(0);
  });
});
