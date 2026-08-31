// @ts-check

/**
 * Page object for the persons list in the admin panel.
 *
 * The screen an administrator uses to see who is in the index, rename or delete
 * them, and rebuild the whole index from the image files. Every locator the
 * specs need lives here; a locator in a spec file is a bug.
 */
class AdminPersonsPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;

    this.table = page.locator('#persons-table');
    this.rows = page.locator('#persons-table tbody tr');
    this.summary = page.locator('#persons-admin-summary');

    this.search = page.locator('#persons-search-q');

    this.rescanButton = page.locator('#persons-rescan');
    /* The run publishes its counters on the element as attributes as well as
       painting a bar, so a spec reads what the run really covered instead of
       measuring an animation mid-transition. */
    this.rescanProgress = page.locator('#persons-rescan-progress');
    this.rescanMessage = page.locator('#persons-rescan-message');
  }

  static url() {
    return '/admin.php?page=plugin-persons';
  }

  /** @returns {Promise<import('@playwright/test').Response|null>} */
  async open() {
    const response = await this.page.goto(AdminPersonsPage.url());
    await this.page.waitForLoadState('domcontentloaded');
    return response;
  }

  /** The row for one person, found by the name it carries as an attribute. */
  row(name) {
    return this.page.locator(`#persons-table tbody tr[data-person-name="${name}"]`);
  }

  /**
   * One person's counts as the screen states them.
   *
   * @param {string} name
   * @returns {Promise<{photos: number, regions: number}>}
   */
  async counts(name) {
    const row = this.row(name);

    return {
      photos: Number(await row.getAttribute('data-person-photos')),
      regions: Number(await row.getAttribute('data-person-regions')),
    };
  }

  /**
   * Runs the rescan and waits for it to report every photo covered.
   *
   * Waits on data-done reaching data-total rather than on the button losing its
   * busy class: the counters are the run's own account of what it covered, and
   * the class is only how the button looks.
   *
   * @param {number} timeout milliseconds; a rescan shells out once per photo
   */
  async runRescan(timeout) {
    await this.rescanButton.click();

    await this.page.waitForFunction(
      () => {
        const box = document.getElementById('persons-rescan-progress');
        if (!box) {
          return false;
        }
        const total = Number(box.getAttribute('data-total'));
        return total > 0 && Number(box.getAttribute('data-done')) === total;
      },
      undefined,
      { timeout }
    );
  }
}

module.exports = { AdminPersonsPage };
