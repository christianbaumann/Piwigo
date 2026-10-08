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

    this.dateRow = page.locator('#PhotoDate');
    this.dateText = page.locator('#PhotoDate dd');
    this.dateView = page.locator('#photoinfo-date-view');
    this.datePlaceholder = page.locator('#photoinfo-date-view .photoinfo-placeholder');
    this.dateForm = page.locator('#photoinfo-date-form');
    this.dateYear = page.locator('#photoinfo-date-form input[name=year]');
    this.dateMonth = page.locator('#photoinfo-date-form select[name=month]');
    this.dateDay = page.locator('#photoinfo-date-form select[name=day]');
    this.dateDayOptions = page.locator('#photoinfo-date-form select[name=day] option:not([value=""])');
    this.dateSaveButton = page.locator('#photoinfo-date-form input[type=submit]');
    this.dateCancelButton = page.locator('#photoinfo-date-form .photoinfo-cancel');
    this.dateMessage = page.locator('#photoinfo-date-form .photoinfo-message');
    this.coreDateRow = page.locator('#datecreate');
  }

  /** @param {string} picturePath as seed.php prints it */
  async goto(picturePath) {
    await this.page.goto(picturePath);
  }

  /**
   * Fills the date controls, leaving a part out when it is ''.
   *
   * @param {string} year
   * @param {string} month 1 to 12, or '' for unknown
   * @param {string} day or '' for unknown
   */
  async fillDate(year, month, day) {
    await this.dateYear.fill(year);
    if (month !== '') {
      await this.dateMonth.selectOption(month);
    }
    if (day !== '') {
      await this.dateDay.selectOption(day);
    }
  }

  /** Clicks the date's Save and waits for the page the save reloads. */
  async saveDateAndWaitForReload() {
    await Promise.all([
      this.page.waitForEvent('load'),
      this.dateSaveButton.click(),
    ]);
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
