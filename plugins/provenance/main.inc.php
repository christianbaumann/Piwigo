<?php
/*
Plugin Name: Provenance
Version: 1.0.0
Description: Records where a scan came from - physical album, owner, scan date and notes - entered at album level, copied down onto its photos, and written into the image files.
Plugin URI: https://github.com/christianbaumann/Piwigo
Author: Christian Baumann
Has Settings: false
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

if (basename(dirname(__FILE__)) != 'provenance')
{
  add_event_handler('init', 'provenance_folder_name_error');
  function provenance_folder_name_error()
  {
    global $page;
    $page['errors'][] = 'Provenance folder name is incorrect, uninstall the plugin and rename it to "provenance"';
  }
  return;
}

global $prefixeTable;

// maintain.class.php defines this too, and runs first during install.
if (!defined('PROVENANCE_PATH'))
{
  define('PROVENANCE_PATH', PHPWG_PLUGINS_PATH . 'provenance/');
}
define('PROVENANCE_HISTORY_TABLE', $prefixeTable . 'provenance_history');
define('PROVENANCE_XMP_CONFIG',    PROVENANCE_PATH . 'exiftool/pwgprov.config');

include_once(PROVENANCE_PATH . 'include/functions.inc.php');
