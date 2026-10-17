// @ts-check
const { test, expect } = require('@playwright/test');
const { AdminTagsPage } = require('./support/AdminTagsPage');
const { seedTags, restore } = require('./support/seed');

// Schlagwortverwaltung: the menu counts "1 Foto" / "n Fotos", a refused rename says why,
// and search and delete work after a Duplikat.
/** @type {ReturnType<typeof seedTags>} */
let seeded;

test.beforeEach(() => {
  restore();
  seeded = seedTags();
});

test.afterEach(() => {
  restore();
});

// [ECP] one photo -> singular, two photos -> plural; first as the server renders the page, then
// as tags.js renders the boxes a search brings back.
test('the tag menu says "1 Foto" for one photo and "2 Fotos" for two', async ({ page }) => {
  const tags = new AdminTagsPage(page);
  await tags.open();

  await expect(await tags.menuCount(seeded.names.one)).toHaveText('1 Foto');
  await expect(await tags.menuCount(seeded.names.two)).toHaveText('2 Fotos');

  // One box left means tags.js has re-filled the boxes the search kept.
  await tags.find(seeded.names.one);
  await expect(tags.allBoxes).toHaveCount(1);
  await expect(await tags.menuCount(seeded.names.one)).toHaveText('1 Foto');

  await tags.find(seeded.names.two);
  await expect(tags.allBoxes).toHaveCount(1);
  await expect(await tags.menuCount(seeded.names.two)).toHaveText('2 Fotos');
  expect(tags.scriptErrors).toEqual([]);
});

// [NEG] a rename onto a name another tag holds is refused, and the screen says so.
test('renaming to an existing name shows the error', async ({ page }) => {
  const tags = new AdminTagsPage(page);
  await tags.open();
  await tags.find(seeded.names.none);

  await tags.rename(seeded.names.none, seeded.names.one);

  await expect(tags.error).toBeVisible();
  await expect(tags.error).toContainText(seeded.names.one);
});

// [ST] after a Duplikat, search and delete throw nothing and the copy goes.
test('search and delete work after a Duplikat', async ({ page }) => {
  const tags = new AdminTagsPage(page);
  await tags.open();
  await tags.find(seeded.names.one);
  await expect(tags.box(seeded.names.one)).toHaveCount(1);

  await tags.duplicate(seeded.names.one);
  await expect(tags.box(seeded.names.one)).toHaveCount(2);

  await tags.find(seeded.names.one);
  await expect(tags.box(seeded.names.one)).toHaveCount(2);
  await tags.delete(seeded.names.one, 1);

  await expect(tags.box(seeded.names.one)).toHaveCount(1);
  expect(tags.scriptErrors).toEqual([]);
});

// [ECP] the confirm of the unused-tag cleanup lists one name per line under a counted heading.
test('the unused-tag review lists one name per line', async ({ page }) => {
  const tags = new AdminTagsPage(page);
  await tags.open();

  await tags.reviewLink.click();

  const lines = await tags.reviewLines();
  expect(lines[0]).toMatch(/\(\d+\):$/);
  expect(lines.slice(1)).toContain(seeded.names.none);
  expect(lines.length - 1).toBe(Number(/\((\d+)\)/.exec(lines[0])?.[1]));
});
