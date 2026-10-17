// @ts-check

/**
 * Page object for an album's properties page, admin.php?page=album-N-properties.
 *
 * Every locator the specs use lives here; a locator in a spec file is a bug.
 */
class AlbumPropertiesPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
    // Core's "In Galerie öffnen" link in the page's footer.
    this.openInGalleryLink = page.locator('a.cat-modify-footer-see-out');
    this.deleteLink = page.locator('a.deleteAlbum');
    this.confirmDialog = page.locator('.jconfirm-box');
    this.confirmDeleteButton = page.locator('.jconfirm-box .btn-red');
    // The footer line a failed save or a refused delete is shown in.
    this.errorLine = page.locator('.cat-modify-footer .info-error');
  }

  /** @param {number} albumId */
  async goto(albumId) {
    await this.page.goto(`/admin.php?page=album-${albumId}-properties`);
    await this.page.waitForLoadState('load');
  }

  /** Opens the delete dialog and confirms it with the preselected "keep photos" choice. */
  async deleteKeepingPhotos() {
    await this.deleteLink.click();
    await this.confirmDeleteButton.waitFor({ state: 'visible' });
    await this.confirmDeleteButton.click();
  }

  /** Follows "In Galerie öffnen" to the album's gallery page. */
  async openInGallery() {
    await this.openInGalleryLink.click();
    await this.page.waitForLoadState('load');
  }
}

module.exports = { AlbumPropertiesPage };
