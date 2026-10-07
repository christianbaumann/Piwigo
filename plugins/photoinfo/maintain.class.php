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
 * The info text is core's images.comment, so there is no schema to create. The
 * plugin writes through provenance's exiftool runner and lock, and refuses to
 * activate without it.
 */
class photoinfo_maintain extends PluginMaintain
{
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
}
