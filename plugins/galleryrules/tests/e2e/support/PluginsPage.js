// @ts-check

/**
 * Page object for core's Administration > Plugins screen.
 *
 * Every locator the specs use lives here; a locator in a spec file is a bug.
 */
class PluginsPage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;
  }

  async goto() {
    await this.page.goto('/admin.php?page=plugins');
  }

  /**
   * The box core renders for one plugin; core gives it the plugin's id.
   *
   * @param {string} pluginId
   */
  box(pluginId) {
    return this.page.locator(`#${pluginId}.pluginBox`);
  }

  /**
   * The on/off switch inside one plugin's box.
   *
   * @param {string} pluginId
   */
  activeSwitch(pluginId) {
    return this.box(pluginId).locator('input#toggleSelectionMode');
  }
}

module.exports = { PluginsPage };
