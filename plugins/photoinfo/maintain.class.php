<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

// activate() runs before main.inc.php is loaded on a first activation.
if (!defined('PHOTOINFO_PATH'))
{
  define('PHOTOINFO_PATH', PHPWG_PLUGINS_PATH . 'photoinfo/');
}
include_once(PHOTOINFO_PATH . 'include/functions.inc.php');

/**
 * Lifecycle for the photoinfo plugin.
 *
 * The info text is core's images.comment and the date's start core's
 * date_creation; the plugin adds only the date columns a DATETIME cannot carry.
 * It writes through provenance's exiftool runner and lock, and refuses to
 * activate without it.
 */
class photoinfo_maintain extends PluginMaintain
{
  function install($plugin_version, &$errors=array())
  {
    foreach (photoinfo_image_columns() as $column => $definition)
    {
      if (!$this->has_column(IMAGES_TABLE, $column))
      {
        pwg_query('ALTER TABLE `'.IMAGES_TABLE.'` ADD `'.$column.'` '.$definition.';');
      }
    }
  }

  function update($old_version, $new_version, &$errors=array())
  {
    $this->install($new_version, $errors);
  }

  function uninstall()
  {
    foreach (array_keys(photoinfo_image_columns()) as $column)
    {
      if ($this->has_column(IMAGES_TABLE, $column))
      {
        pwg_query('ALTER TABLE `'.IMAGES_TABLE.'` DROP `'.$column.'`;');
      }
    }
  }

  function activate($plugin_version, &$errors=array())
  {
    $query = '
SELECT state
  FROM '.PLUGINS_TABLE.'
  WHERE id = \''.PHOTOINFO_REQUIRED_PLUGIN.'\'
;';
    $row = pwg_db_fetch_assoc(pwg_query($query));

    if (empty($row) or $row['state'] != 'active')
    {
      $errors[] = PHOTOINFO_REQUIRES_PROVENANCE_MESSAGE;
    }
  }

  private function has_column($table, $column)
  {
    $result = pwg_query('SHOW COLUMNS FROM `'.$table.'` LIKE "'.$column.'";');
    return pwg_db_num_rows($result) > 0;
  }
}
