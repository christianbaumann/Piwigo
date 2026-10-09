// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { PhotoPropertiesPage } = require('./support/PhotoPropertiesPage');
const { seed, restore, trackTag } = require('./support/seed');

/**
 * A tag typed in while tagging lands in the Freitext group and shows that
 * group's badge (plan 2026-10-09, Phase 5): from core's photo properties
 * screen, and from typetags' field on the picture page.
 *
 * Which paths assign Freitext is pinned one layer down (FreitextAssignTest);
 * these specs witness only what a browser alone can: that the badge the
 * visitor sees carries the group's emoji, including the badge typetags'
 * script builds without a reload.
 */
const RUN = Date.now().toString(36);

test.describe('a typed tag in the Freitext group', () => {
  test.afterEach(async () => {
    restore();
  });

  test('a name typed in the photo properties tag field shows the Freitext badge on the picture page', async ({ page }) => {
    const photo = seed('photo');
    expect(photo.freitext_emoji).not.toBe('');
    const name = `Kirmes properties ${RUN}`;

    const properties = new PhotoPropertiesPage(page);
    await properties.goto(photo.image_id);
    await properties.typeNewTag(name);
    await properties.save();
    const tag = trackTag(name);
    expect(tag.group).toBe('Freitext');

    const picture = new PicturePage(page);
    await picture.goto(photo.picture_path);
    await expect(picture.tagEmojiNamed(name)).toHaveText(photo.freitext_emoji);
    expect(await picture.tagBadgeColorNamed(name)).toBe(photo.freitext_rgb);
  });

  test("a name typed into the picture page's field appears as a Freitext badge without a reload, and after one", async ({ page }) => {
    const photo = seed('photo');
    expect(photo.freitext_emoji).not.toBe('');
    const name = `Kirmes picture ${RUN}`;
    const picture = new PicturePage(page);
    await picture.goto(photo.picture_path);

    await picture.addNewTag(name);
    const tag = trackTag(name);

    expect(tag.group).toBe('Freitext');
    await expect(picture.tagLink(tag.tag_id)).toHaveCount(1);
    await expect(picture.tagEmojiNamed(name)).toHaveText(photo.freitext_emoji);
    expect(await picture.tagBadgeColorNamed(name)).toBe(photo.freitext_rgb);

    await picture.goto(photo.picture_path);
    await expect(picture.tagEmojiNamed(name)).toHaveText(photo.freitext_emoji);
    expect(await picture.tagBadgeColorNamed(name)).toBe(photo.freitext_rgb);
  });
});
