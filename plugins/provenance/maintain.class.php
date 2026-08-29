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

    // A database created by an earlier version carries the ENUM that version
    // declared. install() is re-entered through update() on every version bump,
    // which is where a new source value has to reach an existing column - a
    // CREATE TABLE IF NOT EXISTS never touches one.
    $this->widen_source_enum();

    $this->add_display_info_key();
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

    $this->drop_display_info_key();
  }

  /**
   * Seeds this plugin's key into the picture page's row-visibility map.
   *
   * Present and true, so the row an administrator has just installed the plugin
   * for is visible without a second step. An existing key is left alone: a
   * reinstall or a version bump runs install() again through update(), and must
   * not silently switch a row the administrator turned off back on.
   */
  private function add_display_info_key()
  {
    $map = $this->display_info();

    if ($map === null or array_key_exists(PROVENANCE_DISPLAY_INFO_KEY, $map))
    {
      return;
    }

    $map[PROVENANCE_DISPLAY_INFO_KEY] = true;
    $this->save_display_info($map);
  }

  /** Takes the key out again, so an uninstall leaves core's map as it found it. */
  private function drop_display_info_key()
  {
    $map = $this->display_info();

    if ($map === null or !array_key_exists(PROVENANCE_DISPLAY_INFO_KEY, $map))
    {
      return;
    }

    unset($map[PROVENANCE_DISPLAY_INFO_KEY]);
    $this->save_display_info($map);
  }

  /**
   * The visibility map, or null when this install has none to extend.
   *
   * @return array|null
   */
  private function display_info()
  {
    global $conf;

    if (!isset($conf[PROVENANCE_DISPLAY_INFO_PARAM]))
    {
      return null;
    }

    $map = unserialize($conf[PROVENANCE_DISPLAY_INFO_PARAM]);

    return is_array($map) ? $map : null;
  }

  /**
   * @param array $map
   */
  private function save_display_info($map)
  {
    global $conf;

    conf_update_param(PROVENANCE_DISPLAY_INFO_PARAM, $map);

    // $conf holds the serialized string, which is what picture.php unserializes;
    // letting conf_update_param() put the array there instead would break the
    // page for the rest of this request.
    $conf[PROVENANCE_DISPLAY_INFO_PARAM] = serialize($map);
  }

  /**
   * Brings the source column up to the recorder's current list.
   *
   * MySQL stores a value outside an ENUM as '', so a column that has not been
   * widened does not fail loudly - it writes rows that claim nothing.
   */
  private function widen_source_enum()
  {
    pwg_query('
ALTER TABLE `' . $this->history_table . '`
  MODIFY `source` ' . $this->enum(provenance_history_sources()) . ' NOT NULL
;');
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
