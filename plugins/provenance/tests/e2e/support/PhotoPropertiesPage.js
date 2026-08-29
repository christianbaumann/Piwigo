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
