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
    this.dateQualifier = page.locator('#photoinfo-date-form select[name=qualifier]');
    this.dateYear = page.locator('#photoinfo-date-form input[name=year]');
    this.dateMonth = page.locator('#photoinfo-date-form select[name=month]');
    this.dateDay = page.locator('#photoinfo-date-form select[name=day]');
    this.dateDayOptions = page.locator('#photoinfo-date-form select[name=day] option:not([value=""])');
    this.dateEnd = page.locator('#photoinfo-date-form .photoinfo-date-end');
    this.dateEndYear = page.locator('#photoinfo-date-form input[name=end_year]');
    this.dateEndMonth = page.locator('#photoinfo-date-form select[name=end_month]');
    this.dateEndDay = page.locator('#photoinfo-date-form select[name=end_day]');
    this.dateSaveButton = page.locator('#photoinfo-date-form input[type=submit]');
    this.dateCancelButton = page.locator('#photoinfo-date-form .photoinfo-cancel');
    this.dateMessage = page.locator('#photoinfo-date-form .photoinfo-message');
    this.coreDateRow = page.locator('#datecreate');

    // typetags' field for a new tag, and the Tags row its answer lands in
    this.newTagField = page.locator('#typetags-new-tag input[name="tag_name"]');
    this.newTagButton = page.locator('#typetags-new-tag button[type="submit"]');
  }

  /** @param {number} tagId */
  tagLink(tagId) {
    return this.page.locator(`#Tags dd a[data-tag-id="${tagId}"]`);
  }

  /**
   * The emoji in front of a tag's name in the Tags row. Core links a tag by its
   * URL there, typetags' script by data-tag-id, so the name finds both.
   *
   * @param {string} name
   */
  tagEmojiNamed(name) {
    return this.page.locator('#Tags dd a', { hasText: name }).locator('.typetag-emoji');
  }

  /**
   * The background colour the browser paints a tag's badge in.
   *
   * @param {string} name
   */
  async tagBadgeColorNamed(name) {
    return this.page
      .locator('#Tags dd a', { hasText: name })
      .locator('span[style]')
      .first()
      .evaluate((span) => window.getComputedStyle(span).backgroundColor);
  }

  /**
   * Types a name into typetags' field and waits for the server's answer.
   *
   * @param {string} name
   */
  async addNewTag(name) {
    await this.newTagField.fill(name);
    const answer = this.page.waitForResponse(
      (r) => r.url().includes('ws.php') && (r.request().postData() || '').includes('typetags.image.addNewTag')
    );
    await this.newTagButton.click();
    await answer;
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

  /**
   * Chooses a qualifier and fills the range end, leaving a part out when it is ''.
   *
   * @param {string} qualifier circa, before, after, between, or '' for exact
   * @param {{year: string, month: string, day: string}} [end] only with between
   */
  async qualifyDate(qualifier, end) {
    await this.dateQualifier.selectOption(qualifier);
    if (end) {
      await this.dateEndYear.fill(end.year);
      if (end.month !== '') {
        await this.dateEndMonth.selectOption(end.month);
      }
      if (end.day !== '') {
        await this.dateEndDay.selectOption(end.day);
      }
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
