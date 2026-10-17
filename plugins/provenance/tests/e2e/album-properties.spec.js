// @ts-check
const { test, expect } = require('@playwright/test');
const { AlbumSettingsPage, serverText } = require('./support/CoreAdminPages');
const { seed, restore } = require('./support/seed');

/**
 * The album properties screen, in the DOM: the parent picker and the save.
 *
 * Core's cat_modify.js and album_selector.js are fork-local here (see
 * .claude/rules/fork-local-core-edits.md). The server side - a move into an own
 * sub-album is refused, the album list carries the chain the picker reads - is
 * characterized one layer down in CoreAlbumMoveCharacterizationTest. What only a
 * browser can witness is what the screen then shows.
 *
 * Every album is a throwaway from seed.php's 'album-tree' scenario; nothing real
 * is moved.
 */
const SEARCH_TERM = 'provenance E2E tree';

test.describe('album properties: parent picker and save', () => {
  test.afterEach(async () => {
    restore();
  });

  test('a search does not offer the album\'s own sub-albums [NEG]', async ({ page }) => {
    const fixture = seed('album-tree');
    const album = new AlbumSettingsPage(page);
    await album.goto(fixture.album_id);
    await album.openPicker();

    await album.pickerSearch.pressSequentially(SEARCH_TERM);

    // Anti-vacuity: the unrelated album must be listed, or "no child" is just
    // "no results".
    await expect(album.searchRow(fixture.tree.other_id)).toBeVisible();
    await expect(album.searchRow(fixture.tree.child_id)).toHaveCount(0);
    await expect(album.searchRow(fixture.tree.grandchild_id)).toHaveCount(0);
  });

  test('the browsable list does not offer the album\'s sub-albums either [NEG]', async ({ page }) => {
    const fixture = seed('album-tree');
    const album = new AlbumSettingsPage(page);
    // The child's own screen: its parent is the one with a grandchild below it.
    await album.goto(fixture.tree.child_id);
    await album.openPicker();

    const expander = album.pickerExpander(fixture.album_id);
    await expect(expander).toBeVisible();
    await expander.click();

    // The child itself is listed (greyed, it is the album being edited) so the
    // expansion really happened; its own child is not.
    await expect(album.picker.locator(`.search-result-item.already-in[id="${fixture.tree.child_id}"]`)).toBeVisible();
    await expect(album.pickerRow(fixture.tree.grandchild_id)).toHaveCount(0);
    await expect(album.pickerExpander(fixture.tree.child_id)).toHaveCount(0);
  });

  test('a move the server refuses shows its message and not the confirmation [NEG]', async ({ page }) => {
    const fixture = seed('album-tree');
    const album = new AlbumSettingsPage(page);
    await album.goto(fixture.album_id);
    const genericError = await serverText(album.error);
    expect(genericError).not.toBe('');
    const parentBefore = (await album.parentField.textContent()) || '';

    // The picker no longer offers the child, so the refused request is forced
    // by the page's own variable - the server is the second line of defence.
    await page.evaluate((id) => { parent_album = id; }, fixture.tree.child_id);
    await album.saveButton.click();

    await expect(album.error).toBeVisible();
    await expect(album.error).not.toHaveText(genericError);
    await expect(album.confirmation).toBeHidden();
    await expect(album.parentField).toHaveText(parentBefore.trim());
  });

  test('moving into an unrelated album shows the confirmation and the new path [HAPPY]', async ({ page }) => {
    const fixture = seed('album-tree');
    const album = new AlbumSettingsPage(page);
    await album.goto(fixture.album_id);
    await album.openPicker();
    await album.pickerRow(fixture.tree.other_id).click();

    await album.saveButton.click();

    await expect(album.confirmation).toBeVisible();
    await expect(album.error).toBeHidden();
    await expect(album.ariane).toContainText(fixture.tree.other_name);

    await album.goto(fixture.album_id);
    await expect(album.parentField).toContainText(fixture.tree.other_name);
  });

  test('the info box says "just now" once after a save [HAPPY]', async ({ page }) => {
    const fixture = seed('album-tree');
    const album = new AlbumSettingsPage(page);
    await album.goto(fixture.album_id);
    const justNow = await page.evaluate(() => str_just_now);
    expect(justNow).not.toBe('');

    await album.saveButton.click();

    await expect(album.confirmation).toBeVisible();
    await expect(album.modifiedContent).toHaveText(justNow);
    await expect(album.modifiedSubcontent).toHaveText('');
    const card = (await album.modifiedCard.textContent()) || '';
    expect(card.split(justNow).length - 1).toBe(1);
  });
});
