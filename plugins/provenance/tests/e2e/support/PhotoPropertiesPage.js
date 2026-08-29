// @ts-check

/**
 * Page object for admin.php?page=photo-N-properties with the provenance block
 * injected into it.
 *
 * Separate from AlbumPropertiesPage because it is a different screen with
 * different ids, not a variation of the same one. Every locator the photo specs
 * use lives here; a locator in a spec file is a bug.
 */
class PhotoPropertiesPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;

    this.block = page.locator('#provenance-photo');
    this.inherited = page.locator('#provenance-photo .provenance-inherited');
    this.note = page.locator('#provenance-photo-note');
    this.saveButton = page.locator('#provenance-photo-save');
    this.message = page.locator('#provenance-photo-message');
  }

  /** @param {number} imageId */
  async goto(imageId) {
    await this.page.goto(`/admin.php?page=photo-${imageId}-properties`);
    await this.page.waitForLoadState('domcontentloaded');
  }
}

module.exports = { PhotoPropertiesPage };
