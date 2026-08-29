// @ts-check

/**
 * Page object for admin.php?page=batch_manager&mode=global, with the provenance
 * move-mode radios injected into core's move panel.
 *
 * Every locator in the E2E suite lives here. Specs orchestrate and assert; a
 * locator appearing in a spec file is the first step toward a suite nobody can
 * maintain.
 *
 * Selector policy: the plugin's own class names for what the plugin emits, and
 * core's stable ids (#action_move, #applyAction, #selectAll, select[name=...])
 * for what core emits - never a position inside theme-generated markup.
 *
 * The destination album is chosen through selectize, which replaces core's
 * <select name="move"> with its own markup. There is no way around driving it:
 * the underlying select carries no options at all until selectize fetches them,
 * so setting its value directly submits an empty move.
 */
class BatchManagerPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;

    this.actionSelect = page.locator('select[name=selectAction]');
    this.movePanel = page.locator('#action_move');
    this.selectAll = page.locator('#selectAll');
    this.applyButton = page.locator('#applyAction');
    this.thumbnailCheckboxes = page.locator('.thumbnails input[type=checkbox]');

    /** The injected block, and the title that says what the radios are for. */
    this.moveModeBlock = page.locator('.provenance-move-mode');
    this.moveModeTitle = page.locator('.provenance-move-mode-title');
    /** Every injected radio, in document order. */
    this.moveModeRadios = page.locator('.provenance-move-mode input[type=radio]');

    /** selectize's visible replacement for <select name="move">. */
    this.destinationInput = page.locator('#action_move .selectize-input input');
    this.destinationDropdown = page.locator('#action_move .selectize-dropdown');
  }

  /** One move-mode radio by the value it posts. @param {string} mode */
  moveModeRadio(mode) {
    return this.page.locator(`.provenance-move-mode input[type=radio][value="${mode}"]`);
  }

  /**
   * The Batch Manager filtered to one album.
   *
   * The filter is otherwise a POST-only form driven by a second selectize
   * widget; this GET entry point is core's own (admin/cat_modify.php:219) and
   * sets the same session filter.
   *
   * @param {number} albumId
   */
  async goto(albumId) {
    await this.page.goto(`/admin.php?page=batch_manager&filter=album-${albumId}`);
    // The thumbnails are the only part of the page that is unconditionally
    // there. Core hides the whole action fieldset while nothing is selected, so
    // waiting on the action select here would wait forever.
    await this.selectAll.waitFor({ state: 'visible' });
  }

  /**
   * Selects every photo the filter left on the page.
   *
   * Core reveals the action fieldset only once something is selected, so this
   * is also what makes chooseAction() reachable.
   */
  async selectAllPhotos() {
    await this.selectAll.click();
    await this.actionSelect.waitFor({ state: 'visible' });
  }

  /** Opens one action's panel. @param {string} action */
  async chooseAction(action) {
    await this.actionSelect.selectOption(action);
  }

  /**
   * Picks the destination album by name through selectize.
   *
   * @param {string} albumName
   */
  async chooseDestination(albumName) {
    await this.destinationInput.click();
    await this.destinationInput.fill(albumName);
    const option = this.destinationDropdown.locator('.option', { hasText: albumName });
    await option.first().click();
  }

  /** Submits the action and waits for the reloaded page. */
  async apply() {
    await Promise.all([
      this.page.waitForLoadState('networkidle'),
      this.applyButton.click(),
    ]);
  }
}

module.exports = { BatchManagerPage };
