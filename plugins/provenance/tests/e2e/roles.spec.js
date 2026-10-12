// @ts-check
const path = require('path');
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * The browser half of the core.zoom row of RoleMatrixTest: fit and zoom on the
 * picture page (decision 0032), once per role through the sessions
 * auth.setup.js stored. The integration row sees that the buttons and the
 * script reach the page; only a browser sees that a press zooms.
 *
 *   role       core.zoom
 *   guest      none  (login form)
 *   generic    edit
 *   normal     edit
 *   admin      edit
 *   webmaster  edit
 *
 * The photo is a copy in the throwaway album of the 'writeback' seed, never a
 * real scan: the trace records screenshots.
 */

const STATE = path.join(__dirname, '.state');

const ROLES = [
  { role: 'generic', state: 'auth-generic.json', tags: '[ECP]' },
  { role: 'normal', state: 'auth-normal.json', tags: '[HAPPY]' },
  { role: 'admin', state: 'auth-admin.json', tags: '[HAPPY]' },
  { role: 'webmaster', state: 'auth.json', tags: '[HAPPY]' },
];

/** Each size is floored to whole pixels; a step must grow the photo by more than that. */
const ROUNDING_PX = 2;

/** @type {ReturnType<typeof seed>} */
let seeded;

test.beforeEach(() => {
  seeded = seed('writeback');
  expect(seeded.photo_ids.length).toBeGreaterThan(0);
});

test.afterEach(() => {
  restore();
});

test.describe('as a guest', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('[NEG] gets the login form and no zoom control', async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(seeded.photo_ids[0], seeded.album_id);

    // Anti-vacuity: what came back is the login form, not a broken photo page.
    await expect(picture.loginForm).toBeVisible();
    await expect(picture.zoomInButton).toHaveCount(0);
  });
});

for (const { role, state, tags } of ROLES) {
  test.describe(`as ${role}`, () => {
    test.use({ storageState: path.join(STATE, state) });

    test(`${tags} one press of + zooms the photo past fit`, async ({ page }) => {
      const picture = new PicturePage(page);
      await picture.goto(seeded.photo_ids[0], seeded.album_id);
      await picture.settleImage();

      const fit = await picture.imageWidth();
      expect(fit).toBeGreaterThan(ROUNDING_PX);

      await picture.zoomIn();

      expect(await picture.imageWidth()).toBeGreaterThan(fit + ROUNDING_PX);
    });
  });
}
