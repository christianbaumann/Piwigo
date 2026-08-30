<?php
defined('PERSONS_PATH') or die('Hacking attempt!');

/*
 * The exiftool boundary. Everything here shells out; everything that decides
 * what the output means is a pure function in functions.inc.php, so a writer's
 * odd JSON shape is a unit test rather than a fixture file.
 *
 * Unlike plugins/provenance this plugin passes no -config file: exiftool has
 * read and written XMP-mwg-rs built in since long before 13.25 (verified
 * 2026-08-29).
 */

/**
 * The exiftool binary, honouring $conf['persons_exiftool_path'].
 *
 * The default is the empty string - the binary is expected on PATH. A host that
 * keeps it elsewhere sets the directory (with its trailing slash) in
 * local/config/config.inc.php rather than patching this plugin.
 *
 * @return string
 */
function persons_exiftool_binary()
{
  global $conf;

  $dir = isset($conf['persons_exiftool_path']) ? $conf['persons_exiftool_path'] : '';

  return $dir.'exiftool';
}

/**
 * Whether this server can read or write region metadata at all.
 *
 * exec() is checked first, because disable_functions makes calling it a fatal
 * error rather than a false return - the same degradation shape as
 * pwg_image::is_ext_imagick() (admin/include/image.class.php:393-410).
 *
 * @return bool
 */
function persons_exiftool_available()
{
  // Memoised: a rescan asks once per photo, and probing the binary again for
  // each of them doubles the processes the batch starts.
  static $available = null;

  if ($available !== null)
  {
    return $available;
  }

  if (!function_exists('exec'))
  {
    return $available = false;
  }

  $out = array();
  @exec(escapeshellcmd(persons_exiftool_binary()).' -ver', $out);

  return $available = (is_array($out) and !empty($out[0]) and preg_match('/^\d+\.\d+/', $out[0]));
}

/**
 * The file on disk behind an image row's stored path.
 *
 * @param string $db_path the path column, relative to the gallery root
 * @return string
 */
function persons_image_file_path($db_path)
{
  return PHPWG_ROOT_PATH.ltrim((string)$db_path, './');
}

/**
 * Reads one file's MWG regions and person names.
 *
 * A file with no regions is not a failure - it is the common case - so 'ok' is
 * true with an empty region list. 'ok' is false only when the file could not be
 * read at all, which is what makes a rescan able to report which photo it could
 * not open.
 *
 * @param string $file the image on disk
 * @return array the persons_parse_regioninfo() shape plus 'ok' and 'message'
 */
function persons_read_regions($file)
{
  $empty = persons_parse_regioninfo(null);

  if (!is_file($file) or !is_readable($file))
  {
    return array_merge($empty, array('ok' => false, 'message' => 'File is missing or not readable'));
  }

  if (!persons_exiftool_available())
  {
    return array_merge($empty, array('ok' => false, 'message' => 'exiftool is not available on this server'));
  }

  $command =
    escapeshellcmd(persons_exiftool_binary()).
    ' -json -struct -charset filename=UTF8'.
    ' -XMP-mwg-rs:RegionInfo -XMP-iptcExt:PersonInImage '.
    escapeshellarg($file).
    ' 2>/dev/null';

  $output = array();
  $status = 1;
  exec($command, $output, $status);

  if ($status !== 0)
  {
    return array_merge($empty, array(
      'ok' => false,
      'message' => 'exiftool could not read the file (status '.$status.')',
      ));
  }

  $decoded = json_decode(implode("\n", $output), true);

  if (!is_array($decoded))
  {
    return array_merge($empty, array('ok' => false, 'message' => 'exiftool returned no readable JSON'));
  }

  return array_merge(persons_parse_regioninfo($decoded), array('ok' => true, 'message' => ''));
}
