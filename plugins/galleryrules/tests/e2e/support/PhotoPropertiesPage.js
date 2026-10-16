// @ts-check

/**
 * Page object for core's photo properties page (admin.php?page=photo-<id>-properties).
 *
 * Every locator the specs use lives here; a locator in a spec file is a bug.
 */
class PhotoPropertiesPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
    this.errors = page.locator('.errors li');
    this.linkedAlbums = page.locator('.related-categories-container .remove-item');
    this.saveButton = page.locator('#pictureModify button[name=submit]');
  }

  /** @param {number} imageId */
  async goto(imageId) {
    await this.page.goto(`/admin.php?page=photo-${imageId}-properties`, { waitUntil: 'domcontentloaded' });
    await this.saveButton.waitFor();
  }

  /**
   * Removes one album from the photo's linked albums, as its x does.
   *
   * @param {number} albumId
   */
  async removeAlbum(albumId) {
    await this.page.locator(`.related-categories-container .remove-item[id="${albumId}"]`).click();
  }

  async save() {
    await Promise.all([
      this.page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      this.saveButton.click(),
    ]);
  }
}

module.exports = { PhotoPropertiesPage };
