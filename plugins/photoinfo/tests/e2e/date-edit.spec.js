// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { statePath } = require('./support/accounts');
const { seed, readDate, readRow, makeReadOnly, restore } = require('./support/seed');
const { germanTextOf } = require('./support/lang');

const SAVED = '✓ Gespeichert';

/**
 * Editing the date on the picture page. Validation and the file's tags in
 * detail are the unit and integration suites' (DateTest, SetDateTest); this
 * spec covers what only the browser runs: the dependent controls, the refusal
 * before a request, and the save on Done or on leaving the group, in place.
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
  test(`[HAPPY] an administrator saves "${save.shown}" and finds exactly that in the row, without a reload`, async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(fixture.picture_path);
    await expect(picture.coreDateRow).toHaveCount(0);

    await expect(picture.dateForm).toBeHidden();
    await picture.dateView.click();
    await expect(picture.dateForm).toBeHidden();
    await picture.dateEditButton.click();
    await expect(picture.dateYear).toBeFocused();

    await picture.markDocument();
    await picture.fillDate(save.year, save.month, save.day);
    await expect(picture.dateStatus).toHaveText('noch nicht gespeichert');
    await picture.dateDoneButton.click();

    await expect(picture.dateStatus).toHaveText(SAVED);
    await expect(picture.dateForm).toBeHidden();
    expect(await picture.documentIsMarked()).toBe(true);

    await expect(picture.dateView).toHaveText(save.shown);
    await expect(picture.coreDateRow).toHaveCount(0);
    expect(readDate(fixture.image_id)['XMP-pwginfo:DateEDTF']).toBe(save.edtf);
  });
}

test('[ST] leaving the date group saves once; moving inside it, or an unchanged value, saves nothing', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  const saves = [];
  page.on('request', (request) => {
    if (request.url().includes('ws.php') && (request.postData() || '').includes('pwg.photoinfo.setDate')) {
      saves.push(request);
    }
  });
  await picture.openDateEditor();
  await picture.markDocument();

  await picture.fillDate('1965', '3', '');
  await picture.dateDay.focus();
  await page.waitForTimeout(300);
  expect(saves.length).toBe(0);

  // The next stop is Done, still inside the group; the one after leaves it.
  await picture.dateDay.press('Tab');
  await expect(picture.dateDoneButton).toBeFocused();
  await page.waitForTimeout(300);
  expect(saves.length).toBe(0);
  await picture.dateDoneButton.press('Tab');
  await expect(picture.dateStatus).toHaveText(SAVED);
  await expect(picture.dateForm).toBeHidden();
  await expect(picture.dateView).toHaveText('März 1965');
  expect(saves.length).toBe(1);
  expect(await picture.documentIsMarked()).toBe(true);

  await picture.openDateEditor();
  await picture.dateDoneButton.click();
  await expect(picture.dateForm).toBeHidden();
  expect(saves.length).toBe(1);
  await expect(picture.dateStatus).toHaveText(SAVED);
});

test('[ST] the month waits for a year and the day for a month', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.openDateEditor();

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
    await picture.openDateEditor();

    await picture.fillDate(length.year, length.month, '');

    await expect(picture.dateDayOptions).toHaveCount(length.days);
  });
}

test('[ST] changing February 1964 to 1965 drops the 29th', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.openDateEditor();
  await picture.fillDate('1964', '2', '29');

  await picture.dateYear.fill('1965');

  await expect(picture.dateDay).toHaveValue('');
});

for (const year of ['1799', String(new Date().getFullYear() + 1)]) {
  test(`[NEG] the year ${year} is refused before anything is sent`, async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(fixture.picture_path);
    await picture.openDateEditor();
    let requests = 0;
    page.on('request', (request) => {
      if (request.url().includes('ws.php')) {
        requests++;
      }
    });

    await picture.dateYear.fill(year);
    await picture.dateDoneButton.click();

    await expect(picture.dateMessage).not.toBeEmpty();
    await expect(picture.dateForm).toBeVisible();
    expect(requests).toBe(0);
    expect(readRow(fixture.image_id).date_creation).toBeNull();
  });
}

test('[ST] emptying the year clears a saved date', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.openDateEditor();
  await picture.fillDate('1965', '3', '14');
  await picture.dateDoneButton.click();
  await expect(picture.dateStatus).toHaveText(SAVED);

  await picture.openDateEditor();
  await picture.dateYear.fill('');
  await picture.dateDoneButton.click();
  await expect(picture.datePlaceholder).toBeVisible();

  expect(readRow(fixture.image_id).date_creation).toBeNull();
  expect(readDate(fixture.image_id)['XMP-photoshop:DateCreated']).toBeNull();
  await expect(picture.datePlaceholder).toBeVisible();
  await expect(picture.dateView).not.toContainText('1965');
});

test('[NEG] a clear the file does not take stays red on the row and says why', async ({ page }) => {
  // Requirement: the task's "reports a failed write without losing the saved text", for the date.
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.openDateEditor();
  await picture.fillDate('1965', '', '');
  await picture.dateDoneButton.click();
  await expect(picture.dateStatus).toHaveText(SAVED);
  makeReadOnly(fixture.image_id);

  await picture.openDateEditor();
  await picture.dateYear.fill('');
  await picture.dateDoneButton.click();

  await expect(picture.dateStatus).toContainText('Nicht gespeichert:');
  expect(readRow(fixture.image_id).date_creation).toBeNull();
  await picture.dateDoneButton.click();
  await expect(picture.datePlaceholder).toBeVisible();
  await expect(picture.dateStatus).toContainText('Nicht gespeichert:');
});

test('[NEG] a date the file does not take is saved, and the page gives the reason in German', async ({ page }) => {
  makeReadOnly(fixture.image_id);
  // Anti-vacuity: the row starts without a date, so a date found afterwards was saved here.
  expect(readRow(fixture.image_id).date_creation).toBeNull();
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);

  await picture.openDateEditor();
  await picture.fillDate('1965', '', '');
  await picture.dateDoneButton.click();

  await expect(picture.dateStatus).toContainText(germanTextOf('PHOTOINFO_FILE_NOT_WRITABLE_MESSAGE'));
  await expect(picture.dateStatus).toHaveClass(/pwg-row-status--error/);
  await expect(picture.dateStatus).toContainText('Nicht gespeichert:');
  expect(readRow(fixture.image_id).date_creation).not.toBeNull();
});

test.describe('as a normal user', () => {
  test.use({ storageState: statePath('NORMAL') });

  test('[HAPPY] a normal user edits the date, and the file holds it', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(fixture.picture_path);

    await picture.openDateEditor();
    await picture.fillDate('1965', '3', '');
    await picture.dateDoneButton.click();
    await expect(picture.dateStatus).toHaveText(SAVED);

    await expect(picture.dateView).toHaveText('März 1965');
    expect(readRow(fixture.image_id).date_creation).toBe('1965-03-01 00:00:00');
    expect(readDate(fixture.image_id)['XMP-pwginfo:DateEDTF']).toBe('1965-03');
  });
});
