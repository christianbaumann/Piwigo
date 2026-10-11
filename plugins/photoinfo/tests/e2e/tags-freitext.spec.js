// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { PhotoPropertiesPage } = require('./support/PhotoPropertiesPage');
const { seed, restore, trackTag, makeReadOnly, makeWritable } = require('./support/seed');
const { germanTextOf } = require('./support/lang');

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

test.describe('a failed tag write on the picture page', () => {
  test.afterEach(async () => {
    restore();
  });

  // photoinfo answers a failed tag write with tags_written false and the
  // reason in the account's German (tags_message_l10n); typetags' script shows
  // it next to the tag field, and the tag stays saved.
  test('[NEG] the picture page says the new tag was not written into the file', async ({ page }) => {
    const photo = seed('photo');
    makeReadOnly(photo.image_id);
    const name = `Kirmes refused ${RUN}`;
    const picture = new PicturePage(page);
    await picture.goto(photo.picture_path);

    const answer = await picture.addNewTag(name);
    const tag = trackTag(name);
    expect(answer.tags_written).toBe(false);

    await expect(picture.textOnPage(germanTextOf('PHOTOINFO_FILE_NOT_WRITABLE_MESSAGE'))).toBeVisible();
    await expect(picture.tagLink(tag.tag_id)).toHaveCount(1);
  });

  test('[NEG][ST] removing a tag says it was not written, and the next written change clears that', async ({ page }) => {
    const photo = seed('photo');
    const name = `Kirmes removed ${RUN}`;
    const notWritten = germanTextOf('PHOTOINFO_FILE_NOT_WRITABLE_MESSAGE');
    const picture = new PicturePage(page);
    await picture.goto(photo.picture_path);
    expect((await picture.addNewTag(name)).tags_written).toBe(true);
    const tag = trackTag(name);
    await expect(picture.tagLink(tag.tag_id)).toHaveCount(1);
    await expect(picture.textOnPage(notWritten)).toHaveCount(0);

    makeReadOnly(photo.image_id);
    expect((await picture.removeTag(name)).tags_written).toBe(false);
    await expect(picture.tagWarning).toHaveText(notWritten);
    await expect(picture.tagLink(tag.tag_id)).toHaveCount(0);

    makeWritable(photo.image_id);
    expect((await picture.addTag(name)).tags_written).toBe(true);
    await expect(picture.tagLink(tag.tag_id)).toHaveCount(1);
    await expect(picture.tagWarning).toBeHidden();
    await expect(picture.textOnPage(notWritten)).toHaveCount(0);
  });

  test('[NEG] adding an existing tag says it was not written into the file', async ({ page }) => {
    const photo = seed('photo');
    const name = `Kirmes added ${RUN}`;
    const picture = new PicturePage(page);
    await picture.goto(photo.picture_path);
    expect((await picture.addNewTag(name)).tags_written).toBe(true);
    const tag = trackTag(name);
    expect((await picture.removeTag(name)).tags_written).toBe(true);

    makeReadOnly(photo.image_id);
    expect((await picture.addTag(name)).tags_written).toBe(false);

    await expect(picture.textOnPage(germanTextOf('PHOTOINFO_FILE_NOT_WRITABLE_MESSAGE'))).toBeVisible();
    await expect(picture.tagLink(tag.tag_id)).toHaveCount(1);
  });
});
