// @ts-check
const { test, expect } = require('@playwright/test');
const { AlbumSettingsPage, AlbumListPage, TagListPage, serverText } = require('./support/CoreAdminPages');
const { seed, restore } = require('./support/seed');

/**
 * The core admin screens the handbook documents, in the DOM.
 *
 * GermanAdminScreenTest asserts these strings in the page source. Source is not
 * what a reader sees: all three sit in markup that is hidden on arrival, and
 * core reveals them - or replaces them - from JavaScript. A source assertion
 * stays green whether the message ever appears, appears empty, or appears
 * saying something else.
 *
 * The wording is not restated here; that rule lives one layer down. Each spec
 * compares what the user sees against what the server put in the same element.
 * The last spec is why that shape is worth having: on the tag screen the two
 * differ, and no lower layer could have said so.
 *
 * Home is this suite for the same reason the core characterization tests are
 * here - core carries no suite of its own, and adding one would invent a
 * pipeline this repository does not have (.claude/rules/backpressure.md).
 */
test.describe('core admin screens the handbook documents', () => {
  test.afterEach(async () => {
    restore();
  });

  test('saving album settings reveals the confirmation the server sent', async ({ page }) => {
    const fixture = seed('no-provenance');
    expect(fixture.album_id).toBeGreaterThan(0);

    const album = new AlbumSettingsPage(page);
    await album.goto(fixture.album_id);

    const declared = await serverText(album.confirmation);
    // Anti-vacuity: an empty confirmation would make every assertion below pass
    // against a message that says nothing.
    expect(declared).not.toBe('');

    // Hidden until the save comes back, so its appearance is the outcome.
    await expect(album.confirmation).toBeHidden();

    await album.saveButton.click();

    await expect(album.confirmation).toBeVisible();
    await expect(album.confirmation).toHaveText(declared);
    await expect(album.error).toBeHidden();
  });

  test('the album rename dialog shows the label the server sent', async ({ page }) => {
    const albums = new AlbumListPage(page);
    await albums.goto();

    expect(await albums.treeNodes.count()).toBeGreaterThan(0);

    const declared = await serverText(albums.renameLabel);
    expect(declared).not.toBe('');

    await expect(albums.renamePopin).toBeHidden();

    await albums.openRenamePopin();

    await expect(albums.renameLabel).toBeVisible();
    await expect(albums.renameLabel).toContainText(declared);
  });

  /**
   * [ERR] The tag rename dialog replaces its own submit label before showing it.
   *
   * tags.tpl:176 emits `{'Rename Tag'|@translate}` inside `.TagSubmit`, and the
   * fork translates that key. tags.js:290-300 (`set_up_popin`) runs
   * `$(".TagSubmit").html(str_yes_rename_confirmation)` before tags.js:306
   * fades the popin in, so the translated "Rename Tag" reaches no reader. The
   * key stays overridden: it costs nothing, GermanOverrideKeyTest watches the
   * literal, and an upstream version that stops overwriting it gets German
   * without a second change.
   *
   * The oracle is the current implementation, not a requirement. This records
   * the behaviour so the change is visible in a run rather than silent.
   */
  test('the tag rename dialog replaces its submit label, so the translated one is never seen', async ({ page }) => {
    const tags = new TagListPage(page);
    await tags.goto();

    expect(await tags.tagNames.count()).toBeGreaterThan(0);

    const declared = await serverText(tags.renameSubmit);
    expect(declared).not.toBe('');

    await tags.openRenamePopin();

    await expect(tags.renameSubmit).toBeVisible();
    const shown = await serverText(tags.renameSubmit);
    expect(shown).not.toBe('');
    expect(shown).not.toBe(declared);
  });
});
