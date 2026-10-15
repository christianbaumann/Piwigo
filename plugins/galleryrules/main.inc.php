<?php
/*
Plugin Name: Gallery Rules
Version: 1.0.0
Description: The fork's changes to how core Piwigo behaves, made through core's own events instead of edits to core.
Plugin URI: https://github.com/christianbaumann/Piwigo
Author: Christian Baumann
Has Settings: false
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

if (basename(dirname(__FILE__)) != 'galleryrules')
{
  add_event_handler('init', 'galleryrules_folder_name_error');
  function galleryrules_folder_name_error()
  {
    global $page;
    $page['errors'][] = 'Gallery Rules folder name is incorrect, uninstall the plugin and rename it to "galleryrules"';
  }
  return;
}

// maintain.class.php defines this too, and runs first during install.
if (!defined('GALLERYRULES_PATH'))
{
  define('GALLERYRULES_PATH', PHPWG_PLUGINS_PATH . 'galleryrules/');
}
