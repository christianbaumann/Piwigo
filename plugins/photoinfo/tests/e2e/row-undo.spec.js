// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, readRow, makeReadOnly, restore } = require('./support/seed');
const { germanTextOf } = require('./support/lang');

/**
 * Rückgängig on the Datum and Info rows (task 13). What the server undoes, refuses and records is
 * UndoTest; this covers the browser half: the button after a save, the row showing the restored
 * value in place, the same for an undo started in the change list, and the red messages.
 */

const SAVED_WITH_UNDO = '✓ Gespeichert · Rückgängig';
const UNDONE = '↶ Rückgängig gemacht';
const NO_DATE = 'Noch kein Datum';
const INFO_PLACEHOLDER = 'Klicken, um eine Beschreibung hinzuzufügen';

/** @type {ReturnType<typeof seed>} */
let fixture;

test.beforeEach(() => {
  restore();
  fixture = seed('photo');
});

test.afterEach(() => {
  restore();
});

/** @param {PicturePage} picture @param {string} text */
async function saveInfo(picture, text) {
  await picture.infoEditLink.click();
  await picture.infoTextarea.fill(text);
  await picture.infoDoneButton.click();
  await expect(picture.infoStatus).toHaveText(SAVED_WITH_UNDO);
}

/** @param {PicturePage} picture @param {string} year */
async function saveYear(picture, year) {
  await picture.openDateEditor();
  await picture.fillDate(year, '', '');
  await picture.dateDoneButton.click();
  await expect(picture.dateStatus).toHaveText(SAVED_WITH_UNDO);
}

test('[HAPPY] the Info row undoes its last save in place and the list shows the undo', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.markDocument();
  await saveInfo(picture, 'Die Taufe im Garten');
  expect(readRow(fixture.image_id).comment).toBe('Die Taufe im Garten');

  await picture.infoUndo.click();

  await expect(picture.infoStatus).toHaveText(UNDONE);
  await expect(picture.infoStatus).toHaveClass(/pwg-row-status--undone/);
  await expect(picture.infoView).toHaveText(INFO_PLACEHOLDER);
  expect(readRow(fixture.image_id).comment).toBeFalsy();
  expect(await picture.documentIsMarked()).toBe(true);

  await picture.infoEditLink.click();
  await expect(picture.infoTextarea).toHaveValue('');

  await picture.historySummary.click();
  await expect(picture.historyEntries).toHaveCount(2);
  await expect(picture.page.locator('#photoinfo-history-list li.photoinfo-history-undone')).toHaveCount(1);
});

test('[HAPPY] the Datum row undoes its last save in place and the list shows the undo', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.markDocument();
  await saveYear(picture, '1965');
  expect(readRow(fixture.image_id).date_creation).not.toBeNull();

  await picture.dateUndo.click();

  await expect(picture.dateStatus).toHaveText(UNDONE);
  await expect(picture.dateView).toHaveText(NO_DATE);
  expect(readRow(fixture.image_id).date_creation).toBeNull();
  expect(await picture.documentIsMarked()).toBe(true);

  await picture.dateEditButton.click();
  await expect(picture.dateYear).toHaveValue('');
  await picture.dateYear.fill('1970');
  await picture.dateDoneButton.click();
  await expect(picture.dateView).toHaveText('1970');

  await picture.historySummary.click();
  await expect(picture.historyEntries).toHaveCount(3);
});

test('[ST] an undo started in the change list shows on its rows without a reload', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.markDocument();
  await saveYear(picture, '1965');
  await saveInfo(picture, 'Die Taufe im Garten');
  await picture.historySummary.click();
  await expect(picture.historyEntries).toHaveCount(2);

  await picture.historyEntries.filter({ hasText: 'Info hinzugefügt' }).locator('.photoinfo-undo').click();
  await expect(picture.infoStatus).toHaveText(UNDONE);
  await expect(picture.infoView).toHaveText(INFO_PLACEHOLDER);
  await expect(picture.dateStatus).toHaveText(SAVED_WITH_UNDO);

  await picture.historyEntries.filter({ hasText: 'Datum gesetzt' }).locator('.photoinfo-undo').click();
  await expect(picture.dateStatus).toHaveText(UNDONE);
  await expect(picture.dateView).toHaveText(NO_DATE);
  await expect(picture.historyEntries).toHaveCount(4);
  expect(await picture.documentIsMarked()).toBe(true);
});

test('[NEG] an undo the value moved past is refused in red on the Info row', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await saveInfo(picture, 'Die Taufe im Garten');
  // another entry sits between, so the next save does not merge into the first one
  await picture.callWs({ method: 'pwg.photoinfo.setDate', image_id: String(fixture.image_id),
    qualifier: '', year: '1960', month: '', day: '', end_year: '', end_month: '', end_day: '' });
  await picture.callWs({ method: 'pwg.photoinfo.setInfo', image_id: String(fixture.image_id), info: 'Anderer Text' });

  await picture.infoUndo.click();

  await expect(picture.infoStatus).toContainText('Wurde inzwischen von');
  await expect(picture.infoStatus).toHaveClass(/pwg-row-status--error/);
  expect(readRow(fixture.image_id).comment).toBe('Anderer Text');
});

test('[NEG] an undo the value moved past is refused in red on the Datum row', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await saveYear(picture, '1965');
  await picture.callWs({ method: 'pwg.photoinfo.setInfo', image_id: String(fixture.image_id), info: 'Dazwischen' });
  await picture.callWs({ method: 'pwg.photoinfo.setDate', image_id: String(fixture.image_id),
    qualifier: '', year: '1970', month: '', day: '', end_year: '', end_month: '', end_day: '' });

  await picture.dateUndo.click();

  await expect(picture.dateStatus).toContainText('Wurde inzwischen von');
  await expect(picture.dateStatus).toHaveClass(/pwg-row-status--error/);
});

test('[ERR] an undo the file does not take is restored in the database and red on the row and the list', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await saveInfo(picture, 'Die Taufe im Garten');
  await picture.historySummary.click();
  makeReadOnly(fixture.image_id);

  await picture.infoUndo.click();

  await expect(picture.infoStatus).toContainText('Gespeichert, aber nicht in die Bilddatei geschrieben:');
  await expect(picture.infoStatus).toContainText(germanTextOf('PHOTOINFO_FILE_NOT_WRITABLE_MESSAGE'));
  await expect(picture.infoStatus).toHaveClass(/pwg-row-status--error/);
  await expect(picture.historyWarning).toContainText('nicht in die Bilddatei geschrieben');
  expect(readRow(fixture.image_id).comment).toBeFalsy();
});
