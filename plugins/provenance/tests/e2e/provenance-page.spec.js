// @ts-check
const path = require('path');
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { ProvenancePage } = require('./support/ProvenancePage');
const { seed, restore } = require('./support/seed');

/**
 * The picture page's provenance row links to the read-only provenance page,
 * and a normal account reaches it. What the page contains, and who is refused,
 * is ReadOnlyPageTest; this spec owns only the click through in a browser.
 */
test.describe('read-only provenance page', () => {
  test.use({ storageState: path.join(__dirname, '.state', 'auth-normal.json') });

  test.afterEach(async () => {
    restore();
  });

  test('the row links a normal account to the photo provenance page', async ({ page }) => {
    const fixture = seed('photo-provenance');
    expect(fixture.photo.values.provenance_note).toBeTruthy();

    const picture = new PicturePage(page);
    await picture.goto(fixture.photo.id, fixture.album_id);
    await expect(picture.pageLink).toBeVisible();

    await picture.pageLink.click();

    await expect(page).toHaveURL(new RegExp(`/provenance/photo/${fixture.photo.id}$`));
    const provenance = new ProvenancePage(page);
    await expect(provenance.root).toBeVisible();
    await expect(provenance.value('provenance_note')).toHaveText(fixture.photo.values.provenance_note);
    await expect(provenance.value('provenance_owner')).toHaveText(fixture.photo.values.provenance_owner);
    await expect(provenance.controls).toHaveCount(0);
  });
});
