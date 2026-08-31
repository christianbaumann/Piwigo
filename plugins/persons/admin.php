<?php
defined('PERSONS_PATH') or die('Hacking attempt!');

/*
 * The plugin's admin entry point.
 *
 * admin.php?page=plugin-persons is rewritten by admin/admin.php:129-143 into
 * ?page=plugin&section=persons/admin.php, so this file is what every admin URL
 * of the plugin lands on. admin/plugin.php has already run
 * check_status(ACCESS_ADMINISTRATOR) before including it.
 *
 * Two screens: the persons list, which is what the plugin's Settings link
 * leads to, and the tagging screen for a single photo, which is the same URL
 * with an image_id on it.
 */

include_once(PERSONS_PATH.'include/functions.inc.php');

load_language('plugin.lang', PERSONS_PATH);

$image_id = isset($_GET['image_id']) ? (int)$_GET['image_id'] : 0;

if ($image_id > 0)
{
  include(PERSONS_PATH.'admin/photo.php');
}
else
{
  include(PERSONS_PATH.'admin/persons.php');
}
