// @ts-check
const { test, expect } = require('@playwright/test');
const { BatchManagerPage } = require('./support/BatchManagerPage');
const { seed, readPhoto, restore } = require('./support/seed');

/**
 * The Batch Manager move prompt, in a real browser.
 *
 * The gap this closes was the last unperformed manual step of the provenance
 * plan: "the move prompt appears and its three choices behave as labelled".
 * Nobody had ever confirmed it - it was blocked on a seedable install, then the
 * install was resynchronised and the block was gone.
 *
 * What is *not* restated here: BatchManagerPageTest already proves the radios
 * reach the page source and that one exists per mode the resolver accepts, and
 * InheritTest already proves each mode's database outcome when the parameter is
 * posted directly. Neither can see the two facts below.
 *
 *  - A panel core shows and hides with jQuery may render the radios into markup
 *    nobody can reach. Page source cannot tell a visible control from a hidden
 *    one.
 *  - The radios are injected into core's form by a prefilter. Whether the
 *    admin's choice actually *travels* with the Batch Manager's own submit is a
 *    fact about the assembled form, and it is exactly what a misplaced
 *    injection breaks - silently, with every move taking the unattended default.
 *
 * Why 'keep' gets no move of its own: it is the default, and it is also what a
 * lost parameter produces. A browser test of 'keep' would pass just as happily
 * with the injection deleted, which is the definition of an assertion that
 * cannot fail. The two modes that visibly differ from the default are driven
 * instead; 'keep' is covered where it can be distinguished - InheritTest posts
 * it explicitly and separately from posting nothing.
 */

/** Shorter than this and the batch manager did not render. */
const MIN_LABEL_LENGTH = 4;

test.describe('batch manager move prompt', () => {
  test.afterEach(async () => {
    restore();
  });

  test('the prompt appears with one labelled choice per mode [HAPPY]', async ({ page }) => {
    const fixture = seed('move');
    const labels = fixture.move_mode_labels;

    const batch = new BatchManagerPage(page);
    await batch.goto(fixture.album_id);

    // The filter must really have left the seeded photo on the page, or every
    // assertion below is about an empty batch manager.
    await expect(batch.thumbnailCheckboxes).toHaveCount(fixture.photo_count);
    expect(fixture.photo_count).toBeGreaterThan(0);

    // The panel is hidden until its action is chosen: proving it becomes
    // visible is the whole point, so assert it was not visible first.
    await expect(batch.moveModeBlock).toBeHidden();

    await batch.selectAllPhotos();
    await batch.chooseAction('move');

    await expect(batch.moveModeBlock).toBeVisible();
    await expect(batch.moveModeTitle).toHaveText(labels.title);
    expect(labels.title.length).toBeGreaterThan(MIN_LABEL_LENGTH);

    // One radio per mode, and each carries the label that says what it does -
    // both sides read out of production by seed.php, never typed here.
    const modes = Object.keys(labels).filter((key) => key !== 'title');
    expect(modes.length).toBeGreaterThan(1);
    await expect(batch.moveModeRadios).toHaveCount(modes.length);

    for (const mode of modes) {
      const radio = batch.moveModeRadio(mode);
      await expect(radio).toBeVisible();
      expect(labels[mode].length).toBeGreaterThan(MIN_LABEL_LENGTH);
      await expect(radio.locator('xpath=..')).toHaveText(labels[mode]);
    }

    // Keeping what the photo has is the safe default, so it is preselected.
    await expect(batch.moveModeRadio('keep')).toBeChecked();
  });

  test("'replace' moves the photo and gives it the destination album's provenance [ST]", async ({ page }) => {
    const fixture = seed('move');
    const photoId = fixture.photo_ids[0];
    const destination = fixture.destination;

    const before = readPhoto(photoId);
    expect(before.provenance_owner).toBe(fixture.values.provenance_owner);
    expect(before.provenance_owner).not.toBe(destination.values.provenance_owner);

    const batch = new BatchManagerPage(page);
    await batch.goto(fixture.album_id);
    await batch.selectAllPhotos();
    await batch.chooseAction('move');
    await batch.moveModeRadio('replace').check();
    await batch.chooseDestination(destination.album_name);
    await batch.apply();

    const after = readPhoto(photoId);
    expect(after.provenance_owner).toBe(destination.values.provenance_owner);
    expect(after.provenance_physical_album).toBe(destination.values.provenance_physical_album);
    expect(after.provenance_scanned_on).toBe(destination.values.provenance_scanned_on);
    expect(after.provenance_album_note).toBe(destination.values.provenance_note);
  });

  test("'clear' moves the photo and leaves it with no album provenance [ST]", async ({ page }) => {
    const fixture = seed('move');
    const photoId = fixture.photo_ids[0];
    const destination = fixture.destination;

    expect(readPhoto(photoId).provenance_owner).toBe(fixture.values.provenance_owner);

    const batch = new BatchManagerPage(page);
    await batch.goto(fixture.album_id);
    await batch.selectAllPhotos();
    await batch.chooseAction('move');
    await batch.moveModeRadio('clear').check();
    await batch.chooseDestination(destination.album_name);
    await batch.apply();

    const after = readPhoto(photoId);
    expect(after.provenance_physical_album).toBeNull();
    expect(after.provenance_owner).toBeNull();
    expect(after.provenance_scanned_on).toBeNull();
    expect(after.provenance_album_note).toBeNull();
  });
});
