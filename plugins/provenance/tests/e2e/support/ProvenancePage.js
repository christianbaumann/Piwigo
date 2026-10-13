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
    this.labels = this.values.locator('dt');
  }

  /**
   * WCAG contrast ratio of an element's text against the first opaque
   * background behind it (white when none is set).
   *
   * @param {import('@playwright/test').Locator} locator
   * @returns {Promise<number>}
   */
  async contrast(locator) {
    return locator.evaluate((el) => {
      const rgb = (css) => css.match(/[\d.]+/g).map(Number);
      const luminance = ([r, g, b]) => {
        const [R, G, B] = [r, g, b].map((c) => {
          const s = c / 255;
          return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * R + 0.7152 * G + 0.0722 * B;
      };
      let background = [255, 255, 255];
      for (let node = el; node; node = node.parentElement) {
        const parts = rgb(window.getComputedStyle(node).backgroundColor);
        if (parts.length === 3 || parts[3] === 1) {
          background = parts.slice(0, 3);
          break;
        }
      }
      const text = luminance(rgb(window.getComputedStyle(el).color));
      const back = luminance(background);
      return (Math.max(text, back) + 0.05) / (Math.min(text, back) + 0.05);
    });
  }
}

module.exports = { ProvenancePage };
