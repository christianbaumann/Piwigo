// @ts-check
const path = require('path');
const { test, expect } = require('@playwright/test');
const { seed, restore } = require('./support/seed');
const { PicturePage } = require('./support/PicturePage');

/**
 * The role rows of the persons cells the browser builds: the overlay, the
 * editor (draw and save), the delete control and the name picker, once per
 * role through the sessions auth.setup.js stored.
 *
 * The gates themselves are RoleMatrixTest's; this spec witnesses only what
 * client JavaScript makes of them. Levels per role, from the design's matrix:
 *
 *   role       overlay  add-region  delete-region  name-list
 *   guest      none     none        none           none
 *   generic    see      edit        own            see
 *   normal     see      edit        own            see
 *   admin      see      edit        edit           see
 *   webmaster  see      edit        edit           see
 *
 * The 'overlay' seed's two regions are drawn by nobody, so they stand in for
 * another account's region: only an administrator or the webmaster gets their
 * delete control. The box each role draws is its own.
 */

const STATE = path.join(__dirname, '.state');

const ROLES = [
  { role: 'generic', state: 'auth-generic.json', deletesOthers: false },
  { role: 'normal', state: 'auth.json', deletesOthers: false },
  { role: 'admin', state: 'auth-administrator.json', deletesOthers: true },
  { role: 'webmaster', state: 'auth-admin.json', deletesOthers: true },
];

/** Clear of both seeded regions, so the drag starts on bare photo. */
const CLEAR_BOX = { left: 0.05, top: 0.65, w: 0.15, h: 0.2 };

/** Created through the UI; seed.php --restore removes it by name. */
const MIA = 'E2E Roles Mia';
const MIA_FRAGMENT = 'Roles Mi';

/** The 'overlay' scenario's region count. */
const SEEDED_BOXES = 2;

/** @type {ReturnType<typeof seed>} */
let seeded;

test.beforeEach(() => {
  seeded = seed('overlay');
  expect(seeded.regions).toHaveLength(SEEDED_BOXES);
});

test.afterEach(() => {
  restore();
});

test.describe('as a guest', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('[NEG] gets the login form, no overlay and no editor', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.picture_path);

    // Anti-vacuity: the page that came back is the login form, not a broken photo page.
    await expect(picture.loginForm).toBeVisible();
    await expect(picture.boxes).toHaveCount(0);
    await expect(picture.tagToggle).toHaveCount(0);
  });
});

for (const { role, state, deletesOthers } of ROLES) {
  test.describe(`as ${role}`, () => {
    test.use({ storageState: path.join(STATE, state) });

    const tags = role === 'generic' ? '[HAPPY] [ECP]' : '[HAPPY]';

    test(`${tags} sees the overlay, draws, deletes ${deletesOthers ? 'any' : 'only its own'} region and picks a known name`, async ({ page }) => {
      const picture = new PicturePage(page);
      await picture.goto(seeded.picture_path);
      await picture.waitForPlacement();

      // persons.overlay: see
      await expect(picture.savedBoxes).toHaveCount(SEEDED_BOXES);

      // persons.delete-region on a region somebody else put there
      await picture.enterTaggingMode();
      const others = picture.deleteButton(seeded.regions[0].region_id);
      if (deletesOthers) {
        await expect(others).toBeVisible();
      } else {
        await expect(others).toHaveCount(0);
      }

      // persons.add-region: edit
      await picture.dragBox(CLEAR_BOX);
      await picture.typeName(MIA);
      await picture.pickerInput.press('Enter');
      await expect(picture.savedBoxes).toHaveCount(SEEDED_BOXES + 1);

      // persons.delete-region on its own region
      await picture.deleteButtonOf(MIA).click();
      await expect(picture.savedBoxes).toHaveCount(SEEDED_BOXES);

      // persons.name-list: the person, now on no photo, is offered by the picker
      await picture.dragBox(CLEAR_BOX);
      await picture.searchForExisting(MIA_FRAGMENT, MIA);
      await expect(picture.existingPickerOption(MIA)).toBeVisible();
    });
  });
}
