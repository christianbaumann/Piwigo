// @ts-check
const { test, expect } = require('@playwright/test');
const { seed, restore, readFileRegions, setExiftool, personCounts } = require('./support/seed');
const { PicturePage } = require('./support/PicturePage');
const { SKINS, MIN_SKINS } = require('./support/skins');

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

/** Clear of both regions the 'overlay' scenario seeds, so the drag starts on bare photo. */
const CLEAR_BOX = { left: 0.05, top: 0.65, w: 0.15, h: 0.2 };

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

/** A name carrying markup; the row must show it as text. */
const MARKUP_NAME = '<i>E2E Markup</i>';

/** Saves one box under a name and waits until the server has answered with it. */
async function tag(picture, box, name, expectedBoxes) {
  await picture.enterTaggingMode();
  await picture.dragBox(box);
  await picture.typeName(name);
  await picture.pickerInput.press('Enter');
  await expect(picture.savedBoxes).toHaveCount(expectedBoxes);
}

/**
 * Holds every addRegion response until the returned release() is called, so a
 * spec can act while a save is still on its way. The request itself reaches
 * the server at once; only its answer waits.
 */
async function holdSaves(page) {
  let release;
  const released = new Promise((resolve) => {
    release = resolve;
  });
  await page.route(/method=pwg\.persons\.addRegion/, async (route) => {
    const response = await route.fetch();
    await released;
    await route.fulfill({ response });
  });
  return () => release();
}

/**
 * Makes a person exist in the index without being on this photo: tagged, then
 * removed again. getList never offers somebody already on the photo
 * (ws_functions.inc.php), so this is the state a person tagged elsewhere is in.
 * Leaves tagging mode on. Commits with a click, not with Enter, so the specs
 * about what Enter does do not rely on it in their setup.
 */
async function knownButNotOnPhoto(picture, name) {
  await picture.dragBox(FIRST_BOX);
  await picture.typeName(name);
  await picture.pickerOption(name).click();
  await expect(picture.savedBoxes).toHaveCount(1);
  const [regionId] = await picture.savedRegionIds();
  await picture.deleteButton(Number(regionId)).click();
  await expect(picture.savedBoxes).toHaveCount(0);
}

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
    // Measured against the photo, not the viewport: with the photo fitted to
    // the window, opening the picker can scroll the page, and the reload starts
    // at the top again.
    const imageWhenDrawn = await picture.imageRect();

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

    expect(Math.abs((reloaded.left - image.left) - (drawn.left - imageWhenDrawn.left))).toBeLessThanOrEqual(TOLERANCE_PX);
    expect(Math.abs((reloaded.top - image.top) - (drawn.top - imageWhenDrawn.top))).toBeLessThanOrEqual(TOLERANCE_PX);
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
      await picture.enterTaggingMode();
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

    await picture.enterTaggingMode();
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
    await picture.enterTaggingMode();
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
   * [ERR] Characterization: records today's highlight, read off editor.js
   * (renderOptions() ends in setHighlight(0), the Enter handler commits the
   * highlighted entry); no requirement confirms it. The handbook relies on it.
   */
  test('[HAPPY] the first entry is highlighted as the list opens, and Enter takes it', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();
    await picture.enterTaggingMode();
    await knownButNotOnPhoto(picture, ADA);

    await picture.enterTaggingMode();
    await picture.dragBox(SECOND_BOX);
    await picture.existingPickerOption(ADA).waitFor();

    // Anti-vacuity: nothing typed, and the person just used heads the list.
    await expect(picture.pickerInput).toHaveValue('');
    expect((await picture.pickerEntries())[0]).toEqual({ name: ADA, create: false });

    await expect(picture.highlightedPickerOption).toHaveCount(1);
    await expect(picture.highlightedPickerOption).toHaveAttribute('data-persons-name', ADA);

    await picture.pickerInput.press('Enter');
    await expect(picture.savedBoxes).toHaveCount(1);
    expect(await picture.savedNames()).toEqual([ADA]);
  });

  /**
   * [ERR] Characterization: records today's list order, read off editor.js
   * (renderOptions() appends the create entry after the matches); no
   * requirement confirms it. A fragment is never an exact match, so both are listed.
   */
  test('[ECP] a partial match is listed before Anlegen', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();
    await picture.enterTaggingMode();
    await knownButNotOnPhoto(picture, ADA);

    const fragment = ADA.slice(6, 12);
    await picture.enterTaggingMode();
    await picture.dragBox(SECOND_BOX);
    // Waits for the create entry, which only the answer to the typed search
    // brings: the list the picker opens with already holds the match.
    await picture.typeName(fragment);

    const entries = await picture.pickerEntries();
    // Anti-vacuity: a match and the create entry, so there is an order to check.
    expect(entries.length).toBeGreaterThanOrEqual(2);
    expect(entries[0]).toEqual({ name: ADA, create: false });
    expect(entries[entries.length - 1]).toEqual({ name: fragment, create: true });
    // Task 29: with text typed, Enter takes an exact match or else creates the typed name,
    // so the highlight rests on Anlegen, not on the first partial match.
    await expect(picture.highlightedPickerOption).toHaveAttribute('data-persons-name', fragment);
    await expect(picture.highlightedPickerOption).toHaveClass(/persons-picker-create/);
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

/** Beside the photo (modus sets html.wide) and below it. */
const WIDE_LAYOUT = { name: 'wide', viewport: { width: 1400, height: 900 }, wide: true };
const LAYOUTS = [
  WIDE_LAYOUT,
  { name: 'narrow', viewport: { width: 700, height: 900 }, wide: false },
];

/** Sub-pixel rounding between two left edges laid out by different rules. */
const ALIGN_TOLERANCE_PX = 1;

test.describe('the tag button', () => {
  /** @type {ReturnType<typeof seed>} */
  let seeded;

  test.beforeEach(() => {
    seeded = seed('empty');
  });

  test.afterEach(() => {
    restore();
  });

  for (const layout of LAYOUTS) {
    // [ECP] one partition per layout: panel beside the photo, panel below it
    test(`sits in the Personen row of the information list, ${layout.name} layout`, async ({ page }) => {
      await page.setViewportSize(layout.viewport);
      const picture = new PicturePage(page);
      await picture.goto(seeded.picture_path);
      await picture.waitForPlacement();

      // Anti-vacuity: the layout this case is named for is the one on screen.
      await expect(picture.wideLayout).toHaveCount(layout.wide ? 1 : 0);

      await expect(picture.tagButtonInRow).toBeVisible();
      const list = await picture.standardInfoList.boundingBox();
      const button = await picture.tagButtonInRow.boundingBox();
      expect(list).not.toBeNull();
      expect(button).not.toBeNull();
      expect(button.y).toBeGreaterThanOrEqual(list.y);
      expect(button.y + button.height).toBeLessThanOrEqual(list.y + list.height);
    });
  }

  // [HAPPY] the panel is below the photo here, so a message beside the toggle would be out of sight
  test('a message while drawing shows on the photo, narrow layout', async ({ page }) => {
    const narrow = LAYOUTS.find((layout) => !layout.wide);
    await page.setViewportSize(narrow.viewport);
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();
    await expect(picture.wideLayout).toHaveCount(0);

    await picture.enterTaggingMode();
    await picture.dragBox(TOO_SMALL_BOX);

    await expect(picture.editorMessage).not.toBeEmpty();
    await expect(picture.editorMessage).toBeInViewport();
    const image = await picture.imageRect();
    const message = await picture.editorMessage.boundingBox();
    expect(message).not.toBeNull();
    expect(message.x).toBeGreaterThanOrEqual(image.left);
    expect(message.y).toBeGreaterThanOrEqual(image.top);
    expect(message.x + message.width).toBeLessThanOrEqual(image.left + image.width);
    expect(message.y + message.height).toBeLessThanOrEqual(image.top + image.height);
  });

  // [ECP] a drag starting on the message rather than on bare photo. Holds because the
  // message is inside the overlay, so the mousedown bubbles to the drag handler; a
  // pointer-events mutant on the message alone does not break it (TESTING.md ledger).
  test('a drag that starts on the message still draws a box', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();
    await picture.enterTaggingMode();
    await picture.dragBox(TOO_SMALL_BOX);
    await expect(picture.editorMessage).toBeVisible();

    const image = await picture.imageRect();
    const message = await picture.editorMessage.boundingBox();
    expect(message).not.toBeNull();
    const startsOnMessage = {
      left: (message.x + message.width / 2 - image.left) / image.width,
      top: (message.y + message.height / 2 - image.top) / image.height,
      w: 0.2,
      h: 0.2,
    };
    await picture.dragBox(startsOnMessage);

    await expect(picture.picker).toBeVisible();
    await expect(picture.draft).toHaveCount(1);
  });

  // [ST] idle -> drawing -> idle
  test('is off while a box is being drawn and back after Esc', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    await expect(picture.tagButton).toBeEnabled();
    await picture.enterTaggingMode();
    await expect(picture.tagButton).toBeDisabled();
    await picture.exitTaggingMode();
    await expect(picture.tagButton).toBeEnabled();
  });
});

test.describe('the tag button in every skin', () => {
  test.afterEach(() => {
    restore();
  });

  // Anti-vacuity: the skin list was read at all.
  test('the skins are found', () => {
    expect(SKINS.length).toBeGreaterThanOrEqual(MIN_SKINS);
    expect(SKINS).toContain('newspaper');
  });

  for (const skin of SKINS) {
    // [ECP] one partition per skin: with and without a button style of its own
    test(`looks like the skin's buttons and stays in the panel in ${skin}`, async ({ page }) => {
      await page.setViewportSize(WIDE_LAYOUT.viewport);
      const seeded = seed('empty');
      const picture = new PicturePage(page);
      await picture.goto(`${seeded.picture_path}&skin=${skin}`);
      await expect(picture.tagButtonInRow).toBeVisible();
      // Anti-vacuity: the alignment below is a wide-layout rule.
      await expect(picture.wideLayout).toHaveCount(1);

      const look = await picture.tagButtonLook();
      // Anti-vacuity: the styles were really read, not two empty objects.
      expect(look.toggle.fontFamily).not.toBe('');
      expect(look.toggle.borderTopStyle).not.toBe('');
      expect(look.toggle).toEqual(look.probe);
      expect(look.toggleRight).toBeLessThanOrEqual(look.panelRight);
      // In line with the info list's values, whatever padding the skin gives the list.
      expect(Math.abs(look.toggleLeft - look.ddLeft)).toBeLessThanOrEqual(ALIGN_TOLERANCE_PX);
    });
  }
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

  // Drawn by the account the specs run as: a normal account removes only its own faces.
  test.beforeEach(() => {
    seeded = seed('overlay', { drawnByViewer: true });
  });

  test.afterEach(() => {
    restore();
  });

  test('the x sits beside the name in the row, not on the photo, and needs no drawing', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    // Anti-vacuity: there has to be a region carrying the control.
    expect(seeded.regions.length).toBeGreaterThan(0);
    const first = seeded.regions[0].region_id;

    await expect(picture.deleteButton(first)).toBeVisible();
    await expect(picture.box(first).locator('button')).toHaveCount(0);
  });

  /**
   * [ERR] Characterization: records today's label visibility, no requirement
   * confirms it. Tagging mode shows every saved box without a hover, and the
   * names with them - the user must see who is already tagged.
   */
  test('tagging mode shows every saved name without a hover', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    expect(seeded.regions.length).toBeGreaterThanOrEqual(2);

    await picture.enterTaggingMode();
    await picture.moveMouseTo(0, 0);
    // Anti-vacuity: a pointer still over the photo would show the names through
    // the hover rule, not the tagging one.
    expect(await picture.stageIsHovered()).toBe(false);

    for (const region of seeded.regions) {
      await expect
        .poll(async () => (await picture.labelStyle(region.region_id)).opacity)
        .toBeGreaterThan(0.9);
    }
  });

  test('deleting a box removes it from the page and from the file', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    const before = readFileRegions(seeded.photo_id);
    expect(before.regions.length).toBe(seeded.regions.length);

    const doomed = seeded.regions[0];
    const survivor = seeded.regions[1];

    await picture.deleteButton(doomed.region_id).click();

    await expect(picture.box(doomed.region_id)).toHaveCount(0);
    await expect(picture.box(survivor.region_id)).toHaveCount(1);

    const after = readFileRegions(seeded.photo_id);
    expect(after.regions.map((region) => region.name)).toEqual([survivor.name]);
    expect(after.persons).not.toContain(doomed.name);
  });

  /**
   * [ST] A box rendered with the page can still be deleted after a save.
   *
   * Every write re-indexes the photo; the regions it did not touch keep their
   * ids, so the ones the page was rendered with stay valid.
   */
  test('a box rendered before a save can still be deleted after it', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    await picture.enterTaggingMode();
    await picture.dragBox(CLEAR_BOX);
    await picture.typeName(ADA);
    await picture.pickerInput.press('Enter');
    await expect(picture.savedBoxes).toHaveCount(seeded.regions.length + 1);

    await picture.deleteButton(seeded.regions[0].region_id).click();

    await expect(picture.savedBoxes).toHaveCount(seeded.regions.length);
    await expect(picture.editorMessage).not.toHaveClass(/persons-editor-error/);
    expect(readFileRegions(seeded.photo_id).regions.map((region) => region.name))
      .not.toContain(seeded.regions[0].name);
  });

  /**
   * [ST] Deleting a box just added removes that box and nobody else.
   *
   * The new box takes its id from the save's answer - the one id the page did
   * not know yet. Had the other regions been renumbered, that would be
   * somebody else's.
   */
  test('a box added to a photo with faces deletes only itself', async ({ page }) => {
    // Anti-vacuity: the seeded faces are in the file to be lost.
    expect(seeded.regions.length).toBeGreaterThan(0);

    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    await picture.enterTaggingMode();
    await picture.dragBox(CLEAR_BOX);
    await picture.typeName(ADA);
    await picture.pickerInput.press('Enter');
    await expect(picture.savedBoxes).toHaveCount(seeded.regions.length + 1);

    await picture.deleteButtonOf(ADA).click();
    await expect(picture.savedBoxes).toHaveCount(seeded.regions.length);
    await expect(picture.editorMessage).not.toHaveClass(/persons-editor-error/);

    expect(readFileRegions(seeded.photo_id).regions.map((region) => region.name))
      .toEqual(seeded.regions.map((region) => region.name));
  });

  /**
   * [ST] The same, when somebody else tagged the photo after this page loaded.
   *
   * Their region's id is one this page does not know either. The box just
   * drawn must take the id of the region this save created, not theirs.
   */
  test('a box added after somebody else tagged the photo deletes only itself', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    await picture.addRegionBehindThePage(GRACE, { x: 0.15, y: 0.15, w: 0.1, h: 0.1 });
    // Anti-vacuity: the other region is in the file, and the page does not show it.
    expect(readFileRegions(seeded.photo_id).regions.map((region) => region.name)).toContain(GRACE);
    await expect(picture.savedBoxes).toHaveCount(seeded.regions.length);

    await picture.enterTaggingMode();
    await picture.dragBox(CLEAR_BOX);
    await picture.typeName(ADA);
    await picture.pickerInput.press('Enter');
    await expect(picture.savedBoxes).toHaveCount(seeded.regions.length + 1);

    await picture.deleteButtonOf(ADA).click();
    await expect(picture.savedBoxes).toHaveCount(seeded.regions.length);
    await expect(picture.editorMessage).not.toHaveClass(/persons-editor-error/);

    expect(readFileRegions(seeded.photo_id).regions.map((region) => region.name))
      .toEqual([...seeded.regions.map((region) => region.name), GRACE]);
  });
});

/**
 * The Personen row follows every save and delete while tagging, without a
 * reload.
 *
 * The row is rebuilt from the region list the API answers with, so add, delete
 * and a name tagged twice all go through one path. Names are plain text until
 * the page is loaded again - the links are core's index URLs, built on the
 * server.
 */
test.describe('the person row while tagging', () => {
  /** @type {ReturnType<typeof seed>} */
  let seeded;

  // restore() also drops the exiftool override the refused-save spec forces.
  test.afterEach(() => {
    restore();
  });

  /** [HAPPY] */
  test('a saved name appears in the row with no navigation', async ({ page }) => {
    seeded = seed('overlay');
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();
    await picture.markDocument();

    // Anti-vacuity: the seeded faces are listed, and the new one is not yet.
    await expect(picture.personRow).toContainText(seeded.regions[0].name);
    await expect(picture.personRow).not.toContainText(ADA);

    await picture.enterTaggingMode();
    await tag(picture, CLEAR_BOX, ADA, seeded.regions.length + 1);

    await expect(picture.personRowNames).toHaveText(
      [...seeded.regions.map((region) => region.name), ADA].join(', ')
    );
    expect(await picture.sameDocument()).toBe(true);
  });

  /** [BVA] zero faces -> one -> zero */
  test('the row is there without faces; the first face fills it and deleting the last one empties it', async ({ page }) => {
    seeded = seed('empty');
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    // Anti-vacuity: the row is there with its button and no names.
    await expect(picture.personRow).toBeVisible();
    await expect(picture.tagButtonInRow).toBeVisible();
    await expect(picture.personRowNames).toHaveText('');

    await tag(picture, FIRST_BOX, ADA, 1);

    await expect(picture.personRow).toBeVisible();
    await expect(picture.personRowNames).toHaveText(ADA);

    const [regionId] = await picture.savedRegionIds();
    await picture.deleteButton(Number(regionId)).click();
    await expect(picture.savedBoxes).toHaveCount(0);

    await expect(picture.personRow).toBeVisible();
    await expect(picture.personRowNames).toHaveText('');
  });

  /** [ECP] a name already listed */
  test('a second box with the same name does not list it twice', async ({ page }) => {
    seeded = seed('empty');
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    await picture.enterTaggingMode();
    await tag(picture, FIRST_BOX, ADA, 1);
    await tag(picture, SECOND_BOX, ADA, 2);

    // Anti-vacuity: both boxes carry the name, so the row had two to collapse.
    expect(await picture.savedNames()).toEqual([ADA, ADA]);
    await expect(picture.personRowNames).toHaveText(ADA);
  });

  /** [NEG] the server refuses the write */
  test('a refused save leaves the row unchanged', async ({ page }) => {
    seeded = seed('overlay');
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();
    const before = await picture.personRowNames.textContent();
    // Anti-vacuity: the seeded faces are listed, so "unchanged" means something.
    expect(before).toContain(seeded.regions[0].name);

    // After the page is loaded, so the editor is offered and only the save fails.
    setExiftool('missing');

    await picture.enterTaggingMode();
    await picture.dragBox(CLEAR_BOX);
    await picture.typeName(ADA);
    await picture.pickerInput.press('Enter');

    await expect(picture.editorMessage).toHaveClass(/persons-editor-error/);
    await expect(picture.personRowNames).toHaveText(before);
  });

  /** [ECP] the JS rule and the PHP rule agree - drift between them shows here */
  test('the row reads the same before and after a reload', async ({ page }) => {
    seeded = seed('overlay', { drawnByViewer: true });
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    await picture.deleteButton(seeded.regions[0].region_id).click();
    await expect(picture.savedBoxes).toHaveCount(seeded.regions.length - 1);
    await tag(picture, CLEAR_BOX, ADA, seeded.regions.length);

    const live = [...seeded.regions.slice(1).map((region) => region.name), ADA].join(', ');
    await expect(picture.personRowNames).toHaveText(live);

    await page.reload();
    await picture.waitForPlacement();

    await expect(picture.personRowNames).toHaveText(live);
  });

  /**
   * [NEG] Markup in a name is rendered as text.
   *
   * The plugin's own write path strips tags from a name, so the API is made to
   * answer with one here - the row must not trust that a name is clean.
   */
  test('markup in a name renders as text in the live row', async ({ page }) => {
    seeded = seed('empty');
    const picture = new PicturePage(page);
    await page.route(/method=pwg\.persons\.addRegion/, async (route) => {
      const response = await route.fetch();
      const data = await response.json();
      data.result.regions.forEach((region) => {
        region.name = MARKUP_NAME;
      });
      await route.fulfill({ response, json: data });
    });

    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    await picture.enterTaggingMode();
    await tag(picture, FIRST_BOX, ADA, 1);

    await expect(picture.personRowNames).toHaveText(MARKUP_NAME);
    await expect(picture.personRowNames.locator('i')).toHaveCount(0);
  });
});

/**
 * One drawing is one action: "+ Person markieren", a drag, a name - and the
 * drawing ends by itself, with the row and the boxes updated in place and no
 * reload.
 */
test.describe('one-shot marking', () => {
  /** @type {ReturnType<typeof seed>} */
  let seeded;

  // restore() also drops the exiftool override the refused-save spec forces.
  test.afterEach(() => {
    restore();
  });

  async function openEmpty(page) {
    seeded = seed('empty');
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();
    await picture.markDocument();
    return picture;
  }

  /** [HAPPY] */
  test('one press, a drag and a name save one box; the button is back and the page did not reload', async ({ page }) => {
    const picture = await openEmpty(page);

    await picture.enterTaggingMode();
    await picture.dragBox(FIRST_BOX);
    await picture.typeName(ADA);
    await picture.pickerInput.press('Enter');

    await expect(picture.savedBoxes).toHaveCount(1);
    await expect(picture.taggingStage).toHaveCount(0);
    await expect(picture.draft).toHaveCount(0);
    await expect(picture.tagButton).toBeEnabled();
    await expect(picture.personRowNames).toHaveText(ADA);
    // The link to the person's page comes with the answer, not with a reload.
    await expect(picture.personRowLinks).toHaveText([ADA]);
    expect(await picture.sameDocument()).toBe(true);
    expect(readFileRegions(seeded.photo_id).regions).toHaveLength(1);
  });

  /** [ST] drawing, name chosen -> idle: a second box needs the button again */
  test('a second box needs the button once more', async ({ page }) => {
    const picture = await openEmpty(page);

    await tag(picture, FIRST_BOX, ADA, 1);
    await expect(picture.taggingStage).toHaveCount(0);

    // Dragging with the drawing ended starts nothing.
    await picture.dragBox(SECOND_BOX);
    await expect(picture.draft).toHaveCount(0);
    await expect(picture.picker).toBeHidden();

    await tag(picture, SECOND_BOX, GRACE, 2);
    await expect(picture.personRowNames).toHaveText(`${ADA}, ${GRACE}`);
  });

  /** [HAPPY] */
  test('Esc while a box is drawn cancels it without a request', async ({ page }) => {
    const picture = await openEmpty(page);
    const writes = [];
    page.on('request', (request) => {
      if (/method=pwg\.persons\.(add|delete)Region/.test(request.url())) {
        writes.push(request.url());
      }
    });

    await picture.enterTaggingMode();
    await picture.dragBox(FIRST_BOX);
    await expect(picture.picker).toBeVisible();
    await expect(picture.draft).toHaveCount(1);

    await picture.pickerInput.press('Escape');

    await expect(picture.taggingStage).toHaveCount(0);
    await expect(picture.draft).toHaveCount(0);
    await expect(picture.picker).toBeHidden();
    await expect(picture.tagButton).toBeEnabled();
    expect(writes).toEqual([]);
    expect(await picture.sameDocument()).toBe(true);
  });

  /** [HAPPY] Esc before any drag */
  test('Esc before a drag ends the drawing', async ({ page }) => {
    const picture = await openEmpty(page);

    await picture.enterTaggingMode();
    await expect(picture.editorMessage).not.toBeEmpty();
    await picture.exitTaggingMode();

    await expect(picture.tagButton).toBeEnabled();
    await expect(picture.editorMessage).toBeEmpty();
  });

  /** [ST] the save is on its way when Esc ends the drawing: the box still lands */
  test('Esc while the save is pending still adopts the box when the answer lands', async ({ page }) => {
    const picture = await openEmpty(page);
    const release = await holdSaves(page);

    await picture.enterTaggingMode();
    await picture.dragBox(FIRST_BOX);
    await picture.typeName(ADA);
    const saving = page.waitForRequest(/method=pwg\.persons\.addRegion/);
    await picture.pickerInput.press('Enter');
    await saving;

    await expect(picture.rowStatus).toHaveClass(/pwg-row-status--saving/);
    await picture.exitTaggingMode();
    // The button stays off while a write is on its way.
    await expect(picture.tagButton).toBeDisabled();

    release();

    await expect(picture.savedBoxes).toHaveCount(1);
    await expect(picture.draft).toHaveCount(0);
    await expect(picture.tagButton).toBeEnabled();
    await expect(picture.personRowNames).toHaveText(ADA);
    expect(await picture.sameDocument()).toBe(true);
  });

  /** [NEG] a refused save leaves the box to retry, with one red status */
  test('a refused save keeps the box and shows one red status', async ({ page }) => {
    const picture = await openEmpty(page);
    setExiftool('missing');

    await picture.enterTaggingMode();
    await picture.dragBox(FIRST_BOX);
    await picture.typeName(ADA);
    await picture.pickerInput.press('Enter');

    await expect(picture.rowStatus).toHaveClass(/pwg-row-status--error/);
    await expect(picture.rowStatus).not.toHaveClass(/pwg-row-status--saved/);
    await expect(picture.rowStatus).toContainText('Nicht gespeichert');
    await expect(picture.draft).toHaveCount(1);
    await expect(picture.taggingStage).toHaveCount(1);

    await picture.exitTaggingMode();
    await expect(picture.draft).toHaveCount(0);
    expect(await picture.sameDocument()).toBe(true);
  });

  /** [NEG] the file write of the person tag failed: one red status, never green beside red */
  test('a partly written save shows one red status and no green one', async ({ page }) => {
    const picture = await openEmpty(page);
    await page.route(/method=pwg\.persons\.addRegion/, async (route) => {
      const response = await route.fetch();
      const data = await response.json();
      data.result.tags_written = false;
      data.result.tags_message = 'keine Schreibrechte';
      await route.fulfill({ response, json: data });
    });

    await tag(picture, FIRST_BOX, ADA, 1);

    await expect(picture.rowStatus).toHaveClass(/pwg-row-status--error/);
    await expect(picture.rowStatus).not.toHaveClass(/pwg-row-status--saved/);
    await expect(picture.rowStatus).toContainText('keine Schreibrechte');
  });

  /** [HAPPY] the status of a good save */
  test('a saved box says so on the row', async ({ page }) => {
    const picture = await openEmpty(page);

    await tag(picture, FIRST_BOX, ADA, 1);

    await expect(picture.rowStatus).toHaveClass(/pwg-row-status--saved/);
    await expect(picture.rowStatus).toHaveText(/^✓ Gespeichert( · Rückgängig)?$/);
  });

  /**
   * [NEG] A page core rendered straight from a form post - a comment does that -
   * is never navigated: nothing reloads, so no form is sent again.
   */
  test('a save on a posted page sends no navigation', async ({ page }) => {
    seeded = seed('empty');
    const picture = new PicturePage(page);
    await picture.gotoByPost(seeded.picture_path);
    await picture.waitForPlacement();
    await picture.markDocument();

    const navigations = [];
    page.on('request', (request) => {
      if (request.isNavigationRequest()) {
        navigations.push(request.method());
      }
    });

    await tag(picture, FIRST_BOX, ADA, 1);

    expect(navigations).toEqual([]);
    expect(await picture.sameDocument()).toBe(true);
  });

  /** [ST] starting a drawing while a save is pending is not possible */
  test('the button is off while a save is on its way', async ({ page }) => {
    const picture = await openEmpty(page);
    const release = await holdSaves(page);

    await picture.enterTaggingMode();
    await picture.dragBox(FIRST_BOX);
    await picture.typeName(ADA);
    const saving = page.waitForRequest(/method=pwg\.persons\.addRegion/);
    await picture.pickerInput.press('Enter');
    await saving;

    await expect(picture.tagButton).toBeDisabled();
    release();
    await expect(picture.tagButton).toBeEnabled();
  });
});

/** Deleting from the Personen row. */
test.describe('deleting from the Personen row', () => {
  /** @type {ReturnType<typeof seed>} */
  let seeded;

  test.beforeEach(() => {
    seeded = seed('overlay', { drawnByViewer: true });
  });

  test.afterEach(() => {
    restore();
  });

  /** [HAPPY] */
  test('the x removes the name and its box in place, with a saved status', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();
    await picture.markDocument();
    const [removed, ...kept] = seeded.regions.map((region) => region.name);

    await picture.deleteButton(seeded.regions[0].region_id).click();

    await expect(picture.savedBoxes).toHaveCount(seeded.regions.length - 1);
    await expect(picture.personRowLinks).toHaveText(kept);
    await expect(picture.personRowNames).not.toContainText(removed);
    await expect(picture.rowStatus).toHaveClass(/pwg-row-status--saved/);
    expect(await picture.sameDocument()).toBe(true);
  });

  /** [HAPPY] */
  test('pointing at a name shows its box, focusing it does too', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();
    const first = seeded.regions[0];

    await expect(picture.box(first.region_id)).not.toHaveClass(/person-box-highlight/);
    await picture.nameEntry(first.name).hover();
    await expect(picture.box(first.region_id)).toHaveClass(/person-box-highlight/);
    await expect(picture.box(seeded.regions[1].region_id)).not.toHaveClass(/person-box-highlight/);

    await page.mouse.move(0, 0);
    await expect(picture.box(first.region_id)).not.toHaveClass(/person-box-highlight/);

    await picture.nameEntry(first.name).locator('.persons-name-delete').focus();
    await expect(picture.box(first.region_id)).toHaveClass(/person-box-highlight/);
  });

  /** [HAPPY] a box drawn on this page, by this account, gets its x without a reload */
  test('a box just added shows its x at once', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();

    await tag(picture, CLEAR_BOX, ADA, seeded.regions.length + 1);

    await expect(picture.deleteButtonOf(ADA)).toBeVisible();
    await expect(picture.deleteButtonOf(ADA)).toHaveAttribute('title', /.+/);
  });
});

/**
 * [NEG] A normal account removes only the faces it drew (decision 0065). The
 * seeded faces were written into the file, so nobody drew them here.
 */
test.describe('a face the normal account did not draw', () => {
  /** @type {ReturnType<typeof seed>} */
  let seeded;

  test.beforeEach(() => {
    seeded = seed('overlay');
  });

  test.afterEach(() => {
    restore();
  });

  test('offers no delete control, while a face it draws gets one', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);
    await picture.waitForPlacement();
    expect(seeded.regions.length).toBeGreaterThan(0);

    for (const region of seeded.regions) {
      // Anti-vacuity: the box itself is there, only its control is not.
      await expect(picture.box(region.region_id)).toHaveCount(1);
      await expect(picture.deleteButton(region.region_id)).toHaveCount(0);
    }

    await tag(picture, CLEAR_BOX, ADA, seeded.regions.length + 1);
    await expect(picture.deleteButtonOf(ADA)).toBeVisible();
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
    await expect(picture.tagButton).toHaveCount(1);
    await expect(picture.tagButton).toBeDisabled();
    await expect(picture.tagButton).toHaveAttribute('title', /.+/);
  });
});
