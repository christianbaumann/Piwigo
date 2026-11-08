<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * The change log entry photoedit's turns and crops get. A `photo` entry
 * records that the file was rewritten; it has no old value and offers no undo
 * (the original is kept under _data/photoedit/originals/, restored by hand).
 */

/**
 * Fields whose entries the undo must not offer. The one place undo reads.
 *
 * @return string[]
 */
function photoinfo_history_not_undoable_fields()
{
  return array(PROVENANCE_HISTORY_FIELD_PHOTO);
}

/**
 * @param string $field a PROVENANCE_HISTORY_FIELD_* value
 * @return bool
 */
function photoinfo_history_is_undoable($field)
{
  return !in_array($field, photoinfo_history_not_undoable_fields(), true);
}

/**
 * A photo entry's value: JSON {"turns": quarter turns clockwise (0-3),
 * "crop": {"w","h"} in pixels of the result, or null}. Formatted as
 * "Foto gedreht" when turns > 0 and crop is null, "Foto zugeschnitten" when
 * turns is 0 and crop is set, and both when both.
 *
 * @param array $transform photoedit's transform ('turns', 'crop_px')
 * @return string
 */
function photoinfo_history_photo_value($transform)
{
  $rect = $transform['crop_px'];

  return json_encode(array(
    'turns' => ((int)$transform['turns'] % 4 + 4) % 4,
    'crop' => $rect === null ? null : array('w' => (int)$rect['w'], 'h' => (int)$rect['h']),
    ));
}

/**
 * @param string|null $value a photo entry's value
 * @return array|null turns (int), crop (array|null); null for another shape
 */
function photoinfo_history_photo_parse($value)
{
  $data = is_string($value) ? json_decode($value, true) : null;
  if (!is_array($data) or !isset($data['turns']) or !is_int($data['turns']) or !array_key_exists('crop', $data))
  {
    return null;
  }

  return array('turns' => $data['turns'], 'crop' => $data['crop']);
}

/**
 * Records a written turn or crop. Nothing for a failed edit.
 *
 * @param int $image_id
 * @param array $transform
 * @param bool $ok whether photoedit wrote the edit
 * @return int|null the entry's id
 */
function photoinfo_record_photo_edit($image_id, $transform, $ok)
{
  if (!$ok or !photoinfo_history_available())
  {
    return null;
  }

  return photoinfo_history_insert((int)$image_id, PROVENANCE_HISTORY_FIELD_PHOTO,
    null, photoinfo_history_photo_value($transform), 'PICTURE_PAGE');
}
