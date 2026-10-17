// @ts-check

/**
 * Page object for core's tag management screen (Schlagwortverwaltung).
 *
 * Every locator the specs need lives here; a locator in a spec file is a bug.
 */
class AdminTagsPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;

    this.search = page.locator('#search-tag .search-input');
    /** The screen's error line, which tags.js fills with a refused call's message. */
    this.error = page.locator('.info-error p');
    /** The screen's success line. */
    this.message = page.locator('.info-message p');
    /** The confirm dialog's red "yes, delete" button. */
    this.confirmDelete = page.locator('.jconfirm .btn-red');
  }

  static url() {
    return '/admin.php?page=tags';
  }

  async open() {
    await this.page.goto(AdminTagsPage.url());
    await this.page.waitForLoadState('domcontentloaded');
  }

  /** @param {number} tagId */
  box(tagId) {
    return this.page.locator(`.tag-box[data-id="${tagId}"]`);
  }

  /**
   * Deletes one tag through its menu, as a user does: search it, open the
   * menu, choose Delete and confirm.
   *
   * @param {number} tagId
   * @param {string} name
   */
  async deleteTag(tagId, name) {
    await this.search.fill(name);
    const box = this.box(tagId);
    await box.locator('.showOptions').click();
    await box.locator('.dropdown-option.delete').click();
    await this.confirmDelete.click();
  }
}

module.exports = { AdminTagsPage };
