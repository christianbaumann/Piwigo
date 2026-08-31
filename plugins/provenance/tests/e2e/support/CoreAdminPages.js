// @ts-check

/**
 * Locators for the core admin screens the handbook documents.
 *
 * They live in this suite for the same reason the core characterization tests
 * do (CoreAlbumCharacterizationTest and its siblings): Piwigo core carries no
 * suite of its own, and standing one up would invent a pipeline this repository
 * does not have. Nothing here touches the provenance plugin.
 */

/** The album properties screen, `admin.php?page=album-<id>-properties`. */
class AlbumSettingsPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
    this.saveButton = page.locator('#cat-properties-save');
    this.confirmation = page.locator('#cat-modify .info-message');
    this.error = page.locator('#cat-modify .info-error');
  }

  async goto(albumId) {
    await this.page.goto(`/admin.php?page=album-${albumId}-properties`);
    await this.saveButton.waitFor({ state: 'visible' });
  }
}

/** The album list, `admin.php?page=albums`. */
class AlbumListPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
    this.treeNodes = page.locator('.tree .jqtree-element');
    this.albumTitles = page.locator('.move-cat-title-container');
    this.renamePopin = page.locator('#RenameAlbum');
    this.renameLabel = page.locator('#RenameAlbum .RenameAlbumLabelUsername');

    // The six row actions the handbook lists, in the order albums.js:358-366
    // appends them. Keyed by the global the template declares the label in, so
    // the German lives in the language file and not in a spec.
    this.rowActions = [
      { selector: '.move-cat-add', declaredIn: 'str_add_album' },
      { selector: '.move-cat-edit', declaredIn: 'str_edit_album' },
      { selector: '.move-cat-upload', declaredIn: 'str_add_photo' },
      { selector: '.move-cat-see', declaredIn: 'str_visit_gallery' },
      { selector: '.move-cat-order', declaredIn: 'str_sort_order' },
      { selector: '.move-cat-delete', declaredIn: 'str_delete_album' },
      ].map((action) => ({
        ...action,
        locator: page.locator(action.selector),
        // The row a reader could actually hover: an inert action lets its own
        // container take the pointer, so hovering it yields no tooltip.
        reachable: page.locator(`${action.selector}:not(.notClickable)`),
      }));

    // tipTip moves a `title` into its own tooltip and removes the attribute, so
    // the label a reader sees is only in the tooltip once the anchor is hovered.
    this.tooltip = page.locator('#tiptip_holder');
    this.tooltipText = page.locator('#tiptip_content');

    // albums.js:392-393 greys out the sort action and marks the row's toggler
    // whenever an album has no sub-albums: that action orders sub-albums, so it
    // has nothing to do without them.
    this.rowsWithoutChildren = page.locator('.jqtree-element.disabledToggle');
    this.rowsWithChildren = page.locator('.jqtree-element:not(.disabledToggle)');
    this.sortAction = page.locator('.move-cat-order');
    this.sortActionDisabled = page.locator('.move-cat-order.notClickable');

    this.addAlbumButton = page.locator('.add-album-button label');
    this.addAlbumPopin = page.locator('.AddAlbumPopInContainer');
    this.addAlbumName = page.locator('.AddAlbumPopInContainer .AddAlbumInputContainer input.user-property-input');
    this.addAlbumPlaceFirst = page.locator('#place-start');
    this.addAlbumPlaceLast = page.locator('#place-end');
    this.addAlbumSubmit = page.locator('.AddAlbumSubmit');
    this.addAlbumCancel = page.locator('.AddAlbumCancel');
  }

  /**
   * The label a row action carries, and the label the template declared for it.
   *
   * albums.js builds the anchors from page-level `const` declarations emitted by
   * albums.tpl:47-52, so the declaration is reachable in the page's global
   * lexical scope. Reading it there keeps the German in the language file.
   *
   * @param {{locator: import('@playwright/test').Locator, declaredIn: string}} action
   */
  async actionLabels(action, previous) {
    // The sort action is inert on an album with no sub-albums, so the label is
    // read off a row where a reader could actually reach it.
    await action.reachable.first().hover();
    await this.tooltip.waitFor({ state: 'visible' });

    // tipTip builds one tooltip element and rewrites it, so a read taken too
    // early returns the previous action's label. Waiting for the text to change
    // is the causal fact; a fixed pause would be a wall-clock proxy for it.
    await this.tooltipText.filter({ hasNotText: previous === '' ? /^$/ : previous }).waitFor();
    const shown = (await this.tooltipText.textContent()) || '';

    const declared = await this.page.evaluate(
      (name) => String(eval(name)),
      action.declaredIn
    );

    return { shown: shown.trim(), declared: declared.trim() };
  }

  async openAddAlbumDialog() {
    await this.addAlbumButton.click();
    await this.addAlbumPopin.waitFor({ state: 'visible' });
  }

  async goto() {
    await this.page.goto('/admin.php?page=albums');
    // The tree is built by jqTree from a web-service payload.
    await this.treeNodes.first().waitFor({ state: 'visible' });
  }

  async openRenamePopin() {
    await this.albumTitles.first().click();
    await this.renamePopin.waitFor({ state: 'visible' });
  }
}

/** The tag list, `admin.php?page=tags`. */
class TagListPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
    this.tagNames = page.locator('.tag-name');
    this.optionsToggles = page.locator('.showOptions');
    this.editOptions = page.locator('.dropdown-option.edit');
    this.renamePopin = page.locator('#RenameTag');
    this.renameSubmit = page.locator('#RenameTag .TagSubmit');

    // The kebab menu of the first tag. Scoped to one dropdown: the screen emits
    // one per tag, so an unscoped locator would count the whole list.
    this.firstDropdown = page.locator('.tag-dropdown-block .dropdown-content').first();
    this.firstDropdownOptions = this.firstDropdown.locator('.dropdown-option');

    // The checkbox itself is visually hidden inside a switch label; a reader
    // clicks the slider, and so does this.
    this.selectionModeToggle = page.locator('label.switch:has(#toggleSelectionMode)');
    this.nothingSelected = page.locator('#nothing-selected');
    this.mergeButton = page.locator('#MergeSelectionMode');
    this.mergeBlock = page.locator('#MergeOptionsBlock');
    this.mergeChoices = page.locator('#MergeOptionsChoices');
    this.mergeConfirm = page.locator('.ConfirmMergeButton');
    this.mergeCancel = page.locator('#CancelMerge');
  }

  async openFirstDropdown() {
    await this.optionsToggles.first().click();
    await this.firstDropdown.waitFor({ state: 'visible' });
  }

  /** Selection mode on, then the given number of tags picked. */
  async selectTags(count) {
    await this.selectionModeToggle.click();
    for (let i = 0; i < count; i++) {
      await this.tagNames.nth(i).click();
    }
  }

  async goto() {
    await this.page.goto('/admin.php?page=tags');
    await this.tagNames.first().waitFor({ state: 'visible' });
  }

  async openRenamePopin() {
    await this.optionsToggles.first().click();
    await this.editOptions.first().click();
    await this.renameSubmit.waitFor({ state: 'visible' });
  }
}

/** The upload screen, `admin.php?page=photos_add`. */
class UploadPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
    this.form = page.locator('#uploadForm');
    this.selectedAlbum = page.locator('#selectedAlbum');
    this.optionsToggle = page.locator('#uploadOptions');
    this.optionsContent = page.locator('#uploadOptionsContent');
    this.updateModeSwitch = page.locator('#uploadOptionsContent label.switch:has(#toggleUpdateMode)');
    this.allowedTypes = page.locator('#uploadWarningsSummary');
  }

  async goto(albumId) {
    await this.page.goto(`/admin.php?page=photos_add&album=${albumId}`);
    await this.form.waitFor({ state: 'visible' });
  }
}

/**
 * The Batch Manager's action panel, `admin.php?page=batch_manager`.
 *
 * The plugin's own BatchManagerPage drives the move prompt; this one covers the
 * associate action, which is core's and which the handbook documents as the way
 * to put a photo in a second album without taking it out of the first.
 */
class BatchManagerActionPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
    this.thumbnails = page.locator('.thumbnails li');
    this.selectAll = page.locator('#selectAll');
    this.actionSelect = page.locator('select[name="selectAction"]');
    this.associateBlock = page.locator('#action_associate');
    this.associateAlbumButton = page.locator('#associate_as');
    this.applyAction = page.locator('#applyAction');
  }

  async goto() {
    await this.page.goto('/admin.php?page=batch_manager');
    await this.thumbnails.first().waitFor({ state: 'visible' });
  }

  async chooseAssociate() {
    await this.selectAll.click();
    await this.actionSelect.selectOption('associate');
    await this.associateBlock.waitFor({ state: 'visible' });
  }
}

/**
 * The text an element carries before the screen's JavaScript can act on it.
 *
 * The oracle for "nothing rewrote this label". Read rather than typed, so the
 * German exists in one place - the language file - where the integration suite
 * already checks the wording.
 *
 * @param {import('@playwright/test').Locator} locator
 */
async function serverText(locator) {
  return (await locator.textContent() || '').trim();
}

module.exports = { AlbumSettingsPage, AlbumListPage, TagListPage, UploadPage, BatchManagerActionPage, serverText };
