// @ts-check
const { test, expect } = require('@playwright/test');
const { UploadPage } = require('./support/UploadPage');
const { seedEmpty, trackUploads, restore } = require('./support/seed');

/** A 1x1 PNG; a suffix makes the bytes of each file its own, so duplicate detection never links them. */
const PNG_1X1 = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64'
);

/** @param {string} name */
function png(name) {
  return { name, mimeType: 'image/png', buffer: Buffer.concat([PNG_1X1, Buffer.from(name + Date.now())]) };
}

let seeded;

test.beforeEach(() => {
  restore();
  seeded = seedEmpty();
});

test.afterEach(() => {
  trackUploads(seeded.album_id);
  restore();
});

// [ECP] A file type the page refuses is reported and does not hide the valid files around it.
test('a disallowed file in the selection leaves the valid files queued once', async ({ page }) => {
  const upload = new UploadPage(page);
  await upload.goto(seeded.album_id);

  await upload.addFiles([
    png('galleryrules-up-a.png'),
    { name: 'galleryrules-up-notes.txt', mimeType: 'text/plain', buffer: Buffer.from('keine Bilddatei') },
    png('galleryrules-up-b.png'),
  ]);

  await expect(upload.queueRows).toHaveCount(2);
  await expect(upload.errors).toHaveCount(1);
  await expect(upload.errors).toContainText('galleryrules-up-notes.txt');

  await upload.startButton.click();
  await expect(upload.uploadedThumbnails).toHaveCount(2, { timeout: 15_000 });
});

// [NEG] A file the server refuses is reported with the server's text and is not counted as uploaded.
test('a fake jpg is reported as failed and not counted', async ({ page }) => {
  const upload = new UploadPage(page);
  await upload.goto(seeded.album_id);

  await upload.upload({ name: 'galleryrules-up-fake.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('keine Bilddatei') });

  await expect(upload.errors).toHaveCount(1, { timeout: 15_000 });
  await expect(upload.errors).toContainText('galleryrules-up-fake.jpg');
  await expect(upload.uploadedThumbnails).toHaveCount(0);
  await expect(upload.summary).toBeHidden();
});

// [NEG] A refused uploadCompleted is reported, not announced as success.
test('a failed uploadCompleted is reported and no success line shows', async ({ page }) => {
  await page.route('**/ws.php?*method=pwg.images.uploadCompleted*', (route) =>
    route.fulfill({ json: { stat: 'fail', err: 500, message: 'galleryrules-completed-refused' } })
  );
  const upload = new UploadPage(page);
  await upload.goto(seeded.album_id);

  await upload.upload(png('galleryrules-up-c.png'));

  await expect(upload.errors).toContainText('galleryrules-completed-refused', { timeout: 15_000 });
  await expect(upload.summary).toBeHidden();
});

// [ST] Abbrechen before the first file is done leaves a page that can upload again.
test('Abbrechen before the first file finishes leaves a usable page', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));
  /** @type {import('@playwright/test').Route[]} */
  const held = [];
  await page.route('**/ws.php?*method=pwg.images.upload&*', (route) => held.push(route));
  const upload = new UploadPage(page);
  await upload.goto(seeded.album_id);
  await upload.upload(png('galleryrules-up-d.png'));
  await expect.poll(() => held.length).toBe(1);

  await upload.cancelButton.click();
  await held[0].abort();

  await expect(upload.startButton).toBeVisible();
  await expect(upload.uploadingActions).toBeHidden();
  expect(pageErrors).toEqual([]);

  await page.unroute('**/ws.php?*method=pwg.images.upload&*');
  await upload.startButton.click();
  await expect(upload.uploadedThumbnails).toHaveCount(1, { timeout: 15_000 });
});

// [ST] Abbrechen after one file is done keeps that file, reports it and tells the server what to drop.
test('Abbrechen after the first file keeps it and sends the unfinished name', async ({ page }) => {
  const second = 'galleryrules-up-f.png';
  const cancels = [];
  page.on('request', (r) => {
    if (r.url().includes('method=pwg.images.uploadCancel')) cancels.push(r.postData() ?? '');
  });
  /** @type {import('@playwright/test').Route[]} */
  const held = [];
  await page.route('**/ws.php?*method=pwg.images.upload&*', (route) => {
    if ((route.request().postData() ?? '').includes(second)) held.push(route);
    else route.continue();
  });
  const upload = new UploadPage(page);
  await upload.goto(seeded.album_id);
  await upload.addFiles([png('galleryrules-up-e.png'), png(second)]);
  await upload.startButton.click();
  await expect(upload.uploadedThumbnails).toHaveCount(1, { timeout: 15_000 });
  await expect.poll(() => held.length).toBe(1);

  await upload.cancelButton.click();
  await held[0].abort();

  await expect(upload.summary).toBeVisible();
  await expect(upload.uploadedThumbnails).toHaveCount(1);
  expect(cancels).toHaveLength(1);
  expect(decodeURIComponent(cancels[0])).toContain(second);
});
