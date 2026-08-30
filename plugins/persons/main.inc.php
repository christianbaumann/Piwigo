<?php
/*
Plugin Name: Persons
Version: 1.0.0
Description: Draw a box around a person in a photo and name them. Regions are stored in the image file as MWG regions; the database holds a rebuildable index.
Plugin URI: https://github.com/christianbaumann/Piwigo
Author: Christian Baumann
Has Settings: true
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

if (basename(dirname(__FILE__)) != 'persons')
{
  add_event_handler('init', 'persons_folder_name_error');
  function persons_folder_name_error()
  {
    global $page;
    $page['errors'][] = 'Persons folder name is incorrect, uninstall the plugin and rename it to "persons"';
  }
  return;
}

global $prefixeTable, $conf;

// maintain.class.php defines this too, and runs first during install.
if (!defined('PERSONS_PATH'))
{
  define('PERSONS_PATH', PHPWG_PLUGINS_PATH . 'persons/');
}
define('PERSONS_TABLE',        $prefixeTable . 'persons');
define('PERSONS_REGION_TABLE', $prefixeTable . 'person_region');

include_once(PERSONS_PATH . 'include/functions.inc.php');

// The binary is expected on PATH. A host that keeps it elsewhere sets the
// directory - with its trailing slash - in local/config/config.inc.php.
// Unlike plugins/provenance this plugin needs no -config file: exiftool has
// read and written XMP-mwg-rs built in since well before 13.25.
if (!isset($conf['persons_exiftool_path']))
{
  $conf['persons_exiftool_path'] = '';
}

add_event_handler('ws_add_methods', 'persons_add_methods');

/**
 * Registers the plugin's web-service methods.
 *
 * @param array $arr
 */
function persons_add_methods($arr)
{
  // Registered from Phase 4 on. The handler exists now so the event this plugin
  // subscribes to is real, and so activation is exercised end to end.
}
