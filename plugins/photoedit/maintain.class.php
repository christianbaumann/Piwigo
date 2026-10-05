<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Lifecycle for the photoedit plugin.
 *
 * The plugin keeps no tables and no config rows: an edit changes the image file
 * and core's own columns, so there is nothing to create or drop.
 */
class photoedit_maintain extends PluginMaintain
{
}
