// @ts-check
const { test, expect } = require('@playwright/test');
const { seed, restore } = require('./support/seed');
const { germanTextOf } = require('./support/lang');
const { AdminTagsPage } = require('./support/AdminTagsPage');

/**
 * Schlagwortverwaltung refuses to delete a person's tag and says why, through
 * tags.js's error line; an ordinary tag is still deleted. The refusal itself is
 * TagToolGuardTest's; this spec witnesses what the screen's JavaScript makes of
 * it. Runs as the webmaster (chromium-admin).
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

test('[NEG] deleting a person tag is refused with the message pointing to Personen', async ({ page }) => {
  const tags = new AdminTagsPage(page);
  const tagId = seeded.person_tag_ids[PERSON];
  await tags.open();

  await tags.deleteTag(tagId, PERSON);

  await expect(tags.error).toHaveText(germanTextOf('PERSONS_TAG_DELETE_REFUSED_MESSAGE').replace('%s', PERSON));
  await expect(tags.box(tagId)).toBeVisible();

  await tags.open();
  await tags.search.fill(PERSON);
  await expect(tags.box(tagId)).toBeVisible();
});

test('[HAPPY] an ordinary tag is still deleted', async ({ page }) => {
  const tags = new AdminTagsPage(page);
  await tags.open();

  await tags.deleteTag(seeded.plain_tag_id, PLAIN);

  await expect(tags.box(seeded.plain_tag_id)).toHaveCount(0);
  await expect(tags.error).toBeHidden();
});
