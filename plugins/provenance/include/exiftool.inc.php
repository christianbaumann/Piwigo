<?php
defined('PROVENANCE_PATH') or die('Hacking attempt!');

/*
 * The file write-back: the one operation in this plugin that can destroy data.
 *
 * Three rules shape everything below.
 *
 * A value never reaches a command line - every tag and value goes into an
 * argfile read with -@, one argument per line (decision C8).
 *
 * exiftool's default mode is kept, so the original file is renamed aside to
 * <name>_original and the new file appears atomically (decision 7b). Measured
 * during research: -overwrite_original truncates in place, which a concurrent
 * derivative generation can read half-written.
 *
 * Two exiftool processes writing one file delete it outright (measured: 5 of 6
 * runs at 12-way contention), so every invocation holds an exclusive lock on a
 * separate lock file first - never on the image, whose inode the rename
 * replaces.
 */

/**
 * The exiftool binary, honouring $conf['provenance_exiftool_path'].
 *
 * The default is the empty string - the binary is expected on PATH. A host that
 * keeps it elsewhere sets the directory (with its trailing slash) in
 * local/config/config.inc.php rather than patching this plugin.
 *
 * @return string
 */
function provenance_exiftool_binary()
{
  global $conf;

  $dir = isset($conf['provenance_exiftool_path']) ? $conf['provenance_exiftool_path'] : '';

  return $dir.'exiftool';
}

/**
 * Whether this server can write metadata at all.
 *
 * Follows the degradation shape of pwg_image::is_ext_imagick()
 * (admin/include/image.class.php:393-410): exec() is checked first, because
 * disable_functions makes calling it a fatal error rather than a false return.
 * When this answers false the button is hidden and the web-service method
 * refuses - every other part of the feature keeps working.
 *
 * @return bool
 */
function provenance_exiftool_available()
{
  if (!function_exists('exec'))
  {
    return false;
  }

  $out = array();
  @exec(escapeshellcmd(provenance_exiftool_binary()).' -ver', $out);

  return is_array($out) and !empty($out[0]) and preg_match('/^\d+\.\d+/', $out[0]);
}

/**
 * The file on disk behind an image row's stored path.
 *
 * @param string $db_path the path column, relative to the gallery root
 * @return string
 */
function provenance_image_file_path($db_path)
{
  return PHPWG_ROOT_PATH.ltrim((string)$db_path, './');
}

/**
 * Writes the provenance of each given photo into its file.
 *
 * Continues past a failed photo rather than abandoning the chunk (decision
 * 13a): one unreadable file must not stop an album of 76. Each outcome is
 * recorded in the history table as it happens, before the finally removes the
 * operation directory, so cleanup can never erase the evidence of a failure.
 *
 * @param array $images image rows: id, path and the five provenance columns
 * @param array $labels provenance field => caption label, from l10n() at the call site
 * @return array array('written' => int, 'failed' => array(image id => message))
 */
function provenance_write_back($images, $labels)
{
  $operation_dir = provenance_operation_dir(provenance_operation_id());
  $written = 0;
  $failed = array();

  try
  {
    provenance_make_dir($operation_dir);

    foreach ($images as $image)
    {
      $image_id = (int)$image['id'];
      $file = provenance_image_file_path($image['path']);

      if (!is_file($file) or !is_writable($file))
      {
        $failed[$image_id] = 'File is missing or not writable';
        provenance_record_change('photo', $image_id, PROVENANCE_HISTORY_FIELD_FILE_ERROR,
          null, $failed[$image_id], 'writeback');
        continue;
      }

      $caption = provenance_compose_caption(provenance_caption_parts($image, $labels));
      $lines = provenance_build_argfile($image, $caption);

      if (empty($lines))
      {
        // Nothing to say about this photo. Invoking exiftool anyway would
        // rewrite the file - and create an _original sidecar - for no change.
        continue;
      }

      $argfile = $operation_dir.$image_id.'.args';
      file_put_contents($argfile, implode("\n", $lines)."\n");

      $result = provenance_exiftool_run($argfile, $file, $image['path']);

      if ($result['ok'])
      {
        $written++;
        provenance_record_change('photo', $image_id, PROVENANCE_HISTORY_FIELD_FILE,
          null, $caption, 'writeback');

        $iptc = provenance_truncate_for_iptc($caption);
        if ($iptc['truncated'])
        {
          provenance_record_change('photo', $image_id, PROVENANCE_IPTC_CAPTION_TAG,
            $caption, $iptc['text'], 'truncation');
        }
      }
      else
      {
        $failed[$image_id] = $result['message'];
        provenance_record_change('photo', $image_id, PROVENANCE_HISTORY_FIELD_FILE_ERROR,
          null, $result['message'], 'writeback');
      }
    }
  }
  finally
  {
    provenance_remove_dir($operation_dir);
  }

  return array('written' => $written, 'failed' => $failed);
}

/**
 * Runs one exiftool invocation under an exclusive lock.
 *
 * @param string $argfile
 * @param string $file the image on disk
 * @param string $db_path the stored path, which names the lock
 * @return array array('ok' => bool, 'message' => string)
 */
function provenance_exiftool_run($argfile, $file, $db_path)
{
  $command =
    escapeshellcmd(provenance_exiftool_binary()).
    ' -config '.escapeshellarg(PROVENANCE_XMP_CONFIG).
    ' -@ '.escapeshellarg($argfile).
    ' '.escapeshellarg($file).
    ' 2>&1';

  $lock = provenance_lock_acquire($db_path);
  if ($lock === null)
  {
    return array('ok' => false, 'message' => 'Timed out waiting for another write to this file');
  }

  try
  {
    $output = array();
    $status = 1;
    exec($command, $output, $status);
  }
  finally
  {
    flock($lock, LOCK_UN);
    fclose($lock);
  }

  return $status === 0
    ? array('ok' => true, 'message' => '')
    : array('ok' => false, 'message' => trim(implode(' ', $output)) ?: 'exiftool exited with status '.$status);
}

/**
 * Takes the exclusive lock guarding one image, or gives up.
 *
 * Non-blocking with a deadline rather than a blocking flock: a wedged writer
 * must not hold a request until the server's own timeout kills it halfway
 * through an album.
 *
 * @param string $db_path the stored path, which names the lock
 * @return resource|null the locked handle, or null on timeout
 */
function provenance_lock_acquire($db_path)
{
  provenance_make_dir(PROVENANCE_LOCK_DIR);

  $handle = @fopen(provenance_lock_path($db_path), 'c');
  if ($handle === false)
  {
    return null;
  }

  $deadline = microtime(true) + PROVENANCE_LOCK_TIMEOUT_SECONDS;

  do
  {
    if (flock($handle, LOCK_EX | LOCK_NB))
    {
      return $handle;
    }

    usleep(PROVENANCE_LOCK_RETRY_MICROSECONDS);
  }
  while (microtime(true) < $deadline);

  fclose($handle);

  return null;
}

/**
 * @param string $dir
 * @return void
 */
function provenance_make_dir($dir)
{
  if (!is_dir($dir) and !@mkdir($dir, 0755, true) and !is_dir($dir))
  {
    throw new RuntimeException('Cannot create '.$dir);
  }
}

/**
 * Removes an operation directory and the argfiles in it.
 *
 * @param string $dir
 * @return void
 */
function provenance_remove_dir($dir)
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
