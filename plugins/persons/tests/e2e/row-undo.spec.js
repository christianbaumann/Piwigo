// @ts-check
const { test, expect } = require('@playwright/test');
const { seed, restore, readFileRegions } = require('./support/seed');
const { PicturePage } = require('./support/PicturePage');

/**
 * Rückgängig on the Personen row (task 13). What persons and photoinfo undo and refuse on the server
 * is UndoRegionTest; this covers the browser half: the button after a save, the names and boxes
 * coming back in place (a deleted box at the place it was drawn), an undo started in the change list,
 * the red refusal, and no button when the answer carries no history entry.
 */
const SAVED_WITH_UNDO = '✓ Gespeichert · Rückgängig';
const SAVED = '✓ Gespeichert';
const UNDONE = '↶ Rückgängig gemacht';
const ADA = 'E2E Editor Ada';
const BOX = { left: 0.25, top: 0.3, w: 0.2, h: 0.25 };
/** How far a restored box may sit from the drawn one, in CSS pixels. */
const BOX_TOLERANCE_PX = 2;

async function tag(picture, box, name, expectedBoxes) {
  await picture.enterTaggingMode();
  await picture.dragBox(box);
  await picture.typeName(name);
  await picture.pickerInput.press('Enter');
  await expect(picture.savedBoxes).toHaveCount(expectedBoxes);
}

async function openEmpty(page) {
  const seeded = seed('empty');
  const picture = new PicturePage(page);
  await picture.goto(seeded.picture_path);
  await picture.waitForPlacement();
  await picture.markDocument();
  return { picture, seeded };
}

test.describe('Rückgängig on the Personen row', () => {
  test.afterEach(() => {
    restore();
  });

  test('[HAPPY] a marked person is undone in place: box and name go, the file loses the region', async ({ page }) => {
    const { picture, seeded } = await openEmpty(page);
    await tag(picture, BOX, ADA, 1);
    await expect(picture.rowStatus).toHaveText(SAVED_WITH_UNDO);
    expect(readFileRegions(seeded.photo_id).regions).toHaveLength(1);

    await picture.rowUndo.click();

    await expect(picture.rowStatus).toHaveText(UNDONE);
    await expect(picture.rowStatus).toHaveClass(/pwg-row-status--undone/);
    await expect(picture.savedBoxes).toHaveCount(0);
    await expect(picture.personRowEntries).toHaveCount(0);
    expect(readFileRegions(seeded.photo_id).regions).toHaveLength(0);
    expect(await picture.sameDocument()).toBe(true);

    await picture.historySummary.click();
    await expect(picture.historyEntries).toHaveCount(2);
    await expect(page.locator('#photoinfo-history-list li.photoinfo-history-undone')).toHaveCount(1);
  });

  test('[HAPPY] a deleted mark is undone in place: the name and the box are back where they were', async ({ page }) => {
    const { picture, seeded } = await openEmpty(page);
    await tag(picture, BOX, ADA, 1);
    const [regionId] = await picture.savedRegionIds();
    const drawn = await picture.boxes.first().boundingBox();
    await picture.deleteButtonOf(ADA).click();
    await expect(picture.savedBoxes).toHaveCount(0);
    await expect(picture.rowStatus).toHaveText(SAVED_WITH_UNDO);

    await picture.rowUndo.click();

    await expect(picture.rowStatus).toHaveText(UNDONE);
    await expect(picture.personRowEntries).toHaveCount(1);
    await expect(picture.savedBoxes).toHaveCount(1);
    expect(await picture.savedRegionIds()).not.toEqual([]);
    expect(regionId).toBeTruthy();
    const restored = await picture.boxes.first().boundingBox();
    expect(Math.abs(restored.x - drawn.x)).toBeLessThanOrEqual(BOX_TOLERANCE_PX);
    expect(Math.abs(restored.y - drawn.y)).toBeLessThanOrEqual(BOX_TOLERANCE_PX);
    expect(Math.abs(restored.width - drawn.width)).toBeLessThanOrEqual(BOX_TOLERANCE_PX);
    expect(Math.abs(restored.height - drawn.height)).toBeLessThanOrEqual(BOX_TOLERANCE_PX);
    expect(readFileRegions(seeded.photo_id).regions).toHaveLength(1);
  });

  test('[ST] an undo started in the change list updates the row and the boxes', async ({ page }) => {
    const { picture } = await openEmpty(page);
    await tag(picture, BOX, ADA, 1);
    await picture.historySummary.click();
    await expect(picture.historyEntries).toHaveCount(1);

    await picture.historyEntries.first().locator('.photoinfo-undo').click();

    await expect(picture.savedBoxes).toHaveCount(0);
    await expect(picture.personRowEntries).toHaveCount(0);
    await expect(picture.rowStatus).toHaveText(UNDONE);
    await expect(picture.historyEntries).toHaveCount(2);
  });

  test('[NEG] an undo the mark moved past is refused in red on the row', async ({ page }) => {
    const { picture } = await openEmpty(page);
    await tag(picture, BOX, ADA, 1);
    const [regionId] = await picture.savedRegionIds();
    await expect(picture.rowStatus).toHaveText(SAVED_WITH_UNDO);
    await picture.callWs({ method: 'pwg.persons.deleteRegion', region_id: String(regionId) });

    await picture.rowUndo.click();

    await expect(picture.rowStatus).toContainText('Wurde inzwischen von');
    await expect(picture.rowStatus).toHaveClass(/pwg-row-status--error/);
  });

  test('[NEG] an answer without a history entry shows "Gespeichert" and no Rückgängig', async ({ page }) => {
    await page.route('**/ws.php*', async (route) => {
      if (!route.request().url().includes('pwg.persons.addRegion')) {
        await route.continue();
        return;
      }
      // what photoinfo not being active answers: the same result without its field
      const response = await route.fetch();
      const json = await response.json();
      delete json.result.history_id;
      await route.fulfill({ response, json });
    });
    const { picture } = await openEmpty(page);

    await tag(picture, BOX, ADA, 1);

    await expect(picture.rowStatus).toHaveText(SAVED);
    await expect(picture.rowUndo).toHaveCount(0);
  });
});
