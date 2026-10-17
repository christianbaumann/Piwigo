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
  }

  /** @param {number} albumId */
  async goto(albumId) {
    await this.page.goto(`/admin.php?page=album-${albumId}-properties`);
    await this.page.waitForLoadState('load');
  }

  /** Follows "In Galerie öffnen" to the album's gallery page. */
  async openInGallery() {
    await this.openInGalleryLink.click();
    await this.page.waitForLoadState('load');
  }
}

module.exports = { AlbumPropertiesPage };
