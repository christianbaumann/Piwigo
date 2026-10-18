// @ts-check
const { test, expect } = require('@playwright/test');
const { seed, restore } = require('./support/seed');
const { BatchUnitPage } = require('./support/BatchUnitPage');

/**
 * The Batch Manager's unit mode saves through pwg.images.setInfo. Taking a
 * marked person's tag off a photo is refused there (TagToolGuardTest); this
 * spec witnesses what the screen's JavaScript makes of the refusal. Runs as
 * the webmaster (chromium-admin).
 */

const PERSON = 'E2E Overlay Jane';
const PLAIN = 'E2E Guard Plain';

/** @type {ReturnType<typeof seed>} */
let seeded;

test.beforeEach(() => {
  seeded = seed('overlay', { colouredTags: true });
  expect(seeded.person_tag_ids[PERSON]).toBeGreaterThan(0);
});

test.afterEach(() => {
  restore();
});

test('[NEG] removing a person tag is refused, the error badge shows and the tag stays', async ({ page }) => {
  const unit = new BatchUnitPage(page);
    await unit.open(seeded.album_id);
  // Anti-vacuity: the chip exists before it is removed.
  await expect(unit.tagChip(seeded.photo_id, PERSON)).toBeVisible();

  await unit.removeTag(seeded.photo_id, PERSON);
  await unit.save(seeded.photo_id);

  await expect(unit.errorBadge(seeded.photo_id)).toBeVisible();

  await unit.open(seeded.album_id);
  await expect(unit.tagChip(seeded.photo_id, PERSON)).toBeVisible();
});

test('[HAPPY] removing an ordinary tag is saved', async ({ page }) => {
  const unit = new BatchUnitPage(page);
  await unit.open(seeded.album_id);
  await expect(unit.tagChip(seeded.photo_id, PLAIN)).toBeVisible();

  await unit.removeTag(seeded.photo_id, PLAIN);
  await unit.save(seeded.photo_id);

  await expect(unit.successBadge(seeded.photo_id)).toBeVisible();
  await expect(unit.errorBadge(seeded.photo_id)).toBeHidden();
});
