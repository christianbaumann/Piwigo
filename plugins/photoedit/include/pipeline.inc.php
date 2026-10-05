<?php
defined('PHOTOEDIT_PATH') or die('Hacking attempt!');

/*
 * The write. The one operation in this plugin that changes an image file.
 *
 * The new file is built beside the pipeline, in an operation directory under
 * _data, and only a complete file - pixels turned, metadata copied back - is
 * renamed over the original. Every step before the rename leaves the original
 * untouched; the copy in _data/photoedit/originals/ is there for the steps
 * after it. A failure after the rename - the row update dies with a SQL error,
 * which exits without running finally - leaves the file turned and the row
 * not; the backup is then the way back.
 */

include_once(PHOTOEDIT_PATH.'include/functions.inc.php');

// Scratch space and backups, beside persons' and provenance's under _data.
define('PHOTOEDIT_DATA_DIR', PHPWG_ROOT_PATH.'_data/photoedit/');
define('PHOTOEDIT_LOCK_DIR', PHOTOEDIT_DATA_DIR.'locks/');
define('PHOTOEDIT_ORIGINALS_DIR', PHOTOEDIT_DATA_DIR.'originals/');
define('PHOTOEDIT_WORK_DIR', PHOTOEDIT_DATA_DIR.'work/');

/** File types the pipeline writes. JPEG follows in a later step. */
function photoedit_supported_extensions()
{
  return array('png');
}

/**
 * The exiftool binary, honouring $conf['photoedit_exiftool_path'] - a directory
 * with its trailing slash, empty for PATH.
 *
 * @return string
 */
function photoedit_exiftool_binary()
{
  global $conf;

  $dir = isset($conf['photoedit_exiftool_path']) ? $conf['photoedit_exiftool_path'] : '';

  return $dir.'exiftool';
}

/**
 * Why this server cannot edit a photo, or the empty string when it can.
 *
 * exec() is checked first: disable_functions makes calling it a fatal error
 * rather than a false return.
 *
 * @return string
 */
function photoedit_unavailable_reason()
{
  if (!function_exists('exec'))
  {
    return l10n('exec() is disabled on this server, so the photo cannot be edited.');
  }

  $out = array();
  @exec(escapeshellcmd(photoedit_exiftool_binary()).' -ver 2>/dev/null', $out);
  if (empty($out[0]) or !preg_match('/^\d+\.\d+/', $out[0]))
  {
    return l10n('exiftool is not available on this server, so the photo cannot be edited.');
  }

  return '';
}

/**
 * @param string $db_path the path column, relative to the gallery root
 * @return string
 */
function photoedit_image_file_path($db_path)
{
  return PHPWG_ROOT_PATH.ltrim((string)$db_path, './');
}

/**
 * A failed edit, in the shape every pipeline function returns.
 *
 * @param string $code one of not_found, invalid, unsupported, unavailable, locked, write_failed
 * @param string $message
 * @return array
 */
function photoedit_failure($code, $message)
{
  return array('ok' => false, 'code' => $code, 'message' => $message);
}

/**
 * A failed write: the detail, which may name server paths, goes to the log;
 * the caller gets the message without it.
 *
 * @param string $message what the webmaster reads
 * @param string $detail what the log keeps
 * @return array
 */
function photoedit_write_failure($message, $detail)
{
  global $logger;

  if (is_object($logger))
  {
    $logger->error('photoedit: '.$message.': '.$detail);
  }

  return photoedit_failure('write_failed', $message);
}

/**
 * One image row, as the pipeline reads it.
 *
 * @param int $image_id
 * @return array|null
 */
function photoedit_image_row($image_id)
{
  return pwg_db_fetch_assoc(pwg_query(
    'SELECT id, file, path, width, height, filesize, md5sum, coi, rotation, representative_ext
  FROM '.IMAGES_TABLE.'
  WHERE id = '.(int)$image_id.'
;'));
}

/**
 * What an edit does to the raw file, as the events describe it.
 *
 * @param array $image the image row
 * @param int $turns
 * @param array|null $box the crop as fractions of the turned photo
 * @return array the transform, or a failure ('ok' false) when the photo's
 *   size makes the edit impossible or pointless
 */
function photoedit_transform($image, $turns, $box)
{
  $plan = photoedit_plan_edit($image['width'], $image['height'], $turns, $box);
  if (!$plan['ok'])
  {
    return photoedit_failure('invalid', $plan['error']);
  }

  return array('ok' => true, 'rotation_before' => (int)$image['rotation']) + $plan['transform'];
}

/**
 * Records a new version of one photo's file, so its URLs change.
 *
 * Re-read from the database rather than taken from $conf: an edit of another
 * photo may have written the row since this request loaded it.
 *
 * @param int $image_id
 * @param string $file
 * @return void
 */
function photoedit_record_version($image_id, $file)
{
  global $conf;

  $row = pwg_db_fetch_row(pwg_query(
    'SELECT value FROM '.CONFIG_TABLE.' WHERE param = \'photoedit_versions\';'
    ));

  $versions = photoedit_decode_versions($row ? $row[0] : null);
  $versions[(int)$image_id] = substr(md5_file($file), 0, 12);

  photoedit_store_versions($versions);
}

/**
 * @param array $versions
 * @return void
 */
function photoedit_store_versions($versions)
{
  global $conf;

  // Digits, hex and JSON punctuation only: nothing conf_update_param() would need escaped.
  $conf['photoedit_versions'] = json_encode((object)$versions);
  conf_update_param('photoedit_versions', $conf['photoedit_versions']);
}

/**
 * delete_elements: forgets the versions of deleted photos.
 *
 * @param int[] $ids
 * @return void
 */
function photoedit_delete_elements($ids)
{
  $row = pwg_db_fetch_row(pwg_query(
    'SELECT value FROM '.CONFIG_TABLE.' WHERE param = \'photoedit_versions\';'
    ));
  $versions = photoedit_decode_versions($row ? $row[0] : null);

  $kept = array_diff_key($versions, array_flip(array_map('intval', (array)$ids)));
  if (count($kept) != count($versions))
  {
    photoedit_store_versions($kept);
  }
}

/**
 * Turns and crops one photo and writes the result into its file, or only
 * reports what that would do.
 *
 * @param int $image_id
 * @param int $turns quarter turns clockwise, already validated
 * @param array|null $box the crop as fractions of the turned photo, already validated
 * @param bool $dry_run
 * @return array 'ok' plus, on success, 'lost_regions', 'lossy' and after a
 *   write 'width', 'height'; on failure 'code' and 'message'
 */
function photoedit_apply($image_id, $turns, $box, $dry_run)
{
  $image = photoedit_image_row($image_id);

  if (!$image)
  {
    return photoedit_failure('not_found', 'No photo with this id');
  }

  $file = photoedit_image_file_path($image['path']);
  $extension = strtolower(get_extension($image['path']));

  if (!in_array($extension, photoedit_supported_extensions()))
  {
    return photoedit_failure('unsupported', 'Only '.implode(', ', photoedit_supported_extensions()).' files can be edited');
  }

  // The rename replaces the file, which needs the directory, not the file, writable.
  if (!is_file($file) or !is_writable(dirname($file)))
  {
    return photoedit_failure('write_failed', 'The image file is missing or its folder is not writable');
  }

  // Core sets a rotation only from a JPEG's EXIF Orientation; baking it in comes with JPEG support.
  if ((int)$image['rotation'] != 0)
  {
    return photoedit_failure('unsupported', 'A photo with a stored rotation cannot be edited yet');
  }

  $reason = photoedit_unavailable_reason();
  if ($reason !== '')
  {
    return photoedit_failure('unavailable', $reason);
  }

  $transform = photoedit_transform($image, $turns, $box);
  if (!$transform['ok'])
  {
    return $transform;
  }
  unset($transform['ok']);

  $lost = trigger_change('photoedit_preview', array(), $image, $transform);

  if ($dry_run)
  {
    return array('ok' => true, 'lost_regions' => array_values((array)$lost), 'lossy' => false);
  }

  $lock = photoedit_lock_acquire($image['path']);
  if ($lock === null)
  {
    return photoedit_failure('locked', 'Another edit of this photo is still running');
  }

  // The row as it is now: a write that waited for the lock must not turn the
  // size and centre of interest the previous write already turned.
  $image = photoedit_image_row($image_id);
  if (!$image)
  {
    photoedit_lock_release($lock);
    return photoedit_failure('not_found', 'No photo with this id');
  }
  $transform = photoedit_transform($image, $turns, $box);
  if (!$transform['ok'])
  {
    photoedit_lock_release($lock);
    return $transform;
  }
  unset($transform['ok']);

  $ok = false;
  $work_dir = PHOTOEDIT_WORK_DIR.uniqid((string)$image['id'].'-', true).'/';

  try
  {
    trigger_notify('photoedit_begin', $image);

    $result = photoedit_write($image, $file, $extension, $transform, $work_dir);
    $ok = $result['ok'];
  }
  catch (Throwable $e)
  {
    $result = photoedit_write_failure('The photo could not be written', $e->getMessage());
  }
  finally
  {
    trigger_notify('photoedit_end', $image, $transform, $ok);
    photoedit_remove_dir($work_dir);
    photoedit_lock_release($lock);
  }

  if (!$ok)
  {
    return $result;
  }

  return array(
    'ok' => true,
    'lost_regions' => array_values((array)$lost),
    'lossy' => false,
    'width' => $result['width'],
    'height' => $result['height'],
    );
}

/**
 * The steps between photoedit_begin and photoedit_end.
 *
 * @return array 'ok', and 'width', 'height' or 'code', 'message'
 */
function photoedit_write($image, $file, $extension, $transform, $work_dir)
{
  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
  include_once(PHPWG_ROOT_PATH.'admin/include/image.class.php');

  if (!pwg_image::get_library(null, $extension))
  {
    return photoedit_failure('unavailable', 'No image library is available on this server');
  }

  photoedit_make_dir(PHOTOEDIT_ORIGINALS_DIR);
  photoedit_make_dir($work_dir);

  // Microseconds in the name: two edits of one photo within a second must not
  // share a backup, or the second would overwrite the true original.
  list($micro) = explode(' ', microtime());
  $backup = PHOTOEDIT_ORIGINALS_DIR.$image['id'].'-'.date('YmdHis').'-'.substr($micro, 2, 6).'.'.$extension;
  if (file_exists($backup) or !@copy($file, $backup) or md5_file($backup) !== md5_file($file))
  {
    return photoedit_write_failure('Could not back up the original file', $backup);
  }

  // pwg_image picks the format from the extension, so the temp file keeps it.
  $temp = $work_dir.'edited.'.$extension;

  $editor = new pwg_image($file);
  // libgd aborts the whole process turning a palette image by 180 degrees
  // (measured 2026-10-05, PHP 8.4 in DDEV); a truecolour copy turns fine.
  if ($editor->library == 'gd' and !imageistruecolor($editor->image->image))
  {
    imagepalettetotruecolor($editor->image->image);
  }
  $editor->rotate(photoedit_rotate_angle($transform['turns']));
  $rect = $transform['crop_px'];
  if ($rect !== null)
  {
    $editor->crop($rect['w'], $rect['h'], $rect['x'], $rect['y']);
    // Imagick keeps the cut-away canvas as the image's page, which a PNG
    // carries out as an offset; the edited photo starts at its own corner.
    if ($editor->library == 'imagick')
    {
      $editor->image->image->setImagePage(0, 0, 0, 0);
    }
  }
  // The libraries report failure as warnings (image_ext_imagick::write() raises
  // one per output line); the file on disk is checked below instead, so a
  // warning cannot end up inside a web-service response.
  @$editor->write($temp);
  $editor->destroy();

  $size = is_file($temp) ? @getimagesize($temp) : false;
  if ($size === false
      or (int)$size[0] !== $transform['width_after']
      or (int)$size[1] !== $transform['height_after'])
  {
    return photoedit_write_failure('The image library did not produce the edited photo', $temp);
  }

  // The libraries keep some metadata and drop the rest; copy all of it back
  // from the untouched backup. -overwrite_original: the temp file needs no
  // second copy of itself.
  $command =
    escapeshellcmd(photoedit_exiftool_binary()).
    ' -charset filename=UTF8 -overwrite_original'.
    ' -tagsFromFile '.escapeshellarg($backup).' -all:all '.
    escapeshellarg($temp).
    ' 2>&1';
  $output = array();
  $status = 1;
  exec($command, $output, $status);
  if ($status !== 0)
  {
    return photoedit_write_failure('exiftool could not copy the metadata back', 'status '.$status.': '.implode(' ', $output));
  }

  // The new file keeps the original's permissions, not the temp file's umask,
  // so a photo tracked in git shows no mode change.
  @chmod($temp, fileperms($file) & 0777);
  if (!@rename($temp, $file))
  {
    return photoedit_write_failure('Could not replace the image file', $file);
  }
  clearstatcache();

  $updates = array(
    'width' => $transform['width_after'],
    'height' => $transform['height_after'],
    'filesize' => floor(filesize($file) / 1024),
    'rotation' => 0,
    'coi' => photoedit_transform_coi($image['coi'], $transform),
    );
  if (!empty($image['md5sum']))
  {
    $updates['md5sum'] = md5_file($file);
  }

  single_update(IMAGES_TABLE, $updates, array('id' => $image['id']));

  delete_element_derivatives($image);
  photoedit_record_version($image['id'], $file);

  return array('ok' => true, 'width' => $transform['width_after'], 'height' => $transform['height_after']);
}

/**
 * Takes the exclusive lock guarding one photo, or gives up.
 *
 * A separate lock file, never the image: the rename replaces the image's inode.
 *
 * @param string $db_path the stored path, which names the lock
 * @return resource|null the locked handle, or null on timeout
 */
function photoedit_lock_acquire($db_path)
{
  photoedit_make_dir(PHOTOEDIT_LOCK_DIR);

  $handle = @fopen(PHOTOEDIT_LOCK_DIR.sha1((string)$db_path).'.lock', 'c');
  if ($handle === false)
  {
    return null;
  }

  $deadline = microtime(true) + PHOTOEDIT_LOCK_TIMEOUT_SECONDS;
  do
  {
    if (flock($handle, LOCK_EX | LOCK_NB))
    {
      return $handle;
    }
    usleep(PHOTOEDIT_LOCK_RETRY_MICROSECONDS);
  }
  while (microtime(true) < $deadline);

  fclose($handle);

  return null;
}

/**
 * @param resource $handle
 * @return void
 */
function photoedit_lock_release($handle)
{
  flock($handle, LOCK_UN);
  fclose($handle);
}

/**
 * @param string $dir
 * @return void
 */
function photoedit_make_dir($dir)
{
  if (!is_dir($dir) and !@mkdir($dir, 0755, true) and !is_dir($dir))
  {
    throw new RuntimeException('Cannot create '.$dir);
  }
}

/**
 * Removes an operation directory and the files in it.
 *
 * @param string $dir
 * @return void
 */
function photoedit_remove_dir($dir)
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
