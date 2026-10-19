// @ts-check
const { test, expect } = require('@playwright/test');
const { BatchUnitPage } = require('./support/BatchUnitPage');
const { seed, restore, trackTag, makeReadOnly, readRow } = require('./support/seed');
const { germanTextOf } = require('./support/lang');

/**
 * Stapelverarbeitung, unit mode, saves through pwg.images.setInfo. When photoinfo
 * could not write the date, the Info or the tags into the photo's file, the
 * photo's badge says so in German instead of showing the green success badge.
 * The answer's fields are pinned one layer down (CoreEditTest); this witnesses
 * what the screen's JavaScript makes of them.
 */
const RUN = Date.now().toString(36);

/** The picture page's warning for a read-only file: saved, but not written, and why. */
function notWrittenWarning() {
  return germanTextOf('PHOTOINFO_NOT_WRITTEN_MESSAGE')
    .replace('%s', germanTextOf('PHOTOINFO_FILE_NOT_WRITABLE_MESSAGE'));
}

test.afterEach(() => {
  restore();
});

test('[NEG] a changed Info on a read-only file shows the German reason in the badge, and stays saved', async ({ page }) => {
  const photo = seed('photo');
  makeReadOnly(photo.image_id);
  const unit = new BatchUnitPage(page);
  await unit.open(photo.album_id);

  await unit.setInfo(photo.image_id, `Am See ${RUN}`);
  await unit.save(photo.image_id);

  await expect(unit.errorBadge(photo.image_id)).toBeVisible();
  await expect(unit.errorMessage(photo.image_id)).toHaveText(notWrittenWarning());
  await expect(unit.successBadge(photo.image_id)).toBeHidden();
  expect(readRow(photo.image_id).comment).toBe(`Am See ${RUN}`);
});

test('[NEG] a changed date on a read-only file shows the German reason in the badge', async ({ page }) => {
  const photo = seed('photo');
  makeReadOnly(photo.image_id);
  const unit = new BatchUnitPage(page);
  await unit.open(photo.album_id);

  await unit.setDate(photo.image_id, '1965-03-14');
  await unit.save(photo.image_id);

  await expect(unit.errorBadge(photo.image_id)).toBeVisible();
  await expect(unit.errorMessage(photo.image_id)).toHaveText(notWrittenWarning());
  await expect(unit.successBadge(photo.image_id)).toBeHidden();
});

test('[NEG] a new tag on a read-only file shows the German reason in the badge', async ({ page }) => {
  const photo = seed('photo');
  makeReadOnly(photo.image_id);
  const name = `Kirmes unit ${RUN}`;
  const unit = new BatchUnitPage(page);
  await unit.open(photo.album_id);

  await unit.addNewTag(photo.image_id, name);
  await unit.save(photo.image_id);
  trackTag(name);

  await expect(unit.errorBadge(photo.image_id)).toBeVisible();
  await expect(unit.errorMessage(photo.image_id)).toHaveText(notWrittenWarning());
  await expect(unit.successBadge(photo.image_id)).toBeHidden();
});

test('[HAPPY] a changed Info on a writable file shows the success badge and no reason', async ({ page }) => {
  const photo = seed('photo');
  const unit = new BatchUnitPage(page);
  await unit.open(photo.album_id);

  await unit.setInfo(photo.image_id, `Am See ${RUN}`);
  await unit.save(photo.image_id);

  await expect(unit.successBadge(photo.image_id)).toBeVisible();
  await expect(unit.errorBadge(photo.image_id)).toBeHidden();
});
