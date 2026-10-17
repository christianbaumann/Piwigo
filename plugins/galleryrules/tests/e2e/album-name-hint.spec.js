// @ts-check
const { test, expect } = require('@playwright/test');
const { AlbumsPage } = require('./support/AlbumsPage');
const { albumCount, albumNameHint, trackAlbum, restore } = require('./support/seed');

// The create dialog of Alben > Verwaltung says "Name fehlt" instead of staying silent on a blank name.
let hint;

test.beforeAll(() => {
  restore();
  hint = albumNameHint();
});

test.afterAll(() => {
  restore();
});

// [NEG] Hinzufügen with an empty name shows the hint, keeps the dialog open and sends nothing.
test('a blank name shows the hint and creates no album', async ({ page }) => {
  const albums = new AlbumsPage(page);
  await albums.goto();
  const before = albumCount();
  const sent = albums.watchAddRequests();
  await albums.openCreateDialog();
  await expect(albums.nameMissingHint).toBeHidden();

  await albums.save();

  await expect(albums.nameMissingHint).toBeVisible();
  await expect(albums.nameMissingHint).toHaveText(hint);
  await expect(albums.dialog).toBeVisible();
  expect(sent).toEqual([]);
  expect(albumCount()).toBe(before);
});

// [ECP] A name of spaces only, saved with Enter, is blank too.
test('a name of spaces saved with Enter shows the hint and creates no album', async ({ page }) => {
  const albums = new AlbumsPage(page);
  await albums.goto();
  const before = albumCount();
  const sent = albums.watchAddRequests();
  await albums.openCreateDialog();
  await albums.typeName('   ');

  await albums.saveWithEnter();

  await expect(albums.nameMissingHint).toBeVisible();
  await expect(albums.dialog).toBeVisible();
  expect(sent).toEqual([]);
  expect(albumCount()).toBe(before);
});

// [ST] hint shown -> a name typed -> hint gone, and the album is created.
test('typing a name hides the hint and the album is created', async ({ page }) => {
  const name = `galleryrules-e2e-named-${Date.now()}`;
  const albums = new AlbumsPage(page);
  await albums.goto();
  await albums.openCreateDialog();
  await albums.save();
  await expect(albums.nameMissingHint).toBeVisible();

  await albums.typeName(name);
  await expect(albums.nameMissingHint).toBeHidden();
  await albums.save();

  await expect(albums.treeAlbum(name)).toHaveCount(1);
  trackAlbum(name);
  await expect(albums.dialog).toBeHidden();
});
