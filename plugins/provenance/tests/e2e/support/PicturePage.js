// @ts-check

/**
 * Page object for the public photo page, picture.php, with the provenance row
 * injected into its information list.
 *
 * Every locator the public specs use lives here; a locator in a spec file is a
 * bug.
 */
class PicturePage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;

    /** The information list core renders; the row is injected into it. */
    this.infoList = page.locator('#standard');
    /** The injected row, addressed through the list so its placement is asserted too. */
    this.row = page.locator('#standard #Provenance');
    this.label = this.row.locator('dt');
    this.value = this.row.locator('dd');
    /** The row wherever it may be, for the specs that assert it is nowhere. */
    this.rowAnywhere = page.locator('#Provenance');
    /** Rendered only for an authenticated administrator; the guest spec's proof it really is a guest. */
    this.adminLink = page.locator('a[href*="admin.php"]');
  }

  /**
   * @param {number} imageId
   * @param {number} categoryId
   */
  async goto(imageId, categoryId) {
    await this.page.goto(`/picture.php?/${imageId}/category/${categoryId}`);
    await this.page.waitForLoadState('domcontentloaded');
  }

  /**
   * How far the page's content overflows the viewport horizontally, in CSS pixels.
   *
   * The causal fact behind "the row does not break the layout at a narrow
   * width": provenance text is free-form and can hold a long unbroken token,
   * which widens the whole column and pushes the page sideways.
   *
   * Measured on <body>, not on <html> and not on the row: the row's own
   * scrollWidth grows with its content instead of overflowing, and
   * documentElement.scrollWidth is clipped by the theme - both read zero however
   * wide the text gets. Verified 2026-08-29 by widening the value to 400
   * characters: this figure went from 0 to 2959, the other two stayed at 0.
   */
  async horizontalOverflow() {
    return this.page.evaluate(
      () => document.body.scrollWidth - document.body.clientWidth
    );
  }
}

module.exports = { PicturePage };
