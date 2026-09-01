// @ts-check
const { test, expect } = require('@playwright/test');
const { AlbumPropertiesPage } = require('./support/AlbumPropertiesPage');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * The full album-provenance walkthrough a handbook reader follows: fill the
 * four fields, save, then apply.
 *
 * Automates the manual box docs/agents/plans/2026-09-01-handbuch-corrections-and-
 * typetags-permission-fix.md Phase 3 opened - "enter the four fields on a real
 * album, save, confirm the photo page shows nothing; press Auf N Fotos
 * anwenden, confirm the Herkunft row appears." That sequence is the finding
 * the new handbuch/01-alben.html section documents: saving the album form
 * alone leaves the public photo page unchanged, which without that section
 * reads as broken.
 *
 * Nothing here restates the existing suites: album-provenance.spec.js already
 * covers the modal save round-trip on its own, apply-provenance.spec.js
 * already covers the apply progress bar and chunking, and provenance.spec.js
 * already covers the public row's rendering for a photo that already carries
 * provenance. This spec is the one that walks the sequence between them, on
 * the public page the handbook names ("Fotoseite").
 */

test.describe('the album provenance walkthrough the handbook documents', () => {
  test.afterEach(async () => {
    restore();
  });

  test('the public page stays empty after save and shows the row after apply', async ({ page }) => {
    const fixture = seed('no-provenance');
    expect(fixture.photo_ids.length).toBeGreaterThan(0);
    const photoId = fixture.photo_ids[0];

    const album = new AlbumPropertiesPage(page);
    await album.goto(fixture.album_id);
    await album.openButton.click();
    await expect(album.modal).toBeVisible();

    const entered = {
      provenance_physical_album: 'Walkthrough-Testalbum',
      provenance_owner: 'Walkthrough-Tester',
      provenance_scanned_on: '2026-09-01',
      provenance_note: 'Walkthrough-Notiz',
    };
    await album.fill(entered);
    await album.saveButton.click();
    await expect(album.message).not.toBeEmpty();

    // Saving the album form alone must not reach the photo - the fact the
    // handbook's warning box exists to state.
    const picture = new PicturePage(page);
    await picture.goto(photoId, fixture.album_id);
    await expect(picture.infoList).toBeVisible();
    await expect(picture.rowAnywhere).toHaveCount(0);

    await album.goto(fixture.album_id);
    await expect(album.applyButton).toBeVisible();
    await album.applyButton.click();
    await expect(album.applyDone).toBeVisible();

    await picture.goto(photoId, fixture.album_id);
    await expect(picture.row).toBeVisible();
    await expect(picture.value).toContainText(entered.provenance_physical_album);
    await expect(picture.value).toContainText(entered.provenance_owner);
  });
});
