// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * Entering and leaving edit mode as the webmaster. Nothing is saved here;
 * turn-save.spec.js saves.
 *
 * The persons plugin must be active too; its overlay is part of what edit mode
 * hides.
 */

/** @type {ReturnType<typeof seed>} */
let fixture;

/** A width inside modus' collapsed action menu (picture.css.tpl, max-width 600px). */
const NARROW_VIEWPORT = { width: 400, height: 800 };

/** Keys the theme or core act on: zoom, and photo navigation. */
const WATCHED_KEYS = ['+', 'ArrowLeft', 'ArrowRight', 'ArrowUp'];

test.beforeEach(() => {
  restore();
  fixture = seed('photo');
});

test.afterEach(() => {
  restore();
});

/**
 * Counts the clicks inside the photo's area and the watched key presses that
 * reach their target in the bubble phase.
 *
 * The theme's click navigation sits on the photo, the image map's links inside
 * the same area, and the zoom and navigation keys on the document - all in the
 * bubble phase. So an event counted here is one the theme or core received: the
 * causal fact, rather than waiting to see whether a navigation or a zoom happens.
 *
 * @param {import('@playwright/test').Page} page
 */
async function countArrivals(page) {
  await page.evaluate((keys) => {
    const w = /** @type {any} */ (window);
    w.__photoeditArrivals = { click: 0, key: 0 };
    document.getElementById('theImage')?.addEventListener('click', () => w.__photoeditArrivals.click++);
    document.addEventListener('keydown', (e) => {
      if (keys.includes(e.key)) {
        w.__photoeditArrivals.key++;
      }
    });
  }, WATCHED_KEYS);
  return () => page.evaluate(() => /** @type {any} */ (window).__photoeditArrivals);
}

test('[HAPPY] edit mode opens with the persons overlay hidden, and Cancel puts the page back', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);

  await expect(picture.personsOverlay).toBeVisible();
  await expect(picture.personsEditor).toBeVisible();
  await expect(picture.controls).toBeHidden();
  const fitted = await picture.imageWidth();

  await picture.editButton.click();

  await expect(picture.editMode).toHaveCount(1);
  await expect(picture.controls).toBeVisible();
  await expect(picture.turnLeftButton).toBeVisible();
  await expect(picture.turnRightButton).toBeVisible();
  await expect(picture.saveButton).toBeVisible();
  await expect(picture.editButton).toBeHidden();
  await expect(picture.zoomFitButton).toBeHidden();
  await expect(picture.personsOverlay).toHaveCount(1);
  await expect(picture.personsOverlay).toBeHidden();
  await expect(picture.personsEditor).toBeHidden();

  await picture.cancelButton.click();

  await expect(picture.editMode).toHaveCount(0);
  await expect(picture.controls).toBeHidden();
  await expect(picture.editButton).toBeVisible();
  await expect(picture.zoomFitButton).toBeVisible();
  await expect(picture.personsOverlay).toBeVisible();
  await expect(picture.personsEditor).toBeVisible();
  expect(await picture.imageWidth()).toBe(fitted);
});

test('[ST] edit mode refits a zoomed photo', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  const fitted = await picture.imageWidth();

  await page.keyboard.press('+');
  await expect.poll(() => picture.imageWidth()).toBeGreaterThan(fitted);

  await picture.editButton.click();

  await expect.poll(() => picture.imageWidth()).toBe(fitted);
});

test('[ST] in edit mode the theme gets no click on the photo and no zoom or navigation key', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await expect(picture.nextLink).toHaveCount(1);
  const arrivals = await countArrivals(page);
  const fitted = await picture.imageWidth();

  await picture.editButton.click();
  await picture.clickNextZone();
  for (const key of ['+', 'ArrowLeft', 'ArrowRight', 'Control+ArrowUp']) {
    await page.keyboard.press(key);
  }
  await picture.ctrlWheelOverPhoto();

  expect(await arrivals()).toEqual({ click: 0, key: 0 });
  expect(await picture.imageWidth()).toBe(fitted);

  // Out of edit mode the same gestures reach the theme again - the control that
  // shows the zeros above are not broken counters.
  await picture.cancelButton.click();
  await page.keyboard.press('+');
  await expect.poll(() => picture.imageWidth()).toBeGreaterThan(fitted);
  expect((await arrivals()).key).toBe(1);

  await page.keyboard.press('0');
  await picture.clickNextZone();
  await page.waitForURL((url) => !url.href.includes(fixture.picture_path));
});

test('[ST] Escape leaves edit mode', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);

  await picture.editButton.click();
  await expect(picture.editMode).toHaveCount(1);
  await page.keyboard.press('Escape');

  await expect(picture.editMode).toHaveCount(0);
});

test('[ST] entering edit mode ends persons tagging first', async ({ page }) => {
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await picture.personsTagToggle.click();
  await expect(picture.personsTagging).toHaveCount(1);

  await picture.editButton.click();

  await expect(picture.personsTagging).toHaveCount(0);
  await expect(picture.editMode).toHaveCount(1);
});

test('[ST] on a narrow screen the controls stay reachable in the collapsed menu', async ({ page }) => {
  await page.setViewportSize(NARROW_VIEWPORT);
  const picture = new PicturePage(page);
  await picture.goto(fixture.picture_path);
  await expect(picture.actionMenuSwitch).toBeVisible();

  await picture.actionMenuSwitch.click();
  await picture.editButton.click();
  // The menu closes on mouseleave everywhere else.
  await picture.header.hover();

  await expect(picture.cancelButton).toBeVisible();
  await picture.cancelButton.click();
  await expect(picture.editMode).toHaveCount(0);
});
