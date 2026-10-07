<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * The file side: what photoinfo puts into an image file, through provenance's
 * exiftool runner and under provenance's lock - one lock file per image, which
 * plugins/photoedit's edits respect as well.
 */

/**
 * provenance_caption_parts: puts the photo's info text first in the caption.
 *
 * Provenance's own write-back selects no comment column, so the text is read
 * here when the row does not carry it.
 *
 * @param array $blocks name => text
 * @param array $image the image row: id, and comment when the caller has it
 * @return array
 */
function photoinfo_caption_parts_handler($blocks, $image)
{
  if (array_key_exists('comment', $image))
  {
    $info = $image['comment'];
  }
  else
  {
    $query = '
SELECT comment
  FROM '.IMAGES_TABLE.'
  WHERE id = '.(int)$image['id'].'
;';
    $row = pwg_db_fetch_assoc(pwg_query($query));
    $info = empty($row) ? '' : $row['comment'];
  }

  return photoinfo_caption_blocks($blocks, $info);
}

/**
 * Writes one photo's composed caption and its info text into its file.
 *
 * @param array $image id, path, comment and provenance's photo columns
 * @return array array('ok' => bool, 'message' => string)
 */
function photoinfo_write_file($image)
{
  if (!provenance_exiftool_available())
  {
    return array('ok' => false, 'message' => 'exiftool is not available on this server');
  }

  $file = provenance_image_file_path($image['path']);
  if (!is_file($file) or !is_writable($file))
  {
    return array('ok' => false, 'message' => 'File is missing or not writable');
  }

  load_language('plugin.lang', PROVENANCE_PATH);
  $labels = array_map('l10n', provenance_caption_label_keys());

  $blocks = trigger_change('provenance_caption_parts',
    array('provenance' => provenance_caption_block($image, $labels)),
    $image
    );

  $caption = provenance_join_caption_blocks($blocks);
  $info = (string)$image['comment'];

  $operation_dir = provenance_operation_dir(provenance_operation_id());
  $value_prefix = $operation_dir.(int)$image['id'].'-';
  $lines = photoinfo_build_argfile($caption, $info, $value_prefix);

  try
  {
    provenance_make_dir($operation_dir);

    $value_files = provenance_argfile_value_files(photoinfo_argfile_values($caption, $info), $value_prefix);
    foreach ($value_files as $path => $content)
    {
      file_put_contents($path, $content);
    }

    $argfile = $operation_dir.(int)$image['id'].'.args';
    file_put_contents($argfile, implode("\n", $lines)."\n");

    return provenance_exiftool_run($argfile, $file, $image['path'], PHOTOINFO_XMP_CONFIG);
  }
  finally
  {
    provenance_remove_dir($operation_dir);
  }
}
