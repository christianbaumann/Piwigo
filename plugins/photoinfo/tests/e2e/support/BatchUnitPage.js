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
  async open(albumId) {
    await this.page.goto(`/admin.php?page=batch_manager&mode=unit&filter=album-${albumId}`);
    await this.page.waitForLoadState('domcontentloaded');
  }

  /** @param {number} photoId */
  fieldset(photoId) {
    return this.page.locator(`#picture-${photoId}`);
  }

  /** Types into the Info field. */
  async setInfo(photoId, text) {
    await this.fieldset(photoId).locator('#description').fill(text);
  }

  /**
   * Sets the creation date. The visible field is a read-only datepicker, so the
   * hidden value it fills is set directly; the edit in the name field is what
   * marks the photo as changed, as a user's keystroke would.
   */
  async setDate(photoId, isoDay) {
    const fieldset = this.fieldset(photoId);
    await fieldset.locator('#date_creation').evaluate((el, value) => { /** @type {HTMLInputElement} */ (el).value = value; }, isoDay);
    await fieldset.locator('#name').fill('E2E unit mode');
  }

  /** Types a new tag name into the tag field and commits it. */
  async addNewTag(photoId, name) {
    const input = this.fieldset(photoId).locator('#action_add_tags .selectize-input input');
    await input.pressSequentially(name);
    await input.press('Enter');
    await this.tagChip(photoId, name).waitFor();
  }

  tagChip(photoId, tagName) {
    return this.fieldset(photoId).locator('#action_add_tags .item').filter({ hasText: tagName });
  }

  async save(photoId) {
    await this.fieldset(photoId).locator('.action-save-picture').click();
  }

  errorBadge(photoId) {
    return this.fieldset(photoId).locator('.local-error-badge');
  }

  /** The reason shown inside the error badge. */
  errorMessage(photoId) {
    return this.fieldset(photoId).locator('.local-error-badge .local-error-message');
  }

  successBadge(photoId) {
    return this.fieldset(photoId).locator('.local-success-badge');
  }
}

module.exports = { BatchUnitPage };
