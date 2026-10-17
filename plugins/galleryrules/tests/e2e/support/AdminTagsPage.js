// @ts-check

/**
 * Page object for core's tag management screen (Schlagwortverwaltung), admin.php?page=tags.
 *
 * Every locator the specs use lives here; a locator in a spec file is a bug.
 */
class AdminTagsPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
    this.search = page.locator('#search-tag .search-input');
    this.allBoxes = page.locator('.tag-box');
    /** The screen's error line, which tags.js fills through showError(). */
    this.error = page.locator('.info-error p');
    this.renameDialog = page.locator('#RenameTag');
    this.renameField = page.locator('#RenameTag .tag-property-input');
    this.renameSubmit = page.locator('#RenameTag .TagSubmit');
    this.confirmDelete = page.locator('.jconfirm .btn-red');
    /** The warning's "Überprüfung" link, which opens the list of unused tags. */
    this.reviewLink = page.locator('.info-warning p a');
    this.reviewList = page.locator('.jconfirm-content');
    /** Messages the page's scripts throw as uncaught errors. */
    this.scriptErrors = /** @type {string[]} */ ([]);
    page.on('pageerror', (error) => this.scriptErrors.push(error.message));
  }

  async open() {
    await this.page.goto('/admin.php?page=tags');
    await this.page.waitForLoadState('load');
  }

  /** The lines of the unused-tag list in the open confirm dialog. */
  async reviewLines() {
    const text = await this.reviewList.innerText();
    return text.split('\n').map((line) => line.trim()).filter((line) => line !== '');
  }

  /** @param {string} name */
  box(name) {
    return this.page.locator('.tag-box', { has: this.page.locator('.tag-name', { hasText: name }) });
  }

  /** @param {string} name */
  async find(name) {
    await this.search.fill(name);
  }

  /**
   * The photo count in the tag's menu header ("1 Foto").
   *
   * @param {string} name
   */
  async menuCount(name) {
    const box = this.box(name).first();
    await box.locator('.showOptions').click();
    return box.locator('.tag-dropdown-header i');
  }

  /**
   * Opens the tag's rename dialog, types a new name and submits.
   *
   * @param {string} name
   * @param {string} newName
   */
  async rename(name, newName) {
    const box = this.box(name).first();
    await box.locator('.showOptions').click();
    await box.locator('.dropdown-option.edit').click();
    await this.renameField.fill(newName);
    await this.renameSubmit.click();
  }

  /** @param {string} name */
  async duplicate(name) {
    const box = this.box(name).first();
    await box.locator('.showOptions').click();
    await box.locator('.dropdown-option.duplicate').click();
  }

  /**
   * @param {string} name
   * @param {number} [index] which of the boxes whose name contains `name`
   */
  async delete(name, index = 0) {
    const box = this.box(name).nth(index);
    await box.locator('.showOptions').click();
    await box.locator('.dropdown-option.delete').click();
    await this.confirmDelete.click();
  }
}

module.exports = { AdminTagsPage };
