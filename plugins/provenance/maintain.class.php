<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

// install() runs before the plugin is active, so main.inc.php has not been
// loaded and the shared schema definition is not in scope yet.
if (!defined('PROVENANCE_PATH'))
{
  define('PROVENANCE_PATH', PHPWG_PLUGINS_PATH . 'provenance/');
}
include_once(PROVENANCE_PATH . 'include/functions.inc.php');

/**
 * Schema lifecycle for the provenance plugin.
 *
 * Nine columns and one table. Every step is guarded so install() can run twice
 * with no error - update() delegates to it, and Piwigo calls update() on every
 * version bump.
 */
class provenance_maintain extends PluginMaintain
{
  private $history_table;

  function __construct($plugin_id)
  {
    parent::__construct($plugin_id);

    global $prefixeTable;
    $this->history_table = $prefixeTable . 'provenance_history';
  }

  function install($plugin_version, &$errors=array())
  {
    foreach (provenance_album_columns() as $column => $definition)
    {
      $this->add_column(CATEGORIES_TABLE, $column, $definition);
    }

    foreach (provenance_image_columns() as $column => $definition)
    {
      $this->add_column(IMAGES_TABLE, $column, $definition);
    }

    pwg_query('
CREATE TABLE IF NOT EXISTS `' . $this->history_table . '` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `object` ' . $this->enum(provenance_history_objects()) . ' NOT NULL,
  `object_id` int(10) unsigned NOT NULL,
  `field` varchar(' . PROVENANCE_HISTORY_FIELD_MAX_BYTES . ') NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `source` ' . $this->enum(provenance_history_sources()) . ' NOT NULL,
  `performed_by` mediumint(8) unsigned DEFAULT NULL,
  `occured_on` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `object_lookup` (`object`, `object_id`, `occured_on`)
) DEFAULT CHARSET=utf8mb4
;');
  }

  function update($old_version, $new_version, &$errors=array())
  {
    $this->install($new_version, $errors);
  }

  function uninstall()
  {
    foreach (array_keys(provenance_album_columns()) as $column)
    {
      $this->drop_column(CATEGORIES_TABLE, $column);
    }

    foreach (array_keys(provenance_image_columns()) as $column)
    {
      $this->drop_column(IMAGES_TABLE, $column);
    }

    pwg_query('DROP TABLE IF EXISTS `' . $this->history_table . '`;');
  }

  /**
   * An ENUM column type built from the shared list, so the recorder cannot
   * validate against a value the column does not accept.
   *
   * @param array $values
   * @return string
   */
  private function enum($values)
  {
    return "enum('" . implode("','", $values) . "')";
  }

  private function add_column($table, $column, $definition)
  {
    if (!$this->has_column($table, $column))
    {
      pwg_query('ALTER TABLE `'.$table.'` ADD `'.$column.'` '.$definition.';');
    }
  }

  private function drop_column($table, $column)
  {
    if ($this->has_column($table, $column))
    {
      pwg_query('ALTER TABLE `'.$table.'` DROP `'.$column.'`;');
    }
  }

  private function has_column($table, $column)
  {
    $result = pwg_query('SHOW COLUMNS FROM `'.$table.'` LIKE "'.$column.'";');
    return pwg_db_num_rows($result) > 0;
  }
}
