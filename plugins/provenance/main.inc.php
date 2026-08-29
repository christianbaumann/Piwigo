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
include_once(PROVENANCE_PATH . 'include/history.inc.php');

add_event_handler('ws_add_methods', 'provenance_add_methods');

/**
 * Registers the plugin's web-service methods.
 *
 * @param array $arr
 */
function provenance_add_methods($arr)
{
  $service = &$arr[0];

  $service->addMethod(
    'pwg.provenance.getHistory',
    'ws_provenance_getHistory',
    array(
      'object' => array('info' => 'One of: '.implode(', ', provenance_history_objects())),
      'object_id' => array('type' => WS_TYPE_ID),
      'date_min' => array('flags' => WS_PARAM_OPTIONAL, 'info' => 'YYYY-MM-DD or YYYY-MM-DD HH:MM:SS'),
      'date_max' => array('flags' => WS_PARAM_OPTIONAL, 'info' => 'YYYY-MM-DD or YYYY-MM-DD HH:MM:SS'),
      'per_page' => array(
        'default' => PROVENANCE_HISTORY_PER_PAGE_DEFAULT,
        'maxValue' => PROVENANCE_HISTORY_PER_PAGE_MAX,
        'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
        ),
      ),
    'Reads the provenance audit trail of one album or photo, newest first.',
    PROVENANCE_PATH . 'include/ws_functions.inc.php',
    array('admin_only' => true)
  );
}
