// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore, ageDerivatives } = require('./support/seed');

/**
 * After an edit, the photo's URLs are the same as before it: core links an
 * existing derivative directly under _data/i/, and nginx serves it with only a
 * Last-Modified header, from which the browser may decide to reuse its copy
 * without asking (heuristic freshness). A real scan's derivatives are days
 * old, so a browser that saw the photo before the edit could keep showing it.
 *
 * Seeded with a copy of a real-size scan (seed --scenario=photo): the marked
 * photo is smaller than every derivative, so the page shows its file directly.
 */

/** @type {ReturnType<typeof seed>} */
let fixture;

test.beforeEach(() => {
  restore();
  fixture = seed('photo');
});

test.afterEach(() => {
  restore();
});

test('[ST] a later visit shows the turned photo, not one the browser cached before the edit', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  ageDerivatives();

  // The derivative now exists and looks days old: the page links it directly,
  // and the browser may keep it without asking the server again.
  await picture.goto(fixture.picture_path);
  expect(await picture.imageSrc(), 'anti-vacuity: the photo is not served from _data/i').toContain('/_data/i/');
  const before = await picture.naturalSize();
  expect(before.width, 'anti-vacuity: the seeded photo is not landscape').toBeGreaterThan(before.height);

  await picture.editButton.click();
  await picture.turnRightButton.click();
  await picture.saveAndWaitForReload();

  // The reload built the new derivative, so this visit links it directly again,
  // at the same URL as before the edit.
  await picture.goto(fixture.picture_path);
  expect(await picture.imageSrc()).toContain('/_data/i/');
  const after = await picture.naturalSize();
  expect(after.height).toBeGreaterThan(after.width);
});
