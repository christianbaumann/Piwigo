// @ts-check
const { test, expect } = require('@playwright/test');
const { AlbumPropertiesPage } = require('./support/AlbumPropertiesPage');
const { GalleryPage } = require('./support/GalleryPage');
const { seedEmpty, restore } = require('./support/seed');

let seeded;

test.beforeAll(() => {
  restore();
  seeded = seedEmpty();
});

test.afterAll(() => {
  restore();
});

// [HAPPY] "In Galerie öffnen" on an empty album leads to its page, which says visitors do not see it.
test('an empty album opened from its properties says visitors do not see it', async ({ page }) => {
  const properties = new AlbumPropertiesPage(page);
  await properties.goto(seeded.album_id);
  await properties.openInGallery();

  await expect(page).toHaveURL(new RegExp(`category/${seeded.album_id}\\b`));
  const gallery = new GalleryPage(page);
  await expect(gallery.infos()).toHaveCount(1);
  await expect(gallery.infos()).toContainText(seeded.note);
});
