// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { statePath } = require('./support/accounts');
const { seed, restore } = require('./support/seed');

/**
 * An account that is not the webmaster saves an edit through the same editor,
 * with the same questions first (decision 0059). What the server reports to
 * each role is the integration suite's (ApplyTurnTest, ApplyJpegTest,
 * ApplyRegionsTest); this covers the dialogs and the reload in the browser.
 */

/** toBeCloseTo's digits for a frame edge placed by a mouse drag. */
const RATIO_TOLERANCE_DIGITS = 1;

test.afterEach(() => {
  restore();
});

/**
 * @param {import('@playwright/test').Browser} browser
 * @param {'ADMIN'|'NORMAL'} role
 * @param {(picture: PicturePage) => Promise<void>} body
 */
async function as(browser, role, body) {
  const context = await browser.newContext({ storageState: statePath(role) });
  try {
    await body(new PicturePage(await context.newPage()));
  } finally {
    await context.close();
  }
}

for (const role of /** @type {const} */ (['NORMAL', 'ADMIN'])) {
  test(`[HAPPY] ${role.toLowerCase()}: a JPEG turn asks about the re-encode, then saves`, async ({ browser }) => {
    restore();
    const fixture = seed('jpeg');
    await as(browser, role, async (picture) => {
      /** @type {string[]} */
      const dialogs = [];
      picture.page.on('dialog', (dialog) => {
        dialogs.push(dialog.type());
        dialog.accept();
      });
      await picture.goto(fixture.picture_path);
      await picture.editButton.click();
      await picture.turnRightButton.click();

      await picture.saveAndWaitForReload();

      expect(dialogs).toEqual(['confirm']);
      const size = await picture.naturalSize();
      expect(size.height).toBeGreaterThan(size.width);
    });
  });
}

/**
 * The administrator may cut any person away; a normal account only one it
 * marked itself (persons decision 0065), and the seeded regions are nobody's.
 * That a normal account may cut away its own is ApplyRegionsTest's.
 */
test('[HAPPY] admin: a crop that cuts a person away warns by name, then saves', async ({ browser }) => {
  restore();
  const fixture = seed('regions');
  await as(browser, 'ADMIN', async (picture) => {
    /** @type {string[]} */
    const messages = [];
    picture.page.on('dialog', (dialog) => {
      messages.push(dialog.message());
      dialog.accept();
    });
    await picture.goto(fixture.picture_path);
    await picture.editButton.click();
    await picture.dragCropEdge('e', 0.5);

    await picture.saveAndWaitForReload();

    expect(messages).toHaveLength(1);
    expect(messages[0]).toContain(fixture.cut_name);
    expect(messages[0]).not.toContain(fixture.kept_name);
    const size = await picture.naturalSize();
    expect(size.width / size.height).toBeCloseTo((fixture.width / 2) / fixture.height, RATIO_TOLERANCE_DIGITS);
  });
});

test('[NEG] normal: a crop that would cut away a person somebody else marked is refused by name, nothing written', async ({ browser }) => {
  restore();
  const fixture = seed('regions');
  await as(browser, 'NORMAL', async (picture) => {
    /** @type {string[]} */
    const alerts = [];
    picture.page.on('dialog', (dialog) => {
      alerts.push(`${dialog.type()}: ${dialog.message()}`);
      dialog.accept();
    });
    const writes = picture.watchWrites();
    await picture.goto(fixture.picture_path);
    await picture.markDocument();
    await picture.editButton.click();
    await picture.dragCropEdge('e', 0.5);

    await picture.saveButton.click();

    await expect.poll(() => alerts.length).toBe(1);
    expect(alerts[0]).toMatch(/^alert: /);
    expect(alerts[0]).toContain(fixture.cut_name);
    expect(alerts[0]).not.toContain(fixture.kept_name);
    expect(writes()).toBe(0);
    expect(await picture.sameDocument()).toBe(true);
  });
});
