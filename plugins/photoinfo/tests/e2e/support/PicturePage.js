// @ts-check

/**
 * Page object for the public photo page with the Info row on it.
 *
 * Every locator the specs use lives here; a locator in a spec file is a bug.
 */
class PicturePage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
    this.infoRow = page.locator('#PhotoInfo');
    this.infoText = page.locator('#PhotoInfo dd');
    this.infoView = page.locator('#photoinfo-info-view');
    this.infoForm = page.locator('#photoinfo-info-form');
    this.infoTextarea = page.locator('#photoinfo-info-form textarea');
    this.saveButton = page.locator('#photoinfo-info-form input[type=submit]');
    this.cancelButton = page.locator('#photoinfo-info-form .photoinfo-cancel');
    this.coreDescription = page.locator('.imageComment');
  }

  /** @param {string} picturePath as seed.php prints it */
  async goto(picturePath) {
    await this.page.goto(picturePath);
  }

  /** Clicks Save and waits for the page the save reloads. */
  async saveAndWaitForReload() {
    await Promise.all([
      this.page.waitForEvent('load'),
      this.saveButton.click(),
    ]);
  }
}

module.exports = { PicturePage };
