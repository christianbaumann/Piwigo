<?php
/*
 * Pure functions for pwg.images.addSimple. No core dependency, so the unit
 * suite loads this file on its own.
 */

/**
 * Why an addSimple of a file identical to existing photos is refused, naming
 * each of them. Core sends the message as the HTTP status text too, so each run of
 * control characters, line breaks included, becomes one space.
 *
 * @param array $photos each: id, name (may be empty), file
 * @return string
 */
function galleryrules_identical_file_message($photos)
{
  $named = array();
  foreach ($photos as $photo)
  {
    $label = '#'.(int)$photo['id'];
    if ((string)$photo['name'] !== '')
    {
      $label.= ' "'.$photo['name'].'"';
    }
    $named[] = $label.' ('.$photo['file'].')';
  }

  $message = count($photos) > 1
    ? 'The file is identical to photos '.implode(', ', $named).', which were left unchanged.'
    : 'The file is identical to photo '.implode(', ', $named).', which was left unchanged.';

  return preg_replace('/[\x00-\x1f\x7f]+/', ' ', $message);
}
