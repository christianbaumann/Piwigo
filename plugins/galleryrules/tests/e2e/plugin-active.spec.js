// @ts-check
const { test, expect } = require('@playwright/test');
const { PluginsPage } = require('./support/PluginsPage');

const PLUGIN_ID = 'galleryrules';

// [HAPPY] Smoke: the plugin is installed and switched on where an operator looks for it.
test('Administration > Plugins shows Gallery Rules switched on', async ({ page }) => {
  const plugins = new PluginsPage(page);

  await plugins.goto();

  await expect(plugins.box(PLUGIN_ID)).toHaveCount(1);
  await expect(plugins.box(PLUGIN_ID)).toHaveClass(/plugin-active/);
  await expect(plugins.activeSwitch(PLUGIN_ID)).toBeChecked();
});
