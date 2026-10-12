// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { statePath } = require('./support/accounts');
const { seed, readFile, readRow, makeReadOnly, restore } = require('./support/seed');
const { germanTextOf } = require('./support/lang');

/**
 * Editing the info text on the picture page. What the file holds in detail is
 * the integration suite's (SetInfoTest); this spec covers the editor: the
 * click that opens it, the save it sends and the reload that shows the text.
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

test('[HAPPY] an administrator clicks the row, saves, and finds the text in the row after the reload', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);

  await expect(picture.infoForm).toBeHidden();
  await picture.infoView.click();
  await expect(picture.infoTextarea).toBeVisible();
  await expect(picture.infoTextarea).toBeFocused();

  await picture.infoTextarea.fill('Hochzeit im Garten\nmit Oma');
  await picture.saveAndWaitForReload();

  await expect(picture.infoView).toContainText('Hochzeit im Garten');
  await expect(picture.infoView).toContainText('mit Oma');
  await expect(picture.coreDescription).toHaveCount(0);
  expect(readRow(fixture.image_id).comment).toBe('Hochzeit im Garten\nmit Oma');
  expect(readFile(fixture.image_id)['XMP-pwginfo:Info']).toBe('Hochzeit im Garten\nmit Oma');
});

test('[ST] the keyboard opens the editor, and Cancel closes it without saving', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);

  await picture.infoView.focus();
  await page.keyboard.press('Enter');
  await expect(picture.infoTextarea).toBeVisible();

  await picture.infoTextarea.fill('verworfen');
  await picture.cancelButton.click();

  await expect(picture.infoForm).toBeHidden();
  await expect(picture.infoView).toBeVisible();
  await expect(picture.infoView).not.toContainText('verworfen');
  expect(readRow(fixture.image_id).comment).toBeNull();
});

test('[NEG] a text the file does not take is saved, and the page gives the reason in German', async ({ page }) => {
  makeReadOnly(fixture.image_id);
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);

  await picture.infoView.click();
  await picture.infoTextarea.fill('Nicht in der Datei');
  await picture.saveButton.click();

  await expect(picture.infoMessage).toContainText(germanTextOf('PHOTOINFO_FILE_NOT_WRITABLE_MESSAGE'));
  expect(readRow(fixture.image_id).comment).toBe('Nicht in der Datei');
});

test.describe('as a normal user', () => {
  test.use({ storageState: statePath('NORMAL') });

  test('[HAPPY] a normal user edits the text, and the file holds it', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(fixture.picture_path);

    await picture.infoView.click();
    await picture.infoTextarea.fill('Vom Benutzer');
    await picture.saveAndWaitForReload();

    await expect(picture.infoView).toContainText('Vom Benutzer');
    expect(readRow(fixture.image_id).comment).toBe('Vom Benutzer');
    expect(readFile(fixture.image_id)['XMP-pwginfo:Info']).toBe('Vom Benutzer');
  });
});
