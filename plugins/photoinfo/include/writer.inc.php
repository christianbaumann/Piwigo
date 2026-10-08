<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * The file side: what photoinfo puts into an image file, through provenance's
 * exiftool runner and under provenance's lock - one lock file per image, which
 * plugins/photoedit's edits respect as well.
 */

/**
 * provenance_caption_parts: puts the photo's date and info text first in the
 * caption.
 *
 * Provenance's own write-back selects none of photoinfo's columns, so they are
 * read here when the row does not carry them.
 *
 * @param array $blocks name => text
 * @param array $image the image row: id, and photoinfo_written_columns() when the caller has them
 * @return array
 */
function photoinfo_caption_parts_handler($blocks, $image)
{
  $columns = photoinfo_written_columns();

  if (count(array_diff($columns, array_keys($image))) > 0)
  {
    $query = '
SELECT '.implode(', ', $columns).'
  FROM '.IMAGES_TABLE.'
  WHERE id = '.(int)$image['id'].'
;';
    $row = pwg_db_fetch_assoc(pwg_query($query));
    // What the caller passed wins over what is stored.
    $image = array_merge(empty($row) ? array_fill_keys($columns, null) : $row, $image);
  }

  return photoinfo_caption_blocks($blocks, $image['comment'], photoinfo_dating_display(photoinfo_dating_from_row($image)));
}

/** What a file write sets beside the caption: the info text, or the date. */
define('PHOTOINFO_WRITE_INFO', 'info');
define('PHOTOINFO_WRITE_DATE', 'date');

/**
 * Writes one photo's composed caption into its file, with either its info text
 * or its date beside it.
 *
 * @param array $image id, path, photoinfo_written_columns() and provenance's photo columns
 * @param string $field PHOTOINFO_WRITE_INFO or PHOTOINFO_WRITE_DATE
 * @return array array('ok' => bool, 'message' => string)
 */
function photoinfo_write_file($image, $field)
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

  if ($field == PHOTOINFO_WRITE_DATE)
  {
    $lines = photoinfo_build_date_argfile($caption, photoinfo_dating_from_row($image), $value_prefix);
    $values = provenance_caption_values(provenance_normalize_caption($caption));
  }
  else
  {
    $lines = photoinfo_build_argfile($caption, $info, $value_prefix);
    $values = photoinfo_argfile_values($caption, $info);
  }

  try
  {
    provenance_make_dir($operation_dir);

    foreach (provenance_argfile_value_files($values, $value_prefix) as $path => $content)
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
