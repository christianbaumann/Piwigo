// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, seedHistory, restore } = require('./support/seed');

/**
 * The collapsed list of a photo's last changes. The wording of each entry is
 * provenance's unit suite; the block's presence per role is the integration
 * suite's. This spec covers what only a browser shows: closed on load, open on
 * click, and refreshed by a save without a reload.
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

const SEEDED_ENTRIES = 3;

test('[HAPPY] three entries show as a count, stay closed, and list who and when once opened', async ({ page }) => {
  seedHistory(fixture.image_id);
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);

  await expect(picture.historySummary).toContainText('Letzte Änderungen an diesem Foto');
  await expect(picture.historyCount).toHaveText(String(SEEDED_ENTRIES));
  await expect(picture.history).not.toHaveAttribute('open', '');
  await expect(picture.historyEntries.first()).toBeHidden();

  await picture.historySummary.click();

  await expect(picture.historyEntries).toHaveCount(SEEDED_ENTRIES);
  await expect(picture.historyEntries.filter({ hasText: '„Kirche“ hinzugefügt' })).toHaveCount(1);
  await expect(picture.historyEntries.first()).toContainText('heute, ');
});

test('[ST] a save on the page adds its entry to the open list without a reload', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await page.evaluate(() => { window.__notReloaded = true; });
  await picture.historySummary.click();
  await expect(picture.historyEntries).toHaveCount(0);

  await picture.infoEditLink.click();
  await picture.infoTextarea.fill('Die Taufe im Garten');
  await picture.infoDoneButton.click();

  await expect(picture.historyEntries).toHaveCount(1);
  await expect(picture.historyEntries.first()).toContainText('Info hinzugefügt');
  await expect(picture.historyCount).toHaveText('1');
  expect(await page.evaluate(() => window.__notReloaded)).toBe(true);
});
