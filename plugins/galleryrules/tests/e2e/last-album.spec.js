// @ts-check
const { test, expect } = require('@playwright/test');
const { PhotoPropertiesPage } = require('./support/PhotoPropertiesPage');
const { seedSameName, restore } = require('./support/seed');

// The same-name seed is reused only for its album holding photos; the file name plays no part.
let seeded;

test.beforeAll(() => {
  restore();
  seeded = seedSameName();
});

test.afterAll(() => {
  restore();
});

// [NEG] Removing the photo's only album on its properties page and saving keeps the album and says why.
test('the properties page keeps a photo\'s last album', async ({ page }) => {
  const photo = seeded.image_ids[0];
  const properties = new PhotoPropertiesPage(page);
  await properties.goto(photo);
  await expect(properties.linkedAlbums).toHaveCount(1);

  await properties.removeAlbum(seeded.album_id);
  await expect(properties.linkedAlbums).toHaveCount(0);
  await properties.save();

  await expect(properties.errors).toHaveCount(1);
  await expect(properties.errors).toContainText(`#${photo}`);
  await expect(properties.errors).toContainText('in keinem Album mehr');
  await expect(properties.linkedAlbums).toHaveCount(1);
});
