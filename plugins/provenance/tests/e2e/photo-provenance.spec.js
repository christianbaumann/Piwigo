// @ts-check
const { test, expect } = require('@playwright/test');
const { PhotoPropertiesPage } = require('./support/PhotoPropertiesPage');
const { seed, restore } = require('./support/seed');

/**
 * The provenance block on the photo properties screen, in a real browser.
 *
 * The gap this closes: SetPhotoInfoTest proves the web service writes the note
 * and that the server emits the block, but the save itself is entirely
 * client-side - a click handler in photo_provenance.js firing an AJAX request
 * and writing the outcome into the page. Page source cannot witness any of it,
 * so until these ran, nobody had ever proved that pressing the button on the
 * photo screen saves anything.
 *
 * Nothing here restates SetPhotoInfoTest: that suite owns what reached the
 * database and what the server emitted.
 */

/** The four album-sourced facts the screen shows read-only. */
const INHERITED_FACTS = 4;

/** Two lines whose tops differ by less than this are the same line. */
const MIN_LINE_GAP = 2;

test.describe('photo provenance block', () => {
  test.afterEach(async () => {
    restore();
  });

  test('the note saves and persists across a reload', async ({ page }) => {
    const fixture = seed('photo-provenance');
    expect(fixture.photo.values.provenance_note).toBeTruthy();

    const photo = new PhotoPropertiesPage(page);
    await photo.goto(fixture.photo.id);

    // Arrives holding what is really in the database, so the value asserted
    // after the reload can only have come from the save.
    await expect(photo.note).toHaveValue(fixture.photo.values.provenance_note);

    const edited = 'Rückseite neu gelesen: Herbst 1973';
    await photo.note.fill(edited);
    await photo.saveButton.click();

    // The plugin answers a refused save on the same success callback, so an
    // empty message means the request has not come back yet, not that it worked.
    await expect(photo.message).not.toBeEmpty();
    await expect(photo.message).not.toHaveClass(/provenance-error/);

    await photo.reload();
    await expect(photo.note).toHaveValue(edited);
  });

  test('the album-sourced values are shown but not editable', async ({ page }) => {
    const fixture = seed('photo-provenance');

    const photo = new PhotoPropertiesPage(page);
    await photo.goto(fixture.photo.id);

    // Every album-sourced value is on the screen, read from the seeder rather
    // than typed again here.
    for (const column of ['provenance_physical_album', 'provenance_owner', 'provenance_scanned_on']) {
      await expect(photo.inherited).toContainText(fixture.photo.values[column]);
    }
    await expect(photo.inherited).toContainText(fixture.photo.values.provenance_album_note);

    // And none of them is an input. These columns are album-authoritative: a
    // field that accepted a change and silently dropped it would be worse than
    // no field at all.
    await expect(photo.inheritedControls).toHaveCount(0);
    await expect(photo.inheritedItems).toHaveCount(INHERITED_FACTS);
  });

  test('each album-sourced fact is on its own line', async ({ page }) => {
    const fixture = seed('photo-provenance');

    const photo = new PhotoPropertiesPage(page);
    await photo.goto(fixture.photo.id);

    const tops = await photo.inheritedRowTops();
    expect(tops).toHaveLength(INHERITED_FACTS);

    // Run together on one line the four facts read as a single sentence, and
    // the album name runs straight into the next label.
    for (let i = 1; i < tops.length; i++) {
      expect(tops[i] - tops[i - 1]).toBeGreaterThan(MIN_LINE_GAP);
    }
  });

  test('an application-level failure surfaces instead of stalling', async ({ page }) => {
    const fixture = seed('photo-provenance');

    const photo = new PhotoPropertiesPage(page);
    await photo.goto(fixture.photo.id);

    // A PwgError answers HTTP 200 with stat:"fail", so this arrives in the
    // success callback - a different code path from the abort below, which is
    // why both are tested.
    await page.route('**/ws.php**', async (route) => {
      if (route.request().url().includes('method=pwg.provenance.setPhotoInfo')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ stat: 'fail', err: 500, message: 'seeded failure' }),
        });
        return;
      }
      await route.continue();
    });

    await photo.note.fill('wird nicht gespeichert');
    await photo.saveButton.click();

    await expect(photo.message).toHaveClass(/provenance-error/);
    await expect(photo.message).toContainText('seeded failure');
  });

  test('a network-level failure surfaces instead of stalling', async ({ page }) => {
    const fixture = seed('photo-provenance');

    const photo = new PhotoPropertiesPage(page);
    await photo.goto(fixture.photo.id);

    await page.route('**/ws.php**', async (route) => {
      if (route.request().url().includes('method=pwg.provenance.setPhotoInfo')) {
        await route.abort('failed');
        return;
      }
      await route.continue();
    });

    await photo.note.fill('wird nicht gespeichert');
    await photo.saveButton.click();

    await expect(photo.message).toHaveClass(/provenance-error/);
    await expect(photo.message).not.toBeEmpty();
  });
});
