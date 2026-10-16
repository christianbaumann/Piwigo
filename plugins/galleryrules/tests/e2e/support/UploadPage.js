// @ts-check

/**
 * Page object for core's Fotos hinzufügen screen (admin.php?page=photos_add).
 *
 * Every locator the specs use lives here; a locator in a spec file is a bug.
 */
class UploadPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
    this.errors = page.locator('.errors li');
    this.uploadedThumbnails = page.locator('#uploadedPhotos img');
    this.startButton = page.locator('#startUpload');
    this.addFilesButton = page.locator('#addFiles');
  }

  /** @param {number} albumId the album the page opens with selected */
  async goto(albumId) {
    await this.page.goto(`/admin.php?page=photos_add&album=${albumId}`, { waitUntil: 'domcontentloaded' });
    await this.addFilesButton.waitFor();
  }

  /** Switches on "update the file if a photo in this album has the same filename". */
  async turnOnReplace() {
    await this.page.locator('#uploadOptions').click();
    await this.page.locator('#uploadOptionsContent label.switch').click();
    await this.page.locator('#toggleUpdateMode').waitFor({ state: 'attached' });
  }

  /** @returns {Promise<boolean>} */
  replaceIsOn() {
    return this.page.locator('#toggleUpdateMode').isChecked();
  }

  /**
   * Adds one file to the queue and starts the upload.
   *
   * @param {{name: string, mimeType: string, buffer: Buffer}} file
   */
  async upload(file) {
    const chooser = this.page.waitForEvent('filechooser');
    await this.addFilesButton.click();
    await (await chooser).setFiles(file);
    await this.startButton.click();
  }
}

module.exports = { UploadPage };
