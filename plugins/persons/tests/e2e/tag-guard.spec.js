// @ts-check
const { test, expect } = require('@playwright/test');
const { seed, restore } = require('./support/seed');
const { PicturePage } = require('./support/PicturePage');

/**
 * typetags' x is not offered on the coloured tag of a person marked on the
 * photo; an ordinary coloured tag beside it keeps its x. The server side of it
 * (typetags_fixed_tag_ids) is TagToolGuardTest's; the x is built by JavaScript,
 * so only a browser sees it. Runs as the normal account.
 */

const PERSON = 'E2E Overlay Jane';

/** @type {ReturnType<typeof seed>} */
let seeded;

test.beforeEach(() => {
  seeded = seed('overlay', { colouredTags: true });
});

test.afterEach(() => {
  restore();
});

test('[NEG] a marked person\'s tag has no x, an ordinary coloured tag has one', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(seeded.picture_path);

  const personTag = seeded.person_tag_ids[PERSON];
  await expect(picture.tagLink(personTag)).toBeVisible();
  // Anti-vacuity: typetags put its x on the photo's other coloured tag.
  await expect(picture.tagRemoveButton(seeded.plain_tag_id)).toBeVisible();
  await expect(picture.tagRemoveButton(personTag)).toHaveCount(0);
});
