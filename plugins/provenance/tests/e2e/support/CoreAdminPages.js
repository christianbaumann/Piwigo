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

module.exports = { AlbumSettingsPage, AlbumListPage, TagListPage, serverText };
