// @ts-check

/**
 * Page object for admin.php?page=photo-N-properties with the provenance block
 * injected into it.
 *
 * Separate from AlbumPropertiesPage because it is a different screen with
 * different ids, not a variation of the same one. Every locator the photo specs
 * use lives here; a locator in a spec file is a bug.
 */
class PhotoPropertiesPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;

    this.block = page.locator('#provenance-photo');
    this.inherited = page.locator('#provenance-photo .provenance-inherited');
    /** The four read-only album-sourced facts, one locator per fact. */
    this.inheritedItems = page.locator('#provenance-photo .provenance-inherited > span');
    /** Anything editable inside the read-only half - there must be none. */
    this.inheritedControls = page.locator(
      '#provenance-photo .provenance-inherited input, ' +
      '#provenance-photo .provenance-inherited textarea, ' +
      '#provenance-photo .provenance-inherited select, ' +
      '#provenance-photo .provenance-inherited [contenteditable]'
    );
    this.note = page.locator('#provenance-photo-note');
    this.saveButton = page.locator('#provenance-photo-save');
    this.message = page.locator('#provenance-photo-message');
    this.heading = page.locator('#provenance-photo-heading');
    /** Core's own save button in the fixed save bar of the photo form. */
    this.coreSaveButton = page.locator('#pictureModify button[name="submit"]');
    this.description = page.locator('#pictureModify #description');
    /** The block when core's photo form contains it - there must be none. */
    this.blockInsideCoreForm = page.locator('#pictureModify #provenance-photo');
  }

  /**
   * Counts the beforeunload dialogs the page raises; each is dismissed so
   * leaving proceeds.
   * @returns {() => number}
   */
  collectBeforeUnloadDialogs() {
    let count = 0;
    this.page.on('dialog', async (dialog) => {
      if (dialog.type() === 'beforeunload') {
        count++;
      }
      await dialog.accept();
    });
    return () => count;
  }

  /** Navigates away, which raises beforeunload when the form counts unsaved edits. */
  async leave() {
    await this.page.goto('/admin.php?page=intro');
    await this.page.waitForLoadState('domcontentloaded');
  }

  /** @param {number} imageId */
  async goto(imageId) {
    await this.page.goto(`/admin.php?page=photo-${imageId}-properties`);
    // The save is wired by the injected footer script, so nothing a spec clicks
    // exists until that script has run.
    await this.page.waitForLoadState('domcontentloaded');
  }

  async reload() {
    await this.page.reload();
    await this.page.waitForLoadState('domcontentloaded');
  }

  /** The vertical position of each read-only fact, top first. */
  async inheritedRowTops() {
    const boxes = await this.inheritedItems.evaluateAll((nodes) =>
      nodes.map((n) => n.getBoundingClientRect().top)
    );
    return boxes;
  }
}

module.exports = { PhotoPropertiesPage };
