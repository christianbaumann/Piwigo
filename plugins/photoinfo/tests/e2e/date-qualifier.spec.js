// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, readDate, readRow, makeReadOnly, restore } = require('./support/seed');

/**
 * The qualifier select and the range end on the picture page. Every
 * combination's text and EDTF string in detail are the unit and integration
 * suites' (DatingTest, SetDateTest); this spec covers what only the browser
 * runs: the select, the second date appearing and clearing, the refusal before
 * a request, and the save and reload.
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

const NO_END = undefined;

// The design's combination table, one row each beyond the exact dates date-edit.spec.js saves.
const SAVES = [
  { qualifier: 'circa', start: ['1965', '', ''], end: NO_END, shown: 'ca. 1965', edtf: '1965~' },
  { qualifier: 'before', start: ['1965', '', ''], end: NO_END, shown: 'vor 1965', edtf: '../1965' },
  { qualifier: 'after', start: ['1965', '3', ''], end: NO_END, shown: 'nach März 1965', edtf: '1965-03/..' },
  { qualifier: 'between', start: ['1965', '', ''], end: { year: '1970', month: '', day: '' }, shown: '1965–1970', edtf: '1965/1970' },
];

for (const save of SAVES) {
  test(`[HAPPY] an administrator saves "${save.shown}" and finds exactly that in the row after the reload`, async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(fixture.picture_path);
    await picture.dateView.click();

    await picture.fillDate(save.start[0], save.start[1], save.start[2]);
    await picture.qualifyDate(save.qualifier, save.end);
    await picture.saveDateAndWaitForReload();

    await expect(picture.dateView).toHaveText(save.shown);
    expect(readDate(fixture.image_id)['XMP-pwginfo:DateEDTF']).toBe(save.edtf);
    expect(readRow(fixture.image_id).photoinfo_date_qualifier).toBe(save.qualifier);
  });
}

test('[ST] the qualifier waits for a year, and only "zwischen" shows the second date', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.dateView.click();

  await expect(picture.dateQualifier).toBeDisabled();
  await expect(picture.dateEnd).toBeHidden();

  await picture.dateYear.fill('1965');
  await expect(picture.dateQualifier).toBeEnabled();

  for (const qualifier of ['circa', 'before', 'after', '']) {
    await picture.dateQualifier.selectOption(qualifier);
    await expect(picture.dateEnd).toBeHidden();
  }

  await picture.dateQualifier.selectOption('between');
  await expect(picture.dateEnd).toBeVisible();
  await expect(picture.dateEndMonth).toBeDisabled();
  await picture.dateEndYear.fill('1970');
  await expect(picture.dateEndMonth).toBeEnabled();

  await picture.dateYear.fill('');
  await expect(picture.dateQualifier).toBeDisabled();
  await expect(picture.dateEnd).toBeHidden();
});

test('[ST] switching away from "zwischen" clears the second date', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.dateView.click();
  await picture.fillDate('1965', '', '');
  await picture.qualifyDate('between', { year: '1970', month: '5', day: '2' });

  await picture.dateQualifier.selectOption('circa');
  await picture.dateQualifier.selectOption('between');

  await expect(picture.dateEndYear).toHaveValue('');
  await expect(picture.dateEndMonth).toHaveValue('');
  await expect(picture.dateEndDay).toHaveValue('');
});

for (const end of [{ year: '1964', month: '', day: '' }, { year: '', month: '', day: '' }]) {
  test(`[NEG] a range ending "${end.year}" is refused before anything is sent`, async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(fixture.picture_path);
    await picture.dateView.click();
    let requests = 0;
    page.on('request', (request) => {
      if (request.url().includes('ws.php')) {
        requests++;
      }
    });

    await picture.fillDate('1965', '', '');
    await picture.qualifyDate('between', end);
    await picture.dateSaveButton.click();

    await expect(picture.dateMessage).not.toBeEmpty();
    await expect(picture.dateForm).toBeVisible();
    expect(requests).toBe(0);
    expect(readRow(fixture.image_id).date_creation).toBeNull();
  });
}

test('[ST] the empty option turns a saved range back into an exact date', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.dateView.click();
  await picture.fillDate('1965', '', '');
  await picture.qualifyDate('between', { year: '1970', month: '', day: '' });
  await picture.saveDateAndWaitForReload();

  await picture.dateView.click();
  await expect(picture.dateQualifier).toHaveValue('between');
  await expect(picture.dateEndYear).toHaveValue('1970');
  await picture.dateQualifier.selectOption('');
  await picture.saveDateAndWaitForReload();

  await expect(picture.dateView).toHaveText('1965');
  const row = readRow(fixture.image_id);
  expect(row.photoinfo_date_qualifier).toBeNull();
  expect(row.photoinfo_date_end).toBeNull();
  expect(readDate(fixture.image_id)['XMP-pwginfo:DateEDTF']).toBe('1965');
});

test('[ST] a range the file does not take reopens with its qualifier and end', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  makeReadOnly(fixture.image_id);
  await picture.dateView.click();
  await picture.fillDate('1965', '', '');
  await picture.qualifyDate('between', { year: '1970', month: '5', day: '' });

  await picture.dateSaveButton.click();
  await expect(picture.dateMessage).not.toBeEmpty();
  await picture.dateCancelButton.click();
  await picture.dateView.click();

  expect(readRow(fixture.image_id).photoinfo_date_qualifier).toBe('between');
  await expect(picture.dateQualifier).toHaveValue('between');
  await expect(picture.dateEnd).toBeVisible();
  await expect(picture.dateEndYear).toHaveValue('1970');
  await expect(picture.dateEndMonth).toHaveValue('5');
});
