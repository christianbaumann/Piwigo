// @ts-check

/**
 * Page object for admin.php?page=album-N-properties with the provenance block
 * injected into it.
 *
 * Every locator in the E2E suite lives here. Specs orchestrate and assert; a
 * locator appearing in a spec file is the first step toward a suite nobody can
 * maintain, because the same selector then has to be corrected in N places.
 *
 * Selector policy: locate by the ids the plugin emits on purpose
 * (#provenance-open, #provenance-modal, #provenance-save, the four field ids)
 * plus the one core id the layout check is about (#cat-properties-save) - never
 * by position within theme-generated markup.
 */
class AlbumPropertiesPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;

    this.openButton = page.locator('#provenance-open');
    this.modal = page.locator('#provenance-modal');
    this.saveButton = page.locator('#provenance-save');
    this.closeButton = page.locator('#provenance-modal-close');
    this.message = page.locator('#provenance-message');

    this.physicalAlbum = page.locator('#provenance-physical-album');
    this.owner = page.locator('#provenance-owner');
    this.scannedOn = page.locator('#provenance-scanned-on');
    this.note = page.locator('#provenance-note');

    /** The album screen's own save button, which the block is injected next to. */
    this.albumSaveButton = page.locator('#cat-properties-save');
  }

  /** @param {number} albumId */
  async goto(albumId) {
    await this.page.goto(`/admin.php?page=album-${albumId}-properties`);
    // The modal is opened by the injected footer script, so nothing a spec
    // clicks exists until that script has run.
    await this.page.waitForLoadState('domcontentloaded');
  }

  async reload() {
    await this.page.reload();
    await this.page.waitForLoadState('domcontentloaded');
  }

  /** The four fields as the browser currently holds them. */
  async fieldValues() {
    return {
      provenance_physical_album: await this.physicalAlbum.inputValue(),
      provenance_owner: await this.owner.inputValue(),
      provenance_scanned_on: await this.scannedOn.inputValue(),
      provenance_note: await this.note.inputValue(),
    };
  }

  /** @param {{provenance_physical_album: string, provenance_owner: string, provenance_scanned_on: string, provenance_note: string}} values */
  async fill(values) {
    await this.physicalAlbum.fill(values.provenance_physical_album);
    await this.owner.fill(values.provenance_owner);
    await this.scannedOn.fill(values.provenance_scanned_on);
    await this.note.fill(values.provenance_note);
  }
}

module.exports = { AlbumPropertiesPage };
