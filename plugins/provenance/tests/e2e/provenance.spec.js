// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');

/**
 * The public provenance row, in a real browser.
 *
 * The gap this closes: PicturePageSourceTest proves the server emits the row
 * and puts it inside <dl id="standard">, but not that a visitor can see it -
 * the theme could hide it, and a definition list rendered by a prefilter is
 * exactly the kind of thing a stylesheet swallows without an error anywhere.
 *
 * Nothing here restates PicturePageSourceTest: that suite owns the page source,
 * the composed text and the visibility toggle.
 *
 * One theme, not two. modus is the only theme this install carries, and it
 * declares 'parent' => 'default' with no picture.tpl of its own - so the page
 * rendered below *is* themes/default/template/picture.tpl. There is no second
 * theme to switch to; see the Phase 8 deviation note in the plan.
 */

/** Five labelled parts joined by four separators. */
const SEEDED_PARTS = 5;

/** Narrower than any phone this gallery is browsed on, so the layout check is not lenient. */
const NARROW_VIEWPORT = { width: 320, height: 720 };

test.describe('public provenance row', () => {
  test.afterEach(async () => {
    restore();
  });

  test('a visitor sees the row inside the photo information list', async ({ page }) => {
    const fixture = seed('photo-provenance');
    expect(fixture.photo.values.provenance_physical_album).toBeTruthy();

    const picture = new PicturePage(page);
    await picture.goto(fixture.photo.id, fixture.album_id);

    await expect(picture.infoList).toBeVisible();
    await expect(picture.row).toBeVisible();
    await expect(picture.label).not.toBeEmpty();
  });

  test('the row shows every provenance fact the photo carries', async ({ page }) => {
    const fixture = seed('photo-provenance');

    const values = Object.values(fixture.photo.values).filter(Boolean);
    expect(values).toHaveLength(SEEDED_PARTS);

    const picture = new PicturePage(page);
    await picture.goto(fixture.photo.id, fixture.album_id);

    const text = await picture.value.innerText();
    for (const value of values) {
      expect(text).toContain(value);
    }
  });

  test('the label is rendered in the language the account browses in', async ({ page }) => {
    const fixture = seed('photo-provenance');
    expect(fixture.row_label).toBeTruthy();

    const picture = new PicturePage(page);
    await picture.goto(fixture.photo.id, fixture.album_id);

    // seed.php resolves this out of the account's own language file, so on this
    // German install it is 'Herkunft' and an untranslated key would fail here.
    await expect(picture.label).toHaveText(fixture.row_label);
  });

  test('the row stays inside its column on a narrow viewport', async ({ page }) => {
    const fixture = seed('photo-provenance');

    await page.setViewportSize(NARROW_VIEWPORT);

    const picture = new PicturePage(page);
    await picture.goto(fixture.photo.id, fixture.album_id);
    await expect(picture.row).toBeVisible();

    expect(await picture.horizontalOverflow()).toBeLessThanOrEqual(0);
  });

  test('a photo with no provenance gets no row at all', async ({ page }) => {
    const fixture = seed('no-provenance');
    expect(fixture.photo_ids.length).toBeGreaterThan(0);

    const picture = new PicturePage(page);
    await picture.goto(fixture.photo_ids[0], fixture.album_id);

    await expect(picture.infoList).toBeVisible();
    await expect(picture.rowAnywhere).toHaveCount(0);
  });
});

/**
 * The same row for someone who is not logged in.
 *
 * A separate describe because it needs a context with no stored session, which
 * is a per-file/per-describe fixture rather than something a spec can drop.
 *
 * What this adds over PicturePageSourceTest::testGuestGetsTheRow, which already
 * owns "the server emits the row for a guest": the guest page is a different
 * render - no admin toolbar, a different menubar, a different body class - and
 * only a browser says whether the theme still lays the row out where a visitor
 * can read it. The rule lives one layer down; this witnesses the rendering.
 */
test.describe('public provenance row, seen by a visitor who is not logged in', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test.afterEach(async () => {
    restore();
  });

  test('the row is visible without logging in', async ({ page }) => {
    const fixture = seed('photo-provenance');
    expect(fixture.photo.values.provenance_owner).toBeTruthy();

    const picture = new PicturePage(page);
    await picture.goto(fixture.photo.id, fixture.album_id);

    // Proves the browser really arrived as a guest; without it this spec would
    // pass on a leaked session and assert nothing about the public page.
    await expect(picture.adminLink).toHaveCount(0);

    await expect(picture.row).toBeVisible();
    await expect(picture.value).toContainText(fixture.photo.values.provenance_owner);
  });
});
