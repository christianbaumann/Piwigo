// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { statePath } = require('./support/accounts');
const { seed, readFile, readRow, makeReadOnly, restore } = require('./support/seed');
const { germanTextOf } = require('./support/lang');

/**
 * Editing the info text on the picture page. What the file holds in detail is
 * the integration suite's (SetInfoTest); this spec covers the editor: the
 * link that opens it, the save on leaving the field and the row status.
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

const STATUS_DIRTY = 'noch nicht gespeichert';
const STATUS_SAVED = '✓ Gespeichert';
const SETINFO = 'pwg.photoinfo.setInfo';

/** @param {import('@playwright/test').Page} page */
function setInfoRequests(page) {
  const requests = [];
  page.on('request', (r) => {
    if (r.url().includes('ws.php') && (r.postData() || '').includes(SETINFO)) {
      requests.push(r);
    }
  });
  return requests;
}

/**
 * Whether a leave warning is armed: a beforeunload event that something
 * cancelled. Dispatching it by hand needs no user gesture, which Chromium
 * demands before it shows the real dialog.
 *
 * @param {import('@playwright/test').Page} page
 */
function leaveWarningArmed(page) {
  return page.evaluate(() => {
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);
    return event.defaultPrevented;
  });
}

test('[HAPPY] an administrator opens the row, types, leaves the field, and finds the text in the row without a reload', async ({ page }) => {
  const picture = new PicturePage(page);
  const requests = setInfoRequests(page);
  await picture.goto(fixture.picture_path);
  await page.evaluate(() => { window.__notReloaded = true; });

  await expect(picture.infoForm).toBeHidden();
  await expect(picture.infoEditLink).toHaveText('✎ ändern');
  await picture.infoEditLink.click();
  await expect(picture.infoTextarea).toBeVisible();
  await expect(picture.infoTextarea).toBeFocused();

  await picture.infoTextarea.pressSequentially('Hochzeit im Garten', { delay: 20 });
  await picture.infoTextarea.press('Enter');
  await picture.infoTextarea.pressSequentially('mit Oma', { delay: 20 });
  await expect(picture.infoStatus).toHaveText(STATUS_DIRTY);
  await expect(picture.infoStatus).toHaveClass(/pwg-row-status--dirty/);
  expect(requests, 'nothing is sent while typing').toHaveLength(0);

  await picture.infoDoneButton.focus();
  await expect(picture.infoStatus).toHaveText(STATUS_SAVED);
  await expect(picture.infoStatus).toHaveClass(/pwg-row-status--saved/);
  expect(requests, 'leaving the field saves once').toHaveLength(1);
  await expect(picture.infoView).toContainText('Hochzeit im Garten');
  await expect(picture.infoView).toContainText('mit Oma');
  await expect(picture.coreDescription).toHaveCount(0);
  expect(await page.evaluate(() => window.__notReloaded), 'the page was not reloaded').toBe(true);
  expect(readRow(fixture.image_id).comment).toBe('Hochzeit im Garten\nmit Oma');
  expect(readFile(fixture.image_id)['XMP-pwginfo:Info']).toBe('Hochzeit im Garten\nmit Oma');
});

test('[ST] Fertig after leaving the field sends no second request, and closes the editor', async ({ page }) => {
  const picture = new PicturePage(page);
  const requests = setInfoRequests(page);
  await picture.goto(fixture.picture_path);

  await picture.infoView.click();
  await picture.infoTextarea.fill('Einmal gespeichert');
  await picture.infoDoneButton.click();

  await expect(picture.infoForm).toBeHidden();
  await expect(picture.infoView).toContainText('Einmal gespeichert');
  await expect(picture.infoStatus).toHaveText(STATUS_SAVED);
  expect(requests).toHaveLength(1);
});

test('[ST] an unchanged text sends nothing, and Fertig closes the editor', async ({ page }) => {
  const picture = new PicturePage(page);
  const requests = setInfoRequests(page);
  await picture.goto(fixture.picture_path);

  await picture.infoEditLink.click();
  await picture.infoDoneButton.click();

  await expect(picture.infoForm).toBeHidden();
  await expect(picture.infoStatus).toHaveText('');
  expect(requests).toHaveLength(0);
});

test('[ST] the leave warning is armed only while the text is unsaved', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);

  expect(await leaveWarningArmed(page), 'armed before any change').toBe(false);
  await picture.infoView.click();
  await picture.infoTextarea.pressSequentially('halb', { delay: 20 });
  expect(await leaveWarningArmed(page), 'armed while dirty').toBe(true);

  await picture.infoDoneButton.click();
  await expect(picture.infoStatus).toHaveText(STATUS_SAVED);
  expect(await leaveWarningArmed(page), 'disarmed after the save').toBe(false);
});

test('[ST] emptying the text saves it and shows the placeholder', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.infoView.click();
  await picture.infoTextarea.fill('vorher');
  await picture.infoDoneButton.click();
  await expect(picture.infoStatus).toHaveText(STATUS_SAVED);

  await picture.infoEditLink.click();
  await picture.infoTextarea.fill('');
  await picture.infoDoneButton.click();

  await expect(picture.infoView.locator('.photoinfo-placeholder')).toBeVisible();
  expect(readRow(fixture.image_id).comment).toBeNull();
});

test('[NEG] a text the file does not take is saved, and the row shows the reason in red', async ({ page }) => {
  makeReadOnly(fixture.image_id);
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);

  await picture.infoView.click();
  await picture.infoTextarea.fill('Nicht in der Datei');
  await picture.infoDoneButton.click();

  await expect(picture.infoStatus).toContainText(germanTextOf('PHOTOINFO_FILE_NOT_WRITABLE_MESSAGE'));
  await expect(picture.infoStatus).toHaveClass(/pwg-row-status--error/);
  expect(readRow(fixture.image_id).comment).toBe('Nicht in der Datei');
});

test('[NEG] a refused save shows "Nicht gespeichert" and keeps the editor open', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await page.route('**/ws.php*', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ stat: 'fail', err: 1002, message: 'Ungültig' }),
  }));

  await picture.infoView.click();
  await picture.infoTextarea.fill('abgelehnt');
  await picture.infoDoneButton.click();

  await expect(picture.infoStatus).toHaveText('Nicht gespeichert: Ungültig');
  await expect(picture.infoStatus).toHaveClass(/pwg-row-status--error/);
  await expect(picture.infoForm).toBeVisible();
  expect(readRow(fixture.image_id).comment).toBeNull();
});

test.describe('as a normal user', () => {
  test.use({ storageState: statePath('NORMAL') });

  test('[HAPPY] a normal user edits the text, and the file holds it', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(fixture.picture_path);

    await picture.infoView.click();
    await picture.infoTextarea.fill('Vom Benutzer');
    await picture.infoDoneButton.click();
    await expect(picture.infoStatus).toHaveText(STATUS_SAVED);

    await expect(picture.infoView).toContainText('Vom Benutzer');
    expect(readRow(fixture.image_id).comment).toBe('Vom Benutzer');
    expect(readFile(fixture.image_id)['XMP-pwginfo:Info']).toBe('Vom Benutzer');
  });
});
