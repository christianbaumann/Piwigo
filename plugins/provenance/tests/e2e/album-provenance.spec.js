// @ts-check
const { test, expect } = require('@playwright/test');
const { AlbumPropertiesPage } = require('./support/AlbumPropertiesPage');
const { seed, restore } = require('./support/seed');

/**
 * The provenance block on the album properties screen, in a real browser.
 *
 * These automate the two manual boxes Phase 4 opened:
 *   - "the modal opens, saves, and the values persist across a page reload"
 *   - "the injected block does not disturb the existing Properties layout at
 *     narrow widths"
 *
 * Nothing here restates the integration suite. That suite asserts what the
 * server emitted and what reached the database; everything below needs the
 * browser to have run the injected script - the modal is hidden by CSS and
 * revealed by jQuery, and the layout facts are computed by the engine.
 *
 * What stays manual afterwards is listed in docs/agents/TESTING.md.
 */

/** A button smaller than this is collapsed, whatever the DOM says. */
const MIN_CONTROL_WIDTH = 20;
const MIN_CONTROL_HEIGHT = 10;

/**
 * A form field narrower than this cannot hold the text it is for.
 *
 * Deliberately far above MIN_CONTROL_WIDTH: a field squeezed to 5px still
 * measures 24px once its padding and border are counted, so a button-sized
 * floor would let a collapsed field pass. Measured against a mutant, 2026-08-29.
 */
const MIN_FIELD_WIDTH = 100;

/**
 * The narrowest viewport at which the album screen's own layout still fits.
 *
 * Measured 2026-08-29: with the provenance block hidden, this page reports a
 * scrollWidth of 979px at every viewport below 1024, so core itself overflows
 * there. That is pre-existing and has nothing to do with this plugin - so the
 * layout check below asserts the two buttons' geometry at the narrowest width
 * where core does fit, rather than a document-level overflow figure that no
 * change to this block can move (see docs/agents/TESTING.md).
 */
const NARROW = { width: 1024, height: 900 };

/** A genuinely small window, for the modal - which is sized in vw and really does adapt. */
const PHONE = { width: 360, height: 780 };

test.describe('album provenance block', () => {
  test.afterEach(async () => {
    restore();
  });

  test('the modal opens, saves, and the values persist across a reload', async ({ page }) => {
    const fixture = seed('no-provenance');
    expect(fixture.album_id).toBeGreaterThan(0);
    expect(Object.values(fixture.values)).toEqual([null, null, null, null]);

    const album = new AlbumPropertiesPage(page);
    await album.goto(fixture.album_id);

    // Hidden until asked for: the block must not push itself into the screen.
    await expect(album.openButton).toBeVisible();
    await expect(album.modal).toBeHidden();

    await album.openButton.click();
    await expect(album.modal).toBeVisible();

    // Empty on arrival, because the album really has no provenance yet - so the
    // values asserted after the reload can only have come from the save.
    expect(await album.fieldValues()).toEqual({
      provenance_physical_album: '',
      provenance_owner: '',
      provenance_scanned_on: '',
      provenance_note: '',
    });

    const entered = {
      provenance_physical_album: 'Oma Müllers Fotoalbum',
      provenance_owner: 'Anna Müller',
      provenance_scanned_on: '2026-08-29',
      provenance_note: 'geliehen im August',
    };
    await album.fill(entered);
    await album.saveButton.click();

    // The plugin answers a refused save on the same success callback, so an
    // empty message means the request has not come back yet, not that it worked.
    await expect(album.message).not.toBeEmpty();
    const confirmation = await album.message.textContent();

    await album.reload();
    await album.openButton.click();
    await expect(album.modal).toBeVisible();

    expect(await album.fieldValues()).toEqual(entered);
    expect(confirmation).not.toContain('null');
  });

  test('a seeded album arrives with its values already in the fields', async ({ page }) => {
    const fixture = seed('with-provenance');
    expect(fixture.values.provenance_owner).toBeTruthy();

    const album = new AlbumPropertiesPage(page);
    await album.goto(fixture.album_id);
    await album.openButton.click();

    // The expected values come from the seeding CLI, not from this file: a
    // second copy would rot the day only one of the two is edited.
    expect(await album.fieldValues()).toEqual(fixture.values);
  });

  test('the injected button does not disturb the footer at a narrow width', async ({ page }) => {
    const fixture = seed('with-provenance');

    await page.setViewportSize(NARROW);
    const album = new AlbumPropertiesPage(page);
    await album.goto(fixture.album_id);

    const provenance = await album.openButton.boundingBox();
    const save = await album.albumSaveButton.boundingBox();

    expect(provenance).not.toBeNull();
    expect(save).not.toBeNull();
    if (provenance === null || save === null) return;

    // Both controls are really painted, not collapsed to nothing.
    for (const box of [provenance, save]) {
      expect(box.width).toBeGreaterThan(MIN_CONTROL_WIDTH);
      expect(box.height).toBeGreaterThan(MIN_CONTROL_HEIGHT);
    }

    // Neither is pushed off the right edge by the other.
    expect(provenance.x).toBeGreaterThanOrEqual(0);
    expect(provenance.x + provenance.width).toBeLessThanOrEqual(NARROW.width);
    expect(save.x + save.width).toBeLessThanOrEqual(NARROW.width);

    // And they do not sit on top of each other: either they share a row and are
    // side by side, or the row has wrapped and they are stacked.
    const overlapsHorizontally =
      provenance.x < save.x + save.width && save.x < provenance.x + provenance.width;
    const overlapsVertically =
      provenance.y < save.y + save.height && save.y < provenance.y + provenance.height;
    expect(overlapsHorizontally && overlapsVertically).toBe(false);
  });

  test('the modal is usable in a tiny window', async ({ page }) => {
    const fixture = seed('with-provenance');

    await page.setViewportSize(PHONE);
    const album = new AlbumPropertiesPage(page);
    await album.goto(fixture.album_id);
    await album.openButton.click();

    await expect(album.modal).toBeVisible();

    for (const field of [album.physicalAlbum, album.owner, album.scannedOn, album.note]) {
      const box = await field.boundingBox();
      expect(box).not.toBeNull();
      if (box === null) continue;
      expect(box.width).toBeGreaterThan(MIN_FIELD_WIDTH);
      expect(box.x).toBeGreaterThanOrEqual(0);
      expect(box.x + box.width).toBeLessThanOrEqual(PHONE.width);
    }

    await expect(album.saveButton).toBeVisible();
  });
});
