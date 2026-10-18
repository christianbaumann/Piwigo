// @ts-check

/**
 * Page object for the Batch Manager's unit mode, `admin.php?page=batch_manager&mode=unit`.
 *
 * Every locator the specs need lives here; a locator in a spec file is a bug.
 */
class BatchUnitPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
  }

  /** @param {number} albumId */
  static url(albumId) {
    return `/admin.php?page=batch_manager&mode=unit&filter=album-${albumId}`;
  }

  /** @param {number} albumId */
  async open(albumId) {
    await this.page.goto(BatchUnitPage.url(albumId));
    await this.page.waitForLoadState('domcontentloaded');
  }

  /** @param {number} photoId */
  fieldset(photoId) {
    return this.page.locator(`#picture-${photoId}`);
  }

  /** The selectize chip of one tag, by name in the photo's tag field. */
  tagChip(photoId, tagName) {
    return this.fieldset(photoId).locator('#action_add_tags .item').filter({ hasText: tagName });
  }

  /** Takes a tag off the photo's field, without saving. */
  async removeTag(photoId, tagName) {
    await this.tagChip(photoId, tagName).locator('.remove').click();
  }

  async save(photoId) {
    await this.fieldset(photoId).locator('.action-save-picture').click();
  }

  errorBadge(photoId) {
    return this.fieldset(photoId).locator('.local-error-badge');
  }

  successBadge(photoId) {
    return this.fieldset(photoId).locator('.local-success-badge');
  }
}

module.exports = { BatchUnitPage };
