// @ts-check
const { test, expect } = require('@playwright/test');
const { GalleryPage } = require('./support/GalleryPage');
const { statePath } = require('./support/accounts');
const seed = require('./support/seed');

/**
 * Titel, Autor, Info, an album's name and its description are plain text
 * (decision 0073): a stored <img onerror> is shown as text, and no script of it
 * runs. The page source half is PlainTextFieldsTest; this is the browser half,
 * where an onerror would actually fire.
 *
 * Runs as a normal account, the one every visitor page is served to.
 */
test.use({ storageState: statePath('NORMAL') });

/** @type {ReturnType<typeof seed.seedPayload>} */
let fixture;

test.beforeEach(() => {
  seed.restore();
  fixture = seed.seedPayload();
});

test.afterEach(() => {
  seed.restore();
});

// [HAPPY] Titel, Autor and Info on the picture page.
test('the picture page shows the stored markup as text and runs none of it', async ({ page }) => {
  const gallery = new GalleryPage(page);

  await gallery.goto(fixture.picture_path);

  // Image errors fire before the load event goto() waits for.
  expect(await gallery.ranPayload(fixture.marker)).toBeUndefined();

  await expect(gallery.author()).toHaveText(fixture.payloads.author);
  await expect(gallery.content()).toContainText(fixture.payloads.name);
  await expect(gallery.content()).toContainText(fixture.payloads.comment);
  await expect(gallery.content()).toContainText(fixture.payloads.album_name);
});

// [HAPPY] The album's name and description, and the photo's thumbnail, on the album page.
test('the album page shows the stored markup as text and runs none of it', async ({ page }) => {
  const gallery = new GalleryPage(page);

  await gallery.goto(fixture.album_path);

  expect(await gallery.ranPayload(fixture.marker)).toBeUndefined();

  await expect(gallery.albumDescription()).toHaveText(fixture.payloads.album_description);
  await expect(gallery.content()).toContainText(fixture.payloads.album_name);
});

// [ECP] The album list shows the album's name the same way.
test('the album list shows the stored album name as text and runs none of it', async ({ page }) => {
  const gallery = new GalleryPage(page);

  await gallery.goto(fixture.album_list_path);

  expect(await gallery.ranPayload(fixture.marker)).toBeUndefined();

  await expect(gallery.content()).toContainText(fixture.payloads.album_name);
});
