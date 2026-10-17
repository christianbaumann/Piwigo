// @ts-check
const path = require('path');
const { test, expect } = require('@playwright/test');
const { seed, restore, readFileRegions, personCounts } = require('./support/seed');
const { PicturePage } = require('./support/PicturePage');
const { germanTextOf } = require('./support/lang');

/**
 * The marking editor's controls (task 29, design "The marking editor: delete,
 * draw, labels, picker"): the delete control above every box, a drag that
 * starts inside a frame, labels cut with an ellipsis and shown whole on hover,
 * Enter in the picker, and a delete of a mark that is already gone.
 *
 * Each case runs as the normal account and as an administrator who is not the
 * webmaster. The boxes a role draws are its own, so both may delete them
 * (decision 0065); the case that differs is a box nobody drew, below.
 */

const STATE = path.join(__dirname, '.state');

const ROLES = [
  { role: 'normal', state: 'auth.json' },
  { role: 'admin', state: 'auth-administrator.json' },
];

const NAME_A = 'E2E Marking Alma';
const NAME_B = 'E2E Marking Bruno';
const KNOWN = 'E2E Marking Known';
const LONG_NAME = 'E2E Marking Wolfeschlegelsteinhausenbergerdorf';

const BOX_A = { left: 0.25, top: 0.3, w: 0.2, h: 0.25 };
/** Covers the top-right corner of BOX_A, where its delete control sits, and starts on bare photo. */
const BOX_OVER_A_CORNER = { left: 0.4, top: 0.25, w: 0.2, h: 0.2 };
/** Entirely inside BOX_A and clear of its label at the bottom edge. */
const BOX_INSIDE_A = { left: 0.3, top: 0.35, w: 0.1, h: 0.1 };
/** Narrow and at the photo's left edge, so a long name is cut and its full width runs past the edge. */
const NARROW_AT_EDGE = { left: 0.01, top: 0.3, w: 0.06, h: 0.2 };
/** Starts on bare photo and lies over the full-width label of NARROW_AT_EDGE. */
const BOX_OVER_LABEL = { left: 0.1, top: 0.42, w: 0.25, h: 0.2 };

async function tagAs(picture, box, name, expectedBoxes) {
  await picture.dragBox(box);
  await picture.typeName(name);
  await picture.pickerInput.press('Enter');
  await expect(picture.savedBoxes).toHaveCount(expectedBoxes);
}

for (const { role, state } of ROLES) {
  test.describe(`marking editor, as ${role}`, () => {
    test.use({ storageState: path.join(STATE, state) });

    /** @type {ReturnType<typeof seed>} */
    let seeded;

    test.beforeEach(() => {
      seeded = seed('empty');
    });

    test.afterEach(() => {
      restore();
    });

    test('[HAPPY] the delete control of a box another box lies over is still on top and works', async ({ page }) => {
      const picture = new PicturePage(page);
      await picture.goto(seeded.picture_path);
      await picture.waitForPlacement();
      await picture.enterTaggingMode();

      await tagAs(picture, BOX_A, NAME_A, 1);
      await tagAs(picture, BOX_OVER_A_CORNER, NAME_B, 2);

      const control = picture.deleteButtonOf(NAME_A);
      // Anti-vacuity: the later box really lies over the earlier box's corner.
      const [first, second] = await picture.savedRegionIds();
      const corner = await picture.boxRect(Number(first));
      const over = await picture.boxRect(Number(second));
      expect(over.left).toBeLessThan(corner.left + corner.width);
      expect(over.top).toBeLessThan(corner.top);
      expect(over.top + over.height).toBeGreaterThan(corner.top);

      expect(await picture.isTopmostAtCentre(control)).toBe(true);
      await control.click({ timeout: 2000 });

      await expect(picture.savedBoxes).toHaveCount(1);
      expect(await picture.savedNames()).toEqual([NAME_B]);
    });

    test('[HAPPY] a drag starting inside a saved frame draws a new frame', async ({ page }) => {
      const picture = new PicturePage(page);
      await picture.goto(seeded.picture_path);
      await picture.waitForPlacement();
      await picture.enterTaggingMode();
      await tagAs(picture, BOX_A, NAME_A, 1);

      await picture.dragBox(BOX_INSIDE_A);
      await expect(picture.draft).toHaveCount(1);
      await expect(picture.picker).toBeVisible();
      await picture.typeName(NAME_B);
      await picture.pickerInput.press('Enter');

      await expect(picture.savedBoxes).toHaveCount(2);
      expect(readFileRegions(seeded.photo_id).regions.map((region) => region.name).sort())
        .toEqual([NAME_A, NAME_B]);
    });

    test('[ERR] the name label and the x of a frame stay clickable while tagging', async ({ page }) => {
      // Characterization of today's behaviour (a mousedown on either leaves the click to the control); no requirement confirms it.
      const picture = new PicturePage(page);
      await picture.goto(seeded.picture_path);
      await picture.waitForPlacement();
      await picture.enterTaggingMode();
      await tagAs(picture, BOX_A, NAME_A, 1);
      await picture.goto(seeded.picture_path);
      await picture.waitForPlacement();
      await picture.enterTaggingMode();

      const [regionId] = await picture.savedRegionIds();
      const link = picture.label(Number(regionId));
      await expect(link).toHaveAttribute('href', /.+/);
      const before = page.url();
      await Promise.all([page.waitForURL((url) => url.href !== before), link.click()]);
    });

    for (const tagging of [true, false]) {
      test(`[HAPPY] a long name ends in an ellipsis and shows whole, on top and inside the photo, ${tagging ? 'while tagging' : 'outside tagging'}`, async ({ page }) => {
        const picture = new PicturePage(page);
        await picture.goto(seeded.picture_path);
        await picture.waitForPlacement();
        await picture.enterTaggingMode();
        await tagAs(picture, NARROW_AT_EDGE, LONG_NAME, 1);
        await tagAs(picture, BOX_OVER_LABEL, NAME_B, 2);

        if (!tagging) {
          await picture.goto(seeded.picture_path);
          await picture.waitForPlacement();
        }

        const [regionId] = await picture.savedRegionIds();
        const id = Number(regionId);

        await picture.moveMouseTo(0, 0);
        const resting = await picture.labelGeometry(id);
        expect(resting.textOverflow).toBe('ellipsis');
        // Anti-vacuity: the name really is longer than its box lets it be.
        expect(resting.truncated).toBe(true);
        expect(resting.width).toBeLessThanOrEqual(resting.boxWidth + 1);

        await picture.hoverLabel(id);
        await expect.poll(async () => (await picture.labelGeometry(id)).truncated).toBe(false);
        const hovered = await picture.labelGeometry(id);
        expect(hovered.width).toBeGreaterThan(resting.width);
        expect(hovered.left).toBeGreaterThanOrEqual(hovered.overlayLeft - 0.5);
        expect(hovered.right).toBeLessThanOrEqual(hovered.overlayRight + 0.5);
        expect(hovered.topmost).toBe(true);
      });
    }

    test('[HAPPY] Enter right after typing creates the typed name, not the first partial match', async ({ page }) => {
      const picture = new PicturePage(page);
      await picture.goto(seeded.picture_path);
      await picture.waitForPlacement();
      await picture.enterTaggingMode();
      await tagAs(picture, BOX_A, KNOWN, 1);
      await picture.deleteButtonOf(KNOWN).click();
      await expect(picture.savedBoxes).toHaveCount(0);
      // Anti-vacuity: the person the fragment would wrongly pick exists.
      expect(personCounts()[KNOWN]).toBeDefined();

      const fragment = KNOWN.slice(0, -2);
      await picture.dragBox(BOX_A);
      await expect(picture.picker).toBeVisible();
      await picture.pickerInput.fill(fragment);
      await picture.pickerInput.press('Enter');

      await expect(picture.savedBoxes).toHaveCount(1);
      expect(await picture.savedNames()).toEqual([fragment]);
      const counts = personCounts();
      expect(counts[fragment].regions).toBe(1);
      expect(counts[KNOWN].regions).toBe(0);
    });

    test('[HAPPY] Enter right after typing an exact name, in other letter case, commits that person', async ({ page }) => {
      const picture = new PicturePage(page);
      await picture.goto(seeded.picture_path);
      await picture.waitForPlacement();
      await picture.enterTaggingMode();
      await tagAs(picture, BOX_A, KNOWN, 1);
      await picture.deleteButtonOf(KNOWN).click();
      await expect(picture.savedBoxes).toHaveCount(0);

      await picture.dragBox(BOX_A);
      await expect(picture.picker).toBeVisible();
      await picture.pickerInput.fill(KNOWN.toLowerCase());
      await picture.pickerInput.press('Enter');

      await expect(picture.savedBoxes).toHaveCount(1);
      expect(await picture.savedNames()).toEqual([KNOWN]);
      expect(personCounts()[KNOWN].regions).toBe(1);
    });

    test('[ST] deleting a mark that is already gone removes the box and says so', async ({ page }) => {
      const picture = new PicturePage(page);
      await picture.goto(seeded.picture_path);
      await picture.waitForPlacement();
      await picture.enterTaggingMode();
      await tagAs(picture, BOX_A, NAME_A, 1);
      await expect(picture.personRow).toBeVisible();

      const [regionId] = await picture.savedRegionIds();
      await picture.deleteRegionBehindThePage(Number(regionId));
      // Anti-vacuity: the file no longer holds it, and the page still shows the box.
      expect(readFileRegions(seeded.photo_id).regions).toHaveLength(0);
      await expect(picture.savedBoxes).toHaveCount(1);

      await picture.deleteButton(Number(regionId)).click();

      await expect(picture.savedBoxes).toHaveCount(0);
      await expect(picture.editorMessage).toHaveText(germanTextOf('PERSONS_MARK_GONE'));
      await expect(picture.editorMessage).not.toHaveClass(/persons-editor-error/);
      await expect(picture.personRow).toBeHidden();
    });
  });
}

test.describe('a box nobody drew, as an administrator who is not the webmaster', () => {
  test.use({ storageState: path.join(STATE, 'auth-administrator.json') });

  test('[HAPPY] its delete control stays on top of a box drawn over it', async ({ page }) => {
    const seededOverlay = seed('overlay');
    try {
      const picture = new PicturePage(page);
      await picture.goto(seededOverlay.picture_path);
      await picture.waitForPlacement();
      await picture.enterTaggingMode();

      const jane = seededOverlay.regions[0];
      await picture.dragBox({ left: 0.3, top: 0.25, w: 0.15, h: 0.15 });
      await picture.typeName(NAME_B);
      await picture.pickerInput.press('Enter');
      await expect(picture.savedBoxes).toHaveCount(seededOverlay.regions.length + 1);

      const control = picture.deleteButton(jane.region_id);
      expect(await picture.isTopmostAtCentre(control)).toBe(true);
      await control.click({ timeout: 2000 });
      await expect(picture.box(jane.region_id)).toHaveCount(0);
    } finally {
      restore();
    }
  });
});

test.describe('a box nobody drew, as the normal account', () => {
  test('[NEG] has no delete control, and a box drawn over it has its own', async ({ page }) => {
    const seededOverlay = seed('overlay');
    try {
      const picture = new PicturePage(page);
      await picture.goto(seededOverlay.picture_path);
      await picture.waitForPlacement();
      await picture.enterTaggingMode();

      await picture.dragBox({ left: 0.3, top: 0.25, w: 0.15, h: 0.15 });
      await picture.typeName(NAME_B);
      await picture.pickerInput.press('Enter');
      await expect(picture.savedBoxes).toHaveCount(seededOverlay.regions.length + 1);

      await expect(picture.deleteButton(seededOverlay.regions[0].region_id)).toHaveCount(0);
      await expect(picture.deleteButtonOf(NAME_B)).toBeVisible();
    } finally {
      restore();
    }
  });
});
