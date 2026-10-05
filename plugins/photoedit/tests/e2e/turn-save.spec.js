// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * Turning a photo and saving it, as the webmaster. The photo is a generated
 * landscape PNG (seed --scenario=marked), so a quarter turn shows as portrait.
 *
 * What the file and the row hold afterwards is the integration suite's
 * (ApplyTurnTest); this spec covers the editor: the preview, the save request
 * it sends and the reload that shows the new file.
 */

/** @type {ReturnType<typeof seed>} */
let fixture;

test.beforeEach(() => {
  restore();
  fixture = seed('marked');
});

test.afterEach(() => {
  restore();
});

test('[HAPPY] a quarter turn and Save shows the turned photo after the reload', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);

  const before = await picture.naturalSize();
  expect(before.width, 'anti-vacuity: the seeded photo is not landscape').toBeGreaterThan(before.height);

  await picture.editButton.click();
  await picture.turnRightButton.click();
  expect(await picture.previewTransform()).toContain('rotate(90deg)');

  await picture.saveAndWaitForReload();

  await expect(picture.editMode).toHaveCount(0);
  const after = await picture.naturalSize();
  expect(after.height).toBeGreaterThan(after.width);
});

test('[ST] turns add up, a full circle is no turn, and Cancel drops the preview', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);

  await picture.editButton.click();
  await picture.turnLeftButton.click();
  expect(await picture.previewTransform()).toContain('rotate(270deg)');
  await picture.turnRightButton.click();
  expect(await picture.previewTransform()).toBe('');

  for (let turn = 1; turn <= 3; turn++) {
    await picture.turnRightButton.click();
    expect(await picture.previewTransform()).toContain(`rotate(${turn * 90}deg)`);
  }
  await picture.turnRightButton.click();
  expect(await picture.previewTransform()).toBe('');

  await picture.turnRightButton.click();
  await picture.cancelButton.click();
  await expect(picture.editMode).toHaveCount(0);
  expect(await picture.previewTransform()).toBe('');
});

test('[ST] a turn pressed while the save is in flight is not written', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);

  await picture.editButton.click();
  await picture.turnRightButton.click();
  const release = await picture.holdApplyRequests();
  await picture.saveButton.click();

  // Pointer events are off while saving; the keyboard still reaches a focused control.
  await picture.turnRightButton.focus();
  await page.keyboard.press('Enter');
  expect(await picture.previewTransform()).toContain('rotate(90deg)');

  await Promise.all([page.waitForEvent('load'), release()]);
  await picture.waitForPhoto();
  const after = await picture.naturalSize();
  expect(after.height, 'written as two turns, not one').toBeGreaterThan(after.width);
});

test('[ERR] an unreadable answer to the write reloads the page instead of offering a second save', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.garbleWriteResponse();
  /** @type {string[]} */
  const alerts = [];
  page.on('dialog', (dialog) => {
    alerts.push(dialog.message());
    dialog.accept();
  });

  await picture.editButton.click();
  await picture.turnRightButton.click();
  await picture.saveAndWaitForReload();

  expect(alerts, 'the error was not shown').toHaveLength(1);
  await expect(picture.editMode).toHaveCount(0);
  const after = await picture.naturalSize();
  expect(after.height).toBeGreaterThan(after.width);
});
