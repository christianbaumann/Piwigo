<?php
/*
 * Pure functions: the replace option of Fotos hinzufügen refuses when more
 * than one photo in the album has the file name. No core dependency, so the
 * unit suite loads this file on its own.
 */

/**
 * The photos a replace would have to guess between, or null when there is no
 * guess: no photo has the name (core adds one) or exactly one has it (core
 * replaces that one).
 *
 * @param array $image_ids ids of the album's photos with the file name
 * @return string|null "#3, #7", in id order
 */
function galleryrules_replace_ambiguity($image_ids)
{
  $ids = array_unique(array_map('intval', $image_ids));
  if (count($ids) <= 1)
  {
    return null;
  }
  sort($ids);

  $named = array();
  foreach ($ids as $id)
  {
    $named[] = '#'.$id;
  }
  return implode(', ', $named);
}
