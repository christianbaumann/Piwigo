// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');
const {
  AlbumSettingsPage,
  AlbumListPage,
  TagListPage,
  UploadPage,
  BatchManagerActionPage,
  serverText,
} = require('./support/CoreAdminPages');
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

/**
 * The controls `docs/handbuch/` tells a reader to click.
 *
 * Every one of these is assembled or revealed by JavaScript, so a page-source
 * assertion cannot witness it: the row actions are appended by albums.js from
 * a web-service payload, the add-album dialog and the tag menu are hidden on
 * arrival, and the merge panel is two interactions deep. If one of them moves,
 * the handbook silently documents a screen nobody has.
 *
 * None of them writes anything. Where a workflow's outcome is already proven at
 * the integration layer - creating an album, associating a photo - these stop
 * at the control the handbook names rather than restating the outcome one layer
 * up, per the placement rule in .claude/rules/testing.md.
 */
test.describe('the controls the handbook tells a reader to click', () => {
  test('every album row offers the six actions, labelled as the template declared them', async ({ page }) => {
    const albums = new AlbumListPage(page);
    await albums.goto();

    const rows = await albums.treeNodes.count();
    // Anti-vacuity: with no rows every per-action assertion below is skipped.
    expect(rows).toBeGreaterThan(0);

    let previous = '';
    let witnessed = 0;

    for (const action of albums.rowActions) {
      // One anchor per row: an action that renders for some albums and not
      // others is the case the handbook's flat list would get wrong.
      expect(await action.locator.count()).toBe(rows);

      // An inert action lets its own container take the pointer, so it has no
      // tooltip to read. Today that is the sort action and only the sort
      // action, because no album on this install has sub-albums.
      if ((await action.reachable.count()) === 0) {
        continue;
      }

      // The label is read off the first reachable row. tipTip builds one
      // tooltip element and reuses it, so hovering every row would assert the
      // same element N times.
      const { shown, declared } = await albums.actionLabels(action, previous);
      expect(declared).not.toBe('');
      expect(shown).toBe(declared);
      previous = shown;
      witnessed++;
    }

    // Five of the six are reachable on any tree; the sixth needs an album with
    // sub-albums, which this install has none of. A run that witnessed fewer
    // has stopped reading labels rather than found them all correct.
    expect(witnessed).toBeGreaterThanOrEqual(albums.rowActions.length - 1);
  });

  /**
   * [DT] The sort action is inert exactly when the album has no sub-albums.
   *
   * albums.js:392-393 greys it out and marks the row's toggler in the same
   * branch, because that action orders sub-albums and has nothing to order
   * without them. 01-alben.html says so; before this spec existed the page said
   * it reorders the album's photos, which is what the icon looks like it should
   * do and is not what it does.
   *
   * Stated as an invariant rather than a distribution: every album on this
   * install is currently top-level, so only the inert side is exercised today,
   * and a spec demanding both would go red the day somebody nests an album.
   */
  test('the sort action is greyed out exactly on albums with no sub-albums', async ({ page }) => {
    const albums = new AlbumListPage(page);
    await albums.goto();

    const total = await albums.sortAction.count();
    // Anti-vacuity: no rows, no invariant.
    expect(total).toBeGreaterThan(0);

    const withoutChildren = await albums.rowsWithoutChildren.count();
    const withChildren = await albums.rowsWithChildren.count();
    expect(withoutChildren + withChildren).toBe(total);

    expect(await albums.sortActionDisabled.count()).toBe(withoutChildren);

    if (withoutChildren > 0) {
      const opacity = await albums.sortActionDisabled
        .first()
        .evaluate((node) => getComputedStyle(node).opacity);
      expect(Number(opacity)).toBeLessThan(1);
    }
  });

  test('the add-album dialog carries the fields the handbook names, and cancelling closes it', async ({ page }) => {
    const albums = new AlbumListPage(page);
    await albums.goto();

    await expect(albums.addAlbumPopin).toBeHidden();

    await albums.openAddAlbumDialog();

    // The handbook's step list: a name, a position with two choices, and a
    // confirm beside a cancel. The dialog carries no description field, which
    // is the sentence 01-alben.html spends a callout on.
    await expect(albums.addAlbumName).toBeVisible();
    await expect(albums.addAlbumPlaceFirst).toBeVisible();
    await expect(albums.addAlbumPlaceLast).toBeVisible();
    await expect(albums.addAlbumSubmit).toBeVisible();
    await expect(albums.addAlbumCancel).toBeVisible();
    await expect(albums.addAlbumPopin.locator('textarea')).toHaveCount(0);

    // Exactly one of the two positions is preselected, so a reader who presses
    // Hinzufügen without choosing gets a defined placement.
    const checked = await albums.addAlbumPlaceFirst.isChecked();
    expect(checked).not.toBe(await albums.addAlbumPlaceLast.isChecked());

    await albums.addAlbumCancel.click();
    await expect(albums.addAlbumPopin).toBeHidden();
  });

  test('the tag menu offers the five entries the handbook lists', async ({ page }) => {
    const tags = new TagListPage(page);
    await tags.goto();

    expect(await tags.tagNames.count()).toBeGreaterThan(0);
    await expect(tags.firstDropdown).toBeHidden();

    await tags.openFirstDropdown();

    // View in gallery, Manage photos, Edit, Duplicate, Delete - tags.tpl:67-71.
    // The first two are emitted with display:none for a tag no photo carries,
    // which is why the handbook says they can be missing; the count is of what
    // the menu offers, not of what is visible for this particular tag.
    expect(await tags.firstDropdownOptions.count()).toBe(5);

    for (let i = 0; i < 5; i++) {
      const label = await serverText(tags.firstDropdownOptions.nth(i));
      expect(label).not.toBe('');
    }
  });

  /**
   * [ERR] The merge panel's own two buttons are hardcoded English.
   *
   * `tags.tpl:110-111` emits `Confirm merge` and `Cancel` as literals with no
   * `|@translate`, so a German install shows English there. The oracle is those
   * two literals, not a requirement - which is why the strings are named here
   * rather than read from the page: the point of the test is that they are not
   * translation keys. 04-schlagworte.html tells the reader so, and this fails
   * the day that stops being true, which is the day the handbook must change.
   */
  test('the merge panel opens two interactions deep and its own buttons are untranslated', async ({ page }) => {
    const tags = new TagListPage(page);
    await tags.goto();

    expect(await tags.tagNames.count()).toBeGreaterThan(1);
    await expect(tags.mergeBlock).toBeHidden();

    await tags.selectTags(2);
    await expect(tags.mergeButton).toBeVisible();

    await tags.mergeButton.click();
    await expect(tags.mergeBlock).toBeVisible();

    // The panel's own explanatory copy is translated - it is a translation key.
    const heading = await serverText(tags.mergeBlock.locator('p').first());
    expect(heading).not.toBe('');

    expect(await serverText(tags.mergeConfirm)).toBe('Confirm merge');
    expect(await serverText(tags.mergeCancel)).toBe('Cancel');

    await tags.mergeCancel.click();
    await expect(tags.mergeBlock).toBeHidden();
  });

  test('the upload screen names its file types and hides one option behind the Optionen control', async ({ page }) => {
    const fixture = seed('no-provenance');
    const upload = new UploadPage(page);
    await upload.goto(fixture.album_id);

    // The allowed types are the handbook's own callout; the wording is the
    // server's, so only its presence is asserted here.
    const types = await serverText(upload.allowedTypes);
    expect(types).not.toBe('');

    await expect(upload.optionsContent).toBeHidden();

    await upload.optionsToggle.click();

    await expect(upload.optionsContent).toBeVisible();
    await expect(upload.updateModeSwitch).toBeVisible();
    const option = await serverText(upload.optionsContent);
    expect(option).not.toBe('');
  });

  test('choosing "associate" reveals the album picker the handbook sends the reader to', async ({ page }) => {
    const batch = new BatchManagerActionPage(page);
    await batch.goto();

    expect(await batch.thumbnails.count()).toBeGreaterThan(0);
    await expect(batch.associateBlock).toBeHidden();

    await batch.chooseAssociate();

    // Nothing is applied: what the action does is CoreAssociationCharacterization
    // Test's subject. What only a browser can say is that picking the action
    // brings up the album picker the handbook's step 4 tells the reader to use.
    await expect(batch.associateBlock).toBeVisible();
    await expect(batch.associateAlbumButton.first()).toBeVisible();
  });
});

/**
 * [NEG] The permission claim the handbook's index makes.
 *
 * index.html tells the reader that album, photo-text and tag administration
 * need administrator rights. An admin gate is only proven by an authenticated
 * non-admin failing to pass it, and this suite otherwise runs as a webmaster,
 * which cannot witness that.
 */
test.describe('a normal account on the core admin screens', () => {
  test.use({ storageState: path.join(__dirname, '.state', 'auth-normal.json') });

  const RESERVED_SCREENS = ['albums', 'tags', 'photos_add', 'batch_manager'];

  for (const screen of RESERVED_SCREENS) {
    test(`is refused ${screen}`, async ({ page }) => {
      const response = await page.goto(`/admin.php?page=${screen}`);

      expect(response?.status()).toBe(401);
      // Not merely a status: the screen's own furniture must be absent, so a
      // future 401-with-the-page-anyway does not pass.
      await expect(page.locator('.tree .jqtree-element')).toHaveCount(0);
      await expect(page.locator('.tag-name')).toHaveCount(0);
      await expect(page.locator('#uploadForm')).toHaveCount(0);
      await expect(page.locator('select[name="selectAction"]')).toHaveCount(0);
    });
  }
});
