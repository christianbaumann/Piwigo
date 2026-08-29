// @ts-check
const { test, expect } = require('@playwright/test');
const { AlbumPropertiesPage } = require('./support/AlbumPropertiesPage');
const { seed, restore } = require('./support/seed');
const { readTags } = require('./support/metadata');

/**
 * The file write-back, in a real browser.
 *
 * Nothing here restates WriteBackTest. That suite asserts what one chunk put in
 * a file across ws.php; everything below needs the browser to have run the
 * injected script - the chunking, the serialization, the progress bar and the
 * failure summary are computed in the page and are unreachable from page source.
 *
 * The album is created by the seed and holds nothing but copies. The write-back
 * writes every photo of the album it is started from, so pointing this at a real
 * album would put metadata into the collection's own scans.
 */

/** Below this the album is too small for chunking to be worth asserting. */
const MIN_PHOTOS = 2;

/** exiftool's family-1 group for EXIF:ImageDescription is the IFD it sits in. */
const EXIF_DESCRIPTION = 'IFD0:ImageDescription';

test.describe('writing provenance into the image files', () => {
  test.afterEach(async () => {
    restore();
  });

  test('the run completes and the files really carry the metadata', async ({ page }) => {
    const fixture = seed('writeback');
    expect(fixture.photo_count).toBeGreaterThanOrEqual(MIN_PHOTOS);
    expect(fixture.photo_files).toHaveLength(fixture.photo_count);
    expect(fixture.values.provenance_owner).toBeTruthy();

    const album = new AlbumPropertiesPage(page);
    await album.goto(fixture.album_id);

    // Hidden until a run starts: an empty bar on arrival would read as a run
    // stuck at zero.
    await expect(album.writeButton).toBeVisible();
    await expect(album.writeProgress).toBeHidden();

    // Count the chunks the page really sends. The write-back's ceiling is far
    // below the copy-down's because each photo is an exiftool invocation, so a
    // client that stopped chunking would walk straight into the 60 s ceiling.
    let requests = 0;
    page.on('request', (request) => {
      if (request.url().includes('method=pwg.provenance.writeBack')) requests++;
    });

    await album.writeButton.click();

    await expect(album.writeProgress).toBeVisible();
    await expect(album.writeDone).toBeVisible({ timeout: 20_000 });
    await expect(album.writeMessage).not.toHaveClass(/provenance-error/);
    await expect(album.writeMessage).toContainText(String(fixture.photo_count));

    expect(await album.writeCounts()).toEqual({ done: fixture.photo_count, total: fixture.photo_count });
    expect(requests).toBeGreaterThan(1);

    // The click really reached the files. Without this the spec would pass over
    // a button that only animated a bar.
    for (const file of fixture.photo_files) {
      const tags = readTags(file);
      expect(tags[EXIF_DESCRIPTION]).toContain(fixture.values.provenance_owner);
      expect(tags['IPTC:Caption-Abstract']).toContain(fixture.values.provenance_physical_album);
      expect(tags['XMP-pwgprov:Owner']).toBe(fixture.values.provenance_owner);
      expect(tags['XMP-pwgprov:PhotoNote']).toBe(fixture.photo_note);
    }
  });

  test('photos the server could not write are summarised, not swallowed', async ({ page }) => {
    const fixture = seed('writeback');

    const album = new AlbumPropertiesPage(page);
    await album.goto(fixture.album_id);

    // The one shape unique to this operation: a chunk succeeds as a request
    // while individual photos inside it failed (decision 13a - one unwritable
    // file must not abandon an album of 76). The count has to reach the user.
    await page.route('**/ws.php**', async (route) => {
      if (route.request().url().includes('method=pwg.provenance.writeBack')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ stat: 'ok', result: { written: 0, failed: { 1: 'seeded failure' } } }),
        });
        return;
      }
      await route.continue();
    });

    await album.writeButton.click();

    await expect(album.writeDone).toBeVisible();
    // Both halves: nothing was written, and the failures were counted rather
    // than reported as a clean run.
    await expect(album.writeMessage).toContainText('0');
    await expect(album.writeMessage).toContainText(/[1-9]/);

    // Nothing reached the files, so the summary is not merely cosmetic.
    for (const file of fixture.photo_files) {
      expect(readTags(file)[EXIF_DESCRIPTION]).toBeUndefined();
    }
  });

  test('an application-level failure surfaces instead of stalling', async ({ page }) => {
    const fixture = seed('writeback');

    const album = new AlbumPropertiesPage(page);
    await album.goto(fixture.album_id);

    // A PwgError answers HTTP 200 with stat:"fail", so this arrives in the
    // success callback - a different code path from the abort below.
    await page.route('**/ws.php**', async (route) => {
      if (route.request().url().includes('method=pwg.provenance.writeBack')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ stat: 'fail', err: 501, message: 'seeded failure' }),
        });
        return;
      }
      await route.continue();
    });

    await album.writeButton.click();

    await expect(album.writeMessage).toHaveClass(/provenance-error/);
    await expect(album.writeMessage).toContainText('seeded failure');
  });

  test('a network-level failure surfaces instead of stalling', async ({ page }) => {
    const fixture = seed('writeback');

    const album = new AlbumPropertiesPage(page);
    await album.goto(fixture.album_id);

    await page.route('**/ws.php**', async (route) => {
      if (route.request().url().includes('method=pwg.provenance.writeBack')) {
        await route.abort('failed');
        return;
      }
      await route.continue();
    });

    await album.writeButton.click();

    await expect(album.writeMessage).toHaveClass(/provenance-error/);
    await expect(album.writeMessage).not.toBeEmpty();
  });
});
