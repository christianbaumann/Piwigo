// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { statePath } = require('./support/accounts');
const { seed, restore } = require('./support/seed');

/**
 * photoedit's role matrix where client JavaScript builds what the role sees:
 * the warning before a crop cuts a person away and the note before a JPEG is
 * re-encoded, both in the confirm the editor builds from the dry run's answer
 * (photoedit.warnings: see for every logged-in account, none for a guest).
 * The server's answer per role, and the button in the page source, are
 * RoleMatrixTest's; this proves the browser shows them.
 *
 * The seeded regions are the viewer's own: a normal account's crop of a region
 * somebody else drew is refused instead (persons decision 0065,
 * other-accounts-save.spec.js).
 */

test.afterEach(() => {
  restore();
});

for (const [role, tags] of /** @type {const} */ ([
  ['WEBMASTER', '[HAPPY]'],
  ['ADMIN', '[HAPPY]'],
  ['NORMAL', '[HAPPY]'],
  ['GENERIC', '[ECP]'],
])) {
  test(`${tags} ${role.toLowerCase()}: the save asks with the lost-region warning and the re-encode note`, async ({ browser }) => {
    restore();
    const fixture = seed('warnings', role);
    const context = await browser.newContext({ storageState: statePath(role) });
    try {
      const picture = new PicturePage(await context.newPage());
      await picture.goto(fixture.picture_path);
      await expect(picture.editButton).toBeVisible();
      // Not "Bearbeiten": core's own edit button already carries that name.
      await expect(picture.editButton).toHaveText(fixture.edit_label);
      const texts = await picture.confirmTexts();
      expect(texts.lost, 'anti-vacuity: no warning text on the button').not.toBe('');
      expect(texts.lossy, 'anti-vacuity: no note text on the button').not.toBe('');
      await picture.editButton.click();
      await picture.dragCropEdge('e', 0.5);

      const [dialog] = await Promise.all([picture.page.waitForEvent('dialog'), picture.saveButton.click()]);
      const type = dialog.type();
      const message = dialog.message();
      await dialog.dismiss();

      expect(type).toBe('confirm');
      expect(message).toContain(`${texts.lost} ${fixture.cut_name}`);
      expect(message).not.toContain(fixture.kept_name);
      expect(message).toContain(texts.lossy);
    } finally {
      await context.close();
    }
  });
}

test('[NEG] guest: gets the login page, no editor and so no warning', async ({ browser }) => {
  restore();
  const fixture = seed('warnings');
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  try {
    const picture = new PicturePage(await context.newPage());
    await picture.page.goto(fixture.picture_path);

    await expect(picture.loginPassword).toBeVisible();
    await expect(picture.editButton).toHaveCount(0);
  } finally {
    await context.close();
  }
});
