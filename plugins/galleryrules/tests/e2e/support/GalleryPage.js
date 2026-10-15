// @ts-check

/**
 * Page object for the gallery's visitor pages: the picture page and an album page
 * in the modus theme.
 *
 * Every locator the specs use lives here; a locator in a spec file is a bug.
 */
class GalleryPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
  }

  /** @param {string} path */
  async goto(path) {
    await this.page.goto(path);
    await this.page.waitForLoadState('load');
  }

  /**
   * What a payload's onerror wrote on the body, or undefined when none ran.
   *
   * @param {string} marker the data attribute's name
   */
  async ranPayload(marker) {
    return this.page.evaluate((name) => document.body.dataset[name], marker);
  }

  /** The visible text of the page's content area. */
  content() {
    return this.page.locator('#content');
  }

  /** The photo's Autor on the picture page. */
  author() {
    return this.page.locator('#Author dd');
  }

  /** The album description above an album's thumbnails. */
  albumDescription() {
    return this.page.locator('.additional_info');
  }
}

module.exports = { GalleryPage };
