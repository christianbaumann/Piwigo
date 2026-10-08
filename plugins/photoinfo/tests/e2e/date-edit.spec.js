// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { statePath } = require('./support/accounts');
const { seed, readDate, readRow, makeReadOnly, restore } = require('./support/seed');

/**
 * Editing the date on the picture page. Validation and the file's tags in
 * detail are the unit and integration suites' (DateTest, SetDateTest); this
 * spec covers what only the browser runs: the dependent controls, the refusal
 * before a request, and the save and reload.
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

const SAVES = [
  { year: '1965', month: '', day: '', shown: '1965', edtf: '1965' },
  { year: '1965', month: '3', day: '', shown: 'März 1965', edtf: '1965-03' },
  { year: '1965', month: '3', day: '14', shown: '14. März 1965', edtf: '1965-03-14' },
];

for (const save of SAVES) {
  test(`[HAPPY] an administrator saves "${save.shown}" and finds exactly that in the row after the reload`, async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(fixture.picture_path);
    await expect(picture.coreDateRow).toHaveCount(0);

    await expect(picture.dateForm).toBeHidden();
    await picture.dateView.click();
    await expect(picture.dateYear).toBeFocused();

    await picture.fillDate(save.year, save.month, save.day);
    await picture.saveDateAndWaitForReload();

    await expect(picture.dateView).toHaveText(save.shown);
    await expect(picture.coreDateRow).toHaveCount(0);
    expect(readDate(fixture.image_id)['XMP-pwginfo:DateEDTF']).toBe(save.edtf);
  });
}

test('[ST] the month waits for a year and the day for a month', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.dateView.click();

  await expect(picture.dateMonth).toBeDisabled();
  await expect(picture.dateDay).toBeDisabled();

  await picture.dateYear.fill('1965');
  await expect(picture.dateMonth).toBeEnabled();
  await expect(picture.dateDay).toBeDisabled();

  await picture.dateMonth.selectOption('4');
  await expect(picture.dateDay).toBeEnabled();

  await picture.dateYear.fill('');
  await expect(picture.dateMonth).toBeDisabled();
  await expect(picture.dateDay).toBeDisabled();
});

const MONTH_LENGTHS = [
  { year: '1964', month: '2', days: 29 },
  { year: '1965', month: '2', days: 28 },
  { year: '1965', month: '4', days: 30 },
  { year: '1965', month: '3', days: 31 },
];

for (const length of MONTH_LENGTHS) {
  test(`[BVA] month ${length.month} of ${length.year} offers ${length.days} days`, async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(fixture.picture_path);
    await picture.dateView.click();

    await picture.fillDate(length.year, length.month, '');

    await expect(picture.dateDayOptions).toHaveCount(length.days);
  });
}

test('[ST] changing February 1964 to 1965 drops the 29th', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.dateView.click();
  await picture.fillDate('1964', '2', '29');

  await picture.dateYear.fill('1965');

  await expect(picture.dateDay).toHaveValue('');
});

for (const year of ['1799', String(new Date().getFullYear() + 1)]) {
  test(`[NEG] the year ${year} is refused before anything is sent`, async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(fixture.picture_path);
    await picture.dateView.click();
    let requests = 0;
    page.on('request', (request) => {
      if (request.url().includes('ws.php')) {
        requests++;
      }
    });

    await picture.dateYear.fill(year);
    await picture.dateSaveButton.click();

    await expect(picture.dateMessage).not.toBeEmpty();
    await expect(picture.dateForm).toBeVisible();
    expect(requests).toBe(0);
    expect(readRow(fixture.image_id).date_creation).toBeNull();
  });
}

test('[ST] emptying the year clears a saved date', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.dateView.click();
  await picture.fillDate('1965', '3', '14');
  await picture.saveDateAndWaitForReload();

  await picture.dateView.click();
  await picture.dateYear.fill('');
  await picture.saveDateAndWaitForReload();

  expect(readRow(fixture.image_id).date_creation).toBeNull();
  expect(readDate(fixture.image_id)['XMP-photoshop:DateCreated']).toBeNull();
  await expect(picture.datePlaceholder).toBeVisible();
  await expect(picture.dateView).not.toContainText('1965');
});

test('[NEG] a clear the file does not take keeps the row clickable and says why', async ({ page }) => {
  // Requirement: the task's "reports a failed write without losing the saved text", for the date.
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.dateView.click();
  await picture.fillDate('1965', '', '');
  await picture.saveDateAndWaitForReload();
  makeReadOnly(fixture.image_id);

  await picture.dateView.click();
  await picture.dateYear.fill('');
  await picture.dateSaveButton.click();

  await expect(picture.dateMessage).not.toBeEmpty();
  expect(readRow(fixture.image_id).date_creation).toBeNull();
  await picture.dateCancelButton.click();
  await expect(picture.datePlaceholder).toBeVisible();
});

test.describe('as a normal user', () => {
  test.use({ storageState: statePath('NORMAL') });

  test('[NEG] the row is read-only', async ({ page }) => {
    const admin = await page.context().browser().newContext({ storageState: statePath('WEBMASTER') });
    const adminPicture = new PicturePage(await admin.newPage());
    await adminPicture.goto(fixture.picture_path);
    await adminPicture.dateView.click();
    await adminPicture.fillDate('1965', '3', '');
    await adminPicture.saveDateAndWaitForReload();
    await admin.close();

    const picture = new PicturePage(page);
    await picture.goto(fixture.picture_path);

    await expect(picture.dateText).toHaveText('März 1965');
    await expect(picture.dateView).toHaveCount(0);
    await expect(picture.dateForm).toHaveCount(0);
  });
});
