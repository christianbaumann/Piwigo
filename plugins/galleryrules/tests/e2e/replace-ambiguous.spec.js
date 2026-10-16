// @ts-check
const { test, expect } = require('@playwright/test');
const { UploadPage } = require('./support/UploadPage');
const { seedSameName, restore } = require('./support/seed');

/** A 1x1 PNG: the bytes do not matter, the refusal comes before any is written. */
const PNG_1X1 = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64'
);

let seeded;

test.beforeAll(() => {
  restore();
  seeded = seedSameName();
});

test.afterAll(() => {
  restore();
});

// [NEG] The replace option shows the refusal on the page, naming both photos, and adds none.
test('Fotos hinzufügen refuses to replace a file name two photos share', async ({ page }) => {
  const upload = new UploadPage(page);
  await upload.goto(seeded.album_id);
  await upload.turnOnReplace();
  expect(await upload.replaceIsOn()).toBe(true);

  await upload.upload({ name: seeded.file, mimeType: 'image/png', buffer: PNG_1X1 });

  await expect(upload.errors).toHaveCount(1, { timeout: 15_000 });
  for (const id of seeded.image_ids) {
    await expect(upload.errors).toContainText(`#${id}`);
  }
  await expect(upload.errors).toContainText(seeded.file);
  await expect(upload.uploadedThumbnails).toHaveCount(0);
});
