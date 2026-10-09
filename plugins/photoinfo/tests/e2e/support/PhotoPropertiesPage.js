// @ts-check

/**
 * Page object for core's photo properties screen in the administration.
 *
 * Every locator the specs use lives here; a locator in a spec file is a bug.
 */
class PhotoPropertiesPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
    // The tag field is a selectize control built over select[name="tags[]"]
    this.tagInput = page.locator('select[name="tags[]"] + .selectize-control .selectize-input input');
    this.tagCreateOption = page.locator('select[name="tags[]"] + .selectize-control .selectize-dropdown .create');
    this.saveButton = page.locator('button[type="submit"][name="submit"]');
  }

  /** @param {number} imageId */
  async goto(imageId) {
    await this.page.goto(`/admin.php?page=photo-${imageId}-properties`);
  }

  /**
   * Types a name the field does not know and picks selectize's offer to
   * create it. Typed key by key: selectize builds the offer on key events,
   * which fill() does not send.
   *
   * @param {string} name
   */
  async typeNewTag(name) {
    await this.tagInput.click();
    await this.tagInput.pressSequentially(name);
    await this.tagCreateOption.waitFor({ state: 'visible' });
    await this.tagInput.press('Enter');
  }

  /** Saves and waits for the screen the POST answers with. */
  async save() {
    const answer = this.page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('page=photo-'));
    await this.saveButton.click();
    await answer;
    await this.page.waitForLoadState('domcontentloaded');
  }
}

module.exports = { PhotoPropertiesPage };
