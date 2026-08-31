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

/*
 * ---------------------------------------------------------------------------
 * The write. The one operation in this plugin that can destroy data.
 *
 * Three rules, the same three plugins/provenance follows.
 *
 * No value reaches a command line. The whole payload goes into a JSON file read
 * with -json=, which sidesteps exiftool's escaping rules entirely - the brace
 * syntax and the flattened -RegionName= form both need quoting this plugin
 * would have to reimplement, and the flattened form additionally deletes every
 * other region name on the way past.
 *
 * exiftool's default mode is kept, so the pre-write bytes survive beside the
 * image as <name>_original and the new file appears atomically by rename.
 *
 * Two exiftool processes writing one file destroy it (measured while building
 * the provenance plugin), so every invocation first takes an exclusive lock on
 * a SEPARATE lock file - never on the image, whose inode the rename replaces.
 * ---------------------------------------------------------------------------
 */

/**
 * Writes one file's complete region list and person names.
 *
 * $regioninfo and $names come from persons_merge_regions() and are complete:
 * exiftool replaces both tags wholesale, so a partial structure here is data
 * loss. An empty array for either asks exiftool to delete that tag.
 *
 * Takes no lock of its own. The critical section is the whole read-merge-write
 * in persons_apply_change(), not this invocation - two writers that each read
 * the file before either wrote it would both produce a "complete" list missing
 * the other's region, and locking only the exec would let that happen with
 * every lock behaving correctly.
 *
 * @param string $file the image on disk
 * @param array $regioninfo the XMP-mwg-rs:RegionInfo structure
 * @param array $names XMP-iptcExt:PersonInImage
 * @return array array('ok' => bool, 'message' => string)
 */
function persons_write_regions($file, $regioninfo, $names)
{
  if (!is_file($file) or !is_writable($file))
  {
    return array('ok' => false, 'message' => 'File is missing or not writable');
  }

  if (!persons_exiftool_available())
  {
    return array('ok' => false, 'message' => 'exiftool is not available on this server');
  }

  $operation_dir = persons_operation_dir(persons_operation_id());

  try
  {
    persons_make_dir($operation_dir);

    $payload = array(array(
      'RegionInfo'    => $regioninfo,
      'PersonInImage' => $names,
      ));

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($encoded === false)
    {
      return array('ok' => false, 'message' => 'Could not encode the regions: '.json_last_error_msg());
    }

    $json_file = $operation_dir.'regions.json';
    file_put_contents($json_file, $encoded);

    $command =
      escapeshellcmd(persons_exiftool_binary()).
      ' -charset filename=UTF8'.
      ' -json='.escapeshellarg($json_file).
      ' '.escapeshellarg($file).
      ' 2>&1';

    $output = array();
    $status = 1;
    exec($command, $output, $status);

    return $status === 0
      ? array('ok' => true, 'message' => '')
      : array('ok' => false, 'message' => trim(implode(' ', $output)) ?: 'exiftool exited with status '.$status);
  }
  finally
  {
    persons_remove_dir($operation_dir);
  }
}

/**
 * The locks this process is holding, by lock file: handle and nesting depth.
 *
 * flock() attaches to the open file description, so a second fopen() of the
 * same lock file in the same process is a second description and blocks against
 * the first. A nested acquire would therefore not deadlock outright - it would
 * spin for PERSONS_LOCK_TIMEOUT_SECONDS and then report a timeout the caller
 * cannot act on. Counting the depth here makes an inner acquire a no-op instead.
 *
 * @return array the registry, by reference
 */
function &persons_lock_registry()
{
  static $held = array();

  return $held;
}

/**
 * Takes the exclusive lock guarding one image, or gives up.
 *
 * Non-blocking with a deadline rather than a blocking flock: a wedged writer
 * must not hold a request open until the server's own timeout kills it halfway
 * through.
 *
 * Re-entrant within one process: a caller already holding this image's lock gets
 * the same handle back, and only the outermost persons_lock_release() lets it go.
 *
 * @param string $db_path the stored path, which names the lock
 * @return resource|null the locked handle, or null on timeout
 */
function persons_lock_acquire($db_path)
{
  $registry = &persons_lock_registry();
  $path = persons_lock_path($db_path);

  if (isset($registry[$path]))
  {
    $registry[$path]['depth']++;

    return $registry[$path]['handle'];
  }

  persons_make_dir(PERSONS_LOCK_DIR);

  $handle = @fopen($path, 'c');
  if ($handle === false)
  {
    return null;
  }

  $deadline = microtime(true) + PERSONS_LOCK_TIMEOUT_SECONDS;

  do
  {
    if (flock($handle, LOCK_EX | LOCK_NB))
    {
      $registry[$path] = array('handle' => $handle, 'depth' => 1);

      return $handle;
    }

    usleep(PERSONS_LOCK_RETRY_MICROSECONDS);
  }
  while (microtime(true) < $deadline);

  fclose($handle);

  return null;
}

/**
 * Gives back a lock persons_lock_acquire() handed out.
 *
 * The file is unlocked only when the outermost holder releases it; an inner
 * release just decrements. A handle the registry does not know is released
 * anyway rather than leaked.
 *
 * @param resource $handle
 * @return void
 */
function persons_lock_release($handle)
{
  $registry = &persons_lock_registry();

  foreach ($registry as $path => $entry)
  {
    if ($entry['handle'] !== $handle)
    {
      continue;
    }

    $registry[$path]['depth']--;
    if ($registry[$path]['depth'] > 0)
    {
      return;
    }

    unset($registry[$path]);
    break;
  }

  flock($handle, LOCK_UN);
  fclose($handle);
}

/**
 * @param string $dir
 * @return void
 */
function persons_make_dir($dir)
{
  if (!is_dir($dir) and !@mkdir($dir, 0755, true) and !is_dir($dir))
  {
    throw new RuntimeException('Cannot create '.$dir);
  }
}

/**
 * Removes an operation directory and the payload in it.
 *
 * @param string $dir
 * @return void
 */
function persons_remove_dir($dir)
{
  if (!is_dir($dir))
  {
    return;
  }

  foreach (glob($dir.'*') as $file)
  {
    @unlink($file);
  }

  @rmdir($dir);
}
