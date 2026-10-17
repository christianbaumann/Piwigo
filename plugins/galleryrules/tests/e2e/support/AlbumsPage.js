// @ts-check

/**
 * Page object for Alben > Verwaltung, admin.php?page=albums, and its create dialog.
 *
 * Every locator the specs use lives here; a locator in a spec file is a bug.
 */
class AlbumsPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
    this.addAlbumButton = page.locator('.add-album-button');
    this.dialog = page.locator('#AddAlbum');
    this.nameField = page.locator('#AddAlbum .AddAlbumInputContainer input.user-property-input');
    this.saveButton = page.locator('#AddAlbum .AddAlbumSubmit');
    // galleryrules' hint next to the name field.
    this.nameMissingHint = page.locator('#AddAlbum .galleryrules-album-name-missing');
    this.deleteDialog = page.locator('#DeleteAlbum');
    this.confirmDeleteButton = page.locator('#DeleteAlbum .DeleteAlbumSubmit');
    this.cancelDeleteButton = page.locator('#DeleteAlbum .DeleteAlbumCancel');
    this.deleteError = page.locator('#DeleteAlbum .DeleteAlbumErrors');
  }

  async goto() {
    await this.page.goto('/admin.php?page=albums');
    await this.page.waitForLoadState('load');
  }

  async openCreateDialog() {
    await this.addAlbumButton.click();
    await this.dialog.waitFor({ state: 'visible' });
  }

  /** @param {string} name */
  async typeName(name) {
    await this.nameField.fill(name);
  }

  async save() {
    await this.saveButton.click();
  }

  async saveWithEnter() {
    await this.nameField.press('Enter');
  }

  /**
   * Opens the delete dialog of an album from its row in the tree.
   *
   * @param {number} albumId
   */
  async openDeleteDialog(albumId) {
    await this.page.locator(`.tree .move-cat-delete[data-id="${albumId}"]`).click();
    await this.deleteDialog.waitFor({ state: 'visible' });
  }

  /**
   * Collects every pwg.categories.add request the page sends from now on.
   *
   * @returns {string[]} the request URLs, filled as they are sent
   */
  watchAddRequests() {
    const sent = [];
    this.page.on('request', (request) => {
      if (request.url().includes('method=pwg.categories.add')) {
        sent.push(request.url());
      }
    });
    return sent;
  }

  /**
   * The album core put in the tree after a save.
   *
   * @param {string} name
   */
  treeAlbum(name) {
    return this.page.locator('.tree .move-cat-title', { hasText: name });
  }
}

module.exports = { AlbumsPage };
