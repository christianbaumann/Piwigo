// @ts-check

/**
 * Page object for the read-only provenance page,
 * index.php?/provenance/album|photo/<id>.
 */
class ProvenancePage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;

    this.root = page.locator('#provenancePage');
    this.values = page.locator('#provenancePageValues');
    /** @param {string} field a provenance column name */
    this.value = (field) => this.values.locator(`dd[data-field="${field}"]`);
    /** Anything a visitor could change something with. */
    this.controls = this.root.locator('form, input, textarea, select, button');
  }
}

module.exports = { ProvenancePage };
