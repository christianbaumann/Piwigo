// @ts-check
const { test, expect } = require('@playwright/test');
const { AlbumPropertiesPage } = require('./support/AlbumPropertiesPage');
const { PhotoPropertiesPage } = require('./support/PhotoPropertiesPage');
const { seed, restore } = require('./support/seed');

/**
 * The copy-down apply, in a real browser.
 *
 * These automate the two manual boxes Phase 5 opened:
 *   - "the progress bar advances and completes for the 76-photo album"
 *   - "a deliberate mid-run failure surfaces in the UI rather than silently
 *     stalling"
 *
 * Nothing here restates ApplyTest. That suite asserts what reached the database
 * for one chunk; everything below needs the browser to have run the injected
 * script - the chunking, the serialization and the progress bar are computed in
 * the page and are unreachable from page source.
 */

/**
 * Below this the album is too small for chunking to be worth asserting.
 *
 * The client halves the album, so any album of two or more photos is sent as
 * more than one request - which is what the request count below checks.
 */
const MIN_PHOTOS = 2;

test.describe('applying album provenance to its photos', () => {
  test.afterEach(async () => {
    restore();
  });

  test('the progress bar advances and completes for the whole album', async ({ page }) => {
    const fixture = seed('with-provenance');
    expect(fixture.photo_count).toBeGreaterThanOrEqual(MIN_PHOTOS);
    expect(fixture.values.provenance_owner).toBeTruthy();

    const album = new AlbumPropertiesPage(page);
    await album.goto(fixture.album_id);

    // Hidden until a run starts: an empty bar on arrival would read as a run
    // stuck at zero.
    await expect(album.applyButton).toBeVisible();
    await expect(album.applyProgress).toBeHidden();

    // Count the chunks the page really sends, so a client that quietly stopped
    // chunking - one request for the whole album - fails here.
    let requests = 0;
    page.on('request', (request) => {
      if (request.url().includes('method=pwg.provenance.applyToPhotos')) requests++;
    });

    await album.applyButton.click();

    await expect(album.applyProgress).toBeVisible();
    await expect(album.applyDone).toBeVisible();
    await expect(album.applyMessage).toContainText(String(fixture.photo_count));
    await expect(album.applyMessage).not.toHaveClass(/provenance-error/);

    // Every photo was covered, and the album really was cut into chunks rather
    // than sent as one request that would meet the production 60 s ceiling.
    expect(await album.applyCounts()).toEqual({ done: fixture.photo_count, total: fixture.photo_count });
    expect(requests).toBeGreaterThan(1);
    await expect(album.applyBarFill).toBeVisible();

    // The values really arrived on a photo, read back off a second screen.
    const photo = new PhotoPropertiesPage(page);
    await photo.goto(fixture.photo_ids[0]);
    await expect(photo.inherited).toContainText(fixture.values.provenance_physical_album);
    await expect(photo.inherited).toContainText(fixture.values.provenance_owner);
  });

  test('an application-level failure surfaces instead of stalling', async ({ page }) => {
    const fixture = seed('with-provenance');

    const album = new AlbumPropertiesPage(page);
    await album.goto(fixture.album_id);

    // A PwgError answers HTTP 200 with stat:"fail", so this arrives in the
    // success callback - a different code path from the abort below, which is
    // why both are tested.
    await page.route('**/ws.php**', async (route) => {
      if (route.request().url().includes('method=pwg.provenance.applyToPhotos')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ stat: 'fail', err: 500, message: 'seeded failure' }),
        });
        return;
      }
      await route.continue();
    });

    await album.applyButton.click();

    await expect(album.applyMessage).toHaveClass(/provenance-error/);
    await expect(album.applyMessage).toContainText('seeded failure');
  });

  test('a network-level failure surfaces instead of stalling', async ({ page }) => {
    const fixture = seed('with-provenance');

    const album = new AlbumPropertiesPage(page);
    await album.goto(fixture.album_id);

    await page.route('**/ws.php**', async (route) => {
      if (route.request().url().includes('method=pwg.provenance.applyToPhotos')) {
        await route.abort('failed');
        return;
      }
      await route.continue();
    });

    await album.applyButton.click();

    await expect(album.applyMessage).toHaveClass(/provenance-error/);
    await expect(album.applyMessage).not.toBeEmpty();
  });
});
