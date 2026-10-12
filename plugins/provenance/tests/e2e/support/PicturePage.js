// @ts-check

/**
 * Page object for the public photo page, picture.php, with the provenance row
 * injected into its information list.
 *
 * Every locator the public specs use lives here; a locator in a spec file is a
 * bug.
 */
class PicturePage {
  /** Frames the photo's box must hold still before it counts as settled. */
  static SETTLE_FRAMES = 12;

  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;

    /** The information list core renders; the row is injected into it. */
    this.infoList = page.locator('#standard');
    /** The injected row, addressed through the list so its placement is asserted too. */
    this.row = page.locator('#standard #Provenance');
    this.label = this.row.locator('dt');
    this.value = this.row.locator('dd');
    /** The row's link to the read-only provenance page. */
    this.pageLink = this.row.locator('#provenancePageLink');
    /** The row wherever it may be, for the specs that assert it is nowhere. */
    this.rowAnywhere = page.locator('#Provenance');

    /** The photo, and the modus theme's zoom control (themes/modus/js/photo.zoom.js). */
    this.image = page.locator('#theMainImage');
    this.zoomInButton = page.locator('#zoomIn');
    /** Core's login form, where a guest is sent instead of the photo page. */
    this.loginForm = page.locator('input[name="username"]');
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
   * Waits until the photo file has loaded and its rendered box has held still
   * for SETTLE_FRAMES animation frames. The theme first shows its loading GIF,
   * which is complete at once, so a src equal to the loader's is rejected.
   * The same wait as persons' PicturePage.settleImage().
   */
  async settleImage() {
    await this.page.evaluate(() => {
      window.__pictureSettle = { key: null, frames: 0 };
    });
    await this.page.waitForFunction(
      (needed) => {
        const state = window.__pictureSettle;
        const image = document.getElementById('theMainImage');
        if (!state || !image || !image.complete || image.naturalWidth < 2) {
          return false;
        }
        const loader = document.querySelector('.img-loader-derivatives');
        if (loader && image.currentSrc === loader.currentSrc) {
          return false;
        }
        const r = image.getBoundingClientRect();
        const key = [image.currentSrc, r.left, r.top, r.width, r.height].join('|');
        if (r.width < 2 || state.key !== key) {
          state.key = key;
          state.frames = 0;
          return false;
        }
        state.frames += 1;
        return state.frames >= needed;
      },
      PicturePage.SETTLE_FRAMES,
      { polling: 'raf' }
    );
  }

  /** The photo's rendered width, in CSS pixels. */
  async imageWidth() {
    return this.image.evaluate((el) => el.getBoundingClientRect().width);
  }

  /** One zoom step in, through the theme's button. */
  async zoomIn() {
    await this.zoomInButton.click();
    await this.settleImage();
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
