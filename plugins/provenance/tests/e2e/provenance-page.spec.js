// @ts-check
const path = require('path');
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { ProvenancePage } = require('./support/ProvenancePage');
const { seed, restore } = require('./support/seed');

/** WCAG AA minimum contrast for body text. */
const MIN_TEXT_CONTRAST = 4.5;

/**
 * The picture page's provenance row links to the read-only provenance page,
 * and a normal account reaches it. What the page contains, and who is refused,
 * is ReadOnlyPageTest; this spec owns the click through in a browser and the
 * page's legibility, which only a browser can compute.
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

  // The modus skin "newspaper" colours every dt and dd white for its dark
  // picture sidebar; on this page's light background that hid every value.
  // The case reads the install's skin, so it only goes red without the fix
  // while a skin like that is selected (newspaper is modus' default).
  test('the labels and values are readable against the page', async ({ page }) => {
    const fixture = seed('photo-provenance');
    await page.goto(`/index.php?/provenance/photo/${fixture.photo.id}`);
    const provenance = new ProvenancePage(page);
    await expect(provenance.value('provenance_note')).toBeVisible();
    await expect(provenance.labels.first()).toBeVisible();

    expect(await provenance.contrast(provenance.labels.first())).toBeGreaterThanOrEqual(MIN_TEXT_CONTRAST);
    expect(await provenance.contrast(provenance.value('provenance_note'))).toBeGreaterThanOrEqual(MIN_TEXT_CONTRAST);
  });
});
