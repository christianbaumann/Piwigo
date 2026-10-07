// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { statePath } = require('./support/accounts');
const { seed, readFile, readRow, restore } = require('./support/seed');

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

test.describe('as a normal user', () => {
  test.use({ storageState: statePath('NORMAL') });

  test('[NEG] the row is read-only', async ({ page }) => {
    // A saved text as the administrator, so the row is there to look at.
    const admin = await page.context().browser().newContext({ storageState: statePath('WEBMASTER') });
    const adminPicture = new PicturePage(await admin.newPage());
    await adminPicture.goto(fixture.picture_path);
    await adminPicture.infoView.click();
    await adminPicture.infoTextarea.fill('Nur lesen');
    await adminPicture.saveAndWaitForReload();
    await admin.close();

    const picture = new PicturePage(page);
    await picture.goto(fixture.picture_path);

    await expect(picture.infoText).toContainText('Nur lesen');
    await expect(picture.infoView).toHaveCount(0);
    await expect(picture.infoForm).toHaveCount(0);
  });
});
