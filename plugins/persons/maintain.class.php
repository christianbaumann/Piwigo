<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

// install() runs before the plugin is active, so main.inc.php has not been
// loaded and the shared schema definition is not in scope yet.
if (!defined('PERSONS_PATH'))
{
  define('PERSONS_PATH', PHPWG_PLUGINS_PATH . 'persons/');
}
include_once(PERSONS_PATH . 'include/functions.inc.php');

/**
 * Schema lifecycle for the persons plugin.
 *
 * Two tables, both a derived index: the image file is the source of truth, so
 * dropping them loses nothing a rescan cannot rebuild. Every step is guarded so
 * install() can run twice with no error - update() delegates to it, and Piwigo
 * calls update() on every version bump.
 */
class persons_maintain extends PluginMaintain
{
  private $persons_table;
  private $region_table;

  function __construct($plugin_id)
  {
    parent::__construct($plugin_id);

    global $prefixeTable;
    $this->persons_table = $prefixeTable . 'persons';
    $this->region_table  = $prefixeTable . 'person_region';
  }

  function install($plugin_version, &$errors=array())
  {
    pwg_query('
CREATE TABLE IF NOT EXISTS `' . $this->persons_table . '` (
  ' . $this->column_ddl(persons_person_columns()) . ',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) DEFAULT CHARSET=utf8mb4
;');

    pwg_query('
CREATE TABLE IF NOT EXISTS `' . $this->region_table . '` (
  ' . $this->column_ddl(persons_region_columns()) . ',
  PRIMARY KEY (`id`),
  KEY `image_lookup` (`image_id`),
  KEY `person_lookup` (`person_id`)
) DEFAULT CHARSET=utf8mb4
;');

    // A database created by an earlier version carries that version's columns.
    // install() is re-entered through update() on every version bump, which is
    // where a new column has to reach an existing table - a
    // CREATE TABLE IF NOT EXISTS never touches one.
    $this->add_missing_columns($this->persons_table, persons_person_columns());
    $this->add_missing_columns($this->region_table, persons_region_columns());
  }

  function update($old_version, $new_version, &$errors=array())
  {
    $this->install($new_version, $errors);
  }

  /**
   * Drops both tables and the tags this plugin mirrored into core.
   *
   * The regions themselves stay in the image files, so nothing the plugin
   * recorded is lost - reinstalling and rescanning restores the index. The
   * mirrored tags are a different matter: they are rows in core's table that
   * only this plugin created, and leaving them behind after an uninstall would
   * strand tags nobody can explain.
   */
  function uninstall()
  {
    $this->drop_orphan_mirrored_tags();

    pwg_query('DROP TABLE IF EXISTS `' . $this->region_table . '`;');
    pwg_query('DROP TABLE IF EXISTS `' . $this->persons_table . '`;');
  }

  /**
   * Removes a mirrored tag that nothing else uses.
   *
   * A tag that has been applied by hand to a photo which never carried a region
   * is left alone: it is the administrator's tag now, not this plugin's.
   */
  private function drop_orphan_mirrored_tags()
  {
    if (!$this->table_exists($this->persons_table))
    {
      return;
    }

    $result = pwg_query('
SELECT p.tag_id
  FROM `' . $this->persons_table . '` AS p
  LEFT JOIN `' . IMAGE_TAG_TABLE . '` AS it ON it.tag_id = p.tag_id
  WHERE p.tag_id IS NOT NULL
  GROUP BY p.tag_id
  HAVING COUNT(it.image_id) = 0
;');

    $tag_ids = array();
    while ($row = pwg_db_fetch_assoc($result))
    {
      $tag_ids[] = (int)$row['tag_id'];
    }

    if (count($tag_ids) == 0)
    {
      return;
    }

    pwg_query('DELETE FROM `' . TAGS_TABLE . '` WHERE id IN (' . implode(',', $tag_ids) . ');');
  }

  /**
   * The column list of a CREATE TABLE, built from the shared definition so the
   * schema exists in exactly one place.
   *
   * @param array $columns column => DDL fragment
   * @return string
   */
  private function column_ddl($columns)
  {
    $lines = array();
    foreach ($columns as $column => $definition)
    {
      $lines[] = '`' . $column . '` ' . $definition;
    }
    return implode(",\n  ", $lines);
  }

  private function add_missing_columns($table, $columns)
  {
    foreach ($columns as $column => $definition)
    {
      if ($column == 'id')
      {
        // AUTO_INCREMENT is only meaningful with the primary key that
        // CREATE TABLE declared; it is never added afterwards.
        continue;
      }

      if (!$this->has_column($table, $column))
      {
        pwg_query('ALTER TABLE `' . $table . '` ADD `' . $column . '` ' . $definition . ';');
      }
    }
  }

  private function has_column($table, $column)
  {
    $result = pwg_query('SHOW COLUMNS FROM `' . $table . '` LIKE "' . $column . '";');
    return pwg_db_num_rows($result) > 0;
  }

  private function table_exists($table)
  {
    $result = pwg_query('SHOW TABLES LIKE "' . $table . '";');
    return pwg_db_num_rows($result) > 0;
  }
}
