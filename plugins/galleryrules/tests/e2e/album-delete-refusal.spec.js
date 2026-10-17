// @ts-check
const { test, expect } = require('@playwright/test');
const { AlbumPropertiesPage } = require('./support/AlbumPropertiesPage');
const { AlbumsPage } = require('./support/AlbumsPage');
const { seedSameName, seedEmpty, restore } = require('./support/seed');

// The same-name seed is reused only for its album whose photos have no other album; the file name plays no part.
const REFUSAL_WORDS = 'in keinem Album mehr';

test.beforeEach(() => {
  restore();
});

test.afterAll(() => {
  restore();
});

// [NEG] The properties page shows the 409 message of a delete that would leave photos in no album.
test('the properties page shows why "keep photos" was refused and the album stays', async ({ page }) => {
  const seeded = seedSameName();
  const properties = new AlbumPropertiesPage(page);
  await properties.goto(seeded.album_id);

  await properties.deleteKeepingPhotos();

  await expect(properties.errorLine).toBeVisible();
  await expect(properties.errorLine).toContainText(REFUSAL_WORDS);
  await expect(properties.errorLine).toContainText(`#${seeded.image_ids[0]}`);
  await expect(properties.confirmDialog).toHaveCount(0);
  await expect(page).toHaveURL(new RegExp(`album-${seeded.album_id}-properties`));
  await properties.goto(seeded.album_id);
  await expect(properties.deleteLink).toBeVisible();
});

// [NEG] Alben > Verwaltung shows it in the dialog, which stays open and usable.
test('Alben > Verwaltung shows why "keep photos" was refused, the album stays and the dialog still cancels', async ({ page }) => {
  const seeded = seedSameName();
  const albums = new AlbumsPage(page);
  await albums.goto();
  await albums.openDeleteDialog(seeded.album_id);

  await albums.confirmDeleteButton.click();

  await expect(albums.deleteError).toBeVisible();
  await expect(albums.deleteError).toContainText(REFUSAL_WORDS);
  await expect(albums.deleteError).toContainText(`#${seeded.image_ids[0]}`);
  await expect(albums.deleteDialog).toBeVisible();
  await albums.cancelDeleteButton.click();
  await expect(albums.deleteDialog).toBeHidden();
  await expect(page.locator(`.tree .move-cat-delete[data-id="${seeded.album_id}"]`)).toHaveCount(1);

  await albums.openDeleteDialog(seeded.album_id);
  await expect(albums.deleteError).toBeHidden();
});

// [ERR] A refusal without a JSON body shows the HTTP status text, not an empty box.
test('Alben > Verwaltung shows the status text when the refusal has no parsable body', async ({ page }) => {
  const seeded = seedSameName();
  await page.route('**/ws.php?*pwg.categories.delete*', (route) =>
    route.fulfill({ status: 500, contentType: 'text/html', body: '<html>boom</html>' }));
  const albums = new AlbumsPage(page);
  await albums.goto();
  await albums.openDeleteDialog(seeded.album_id);

  await albums.confirmDeleteButton.click();

  await expect(albums.deleteError).toBeVisible();
  await expect(albums.deleteError).not.toHaveText('');
  await expect(albums.deleteDialog).toBeVisible();
});

// [HAPPY] An allowed delete still works on both screens.
test('the properties page still deletes an album that leaves no photo behind', async ({ page }) => {
  const seeded = seedEmpty();
  const properties = new AlbumPropertiesPage(page);
  await properties.goto(seeded.album_id);

  await properties.deleteKeepingPhotos();

  await expect(page).toHaveURL(/page=albums/);
  await expect(page.locator(`.tree .move-cat-delete[data-id="${seeded.album_id}"]`)).toHaveCount(0);
});

test('Alben > Verwaltung still deletes an album that leaves no photo behind', async ({ page }) => {
  const seeded = seedEmpty();
  const albums = new AlbumsPage(page);
  await albums.goto();
  await albums.openDeleteDialog(seeded.album_id);

  await albums.confirmDeleteButton.click();

  await expect(albums.deleteDialog).toBeHidden();
  await expect(page.locator(`.tree .move-cat-delete[data-id="${seeded.album_id}"]`)).toHaveCount(0);
});
