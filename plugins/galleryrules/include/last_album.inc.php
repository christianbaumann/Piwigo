<?php
/*
 * Pure functions: a photo's last album cannot be removed. Each function says
 * which albums a photo is left in after one of core's removing paths; none of
 * them touches the database, so the unit suite loads this file on its own.
 */

/**
 * The albums a photo keeps after dissociate_images_from_category(), which
 * never breaks the link to the photo's storage album.
 *
 * @param int[] $albums the photo's albums now
 * @param int|null $storage its storage album
 * @param int $album the album it is taken out of
 * @return int[]
 */
function galleryrules_albums_after_dissociate($albums, $storage, $album)
{
  $album = (int)$album;
  if ($storage !== null and (int)$storage === $album)
  {
    return galleryrules_unique_ids($albums);
  }
  return array_values(array_diff(galleryrules_unique_ids($albums), array($album)));
}

/**
 * The albums a photo keeps after move_images_to_categories(): the targets,
 * plus its storage album when it is in it now.
 *
 * @param int[] $albums the photo's albums now
 * @param int|null $storage its storage album
 * @param int[] $targets the albums it is moved to
 * @return int[]
 */
function galleryrules_albums_after_move($albums, $storage, $targets)
{
  $after = galleryrules_unique_ids($targets);
  $albums = galleryrules_unique_ids($albums);
  if ($storage !== null and in_array((int)$storage, $albums, true))
  {
    $after[] = (int)$storage;
  }
  return galleryrules_unique_ids($after);
}

/**
 * The album ids in pwg.images.setInfo's "categories", read as
 * ws_add_image_category_relations() reads it: "id[,rank];id[,rank]", tokens
 * whose id is not a number skipped.
 *
 * @param string|null $categories
 * @return int[]
 */
function galleryrules_category_string_ids($categories)
{
  $ids = array();
  foreach (explode(';', (string)$categories) as $token)
  {
    $parts = explode(',', $token);
    if (preg_match('/^\d+$/', $parts[0]))
    {
      $ids[] = (int)$parts[0];
    }
  }
  return galleryrules_unique_ids($ids);
}

/**
 * The photos a change would leave in no album: in at least one now, in none
 * after. A photo in no album already is not the change's doing.
 *
 * @param array $before image id => int[] albums now
 * @param array $after image id => int[] albums after the change
 * @return int[] image ids, ascending
 */
function galleryrules_left_without_album($before, $after)
{
  $left = array();
  foreach ($before as $image_id => $albums)
  {
    if (count($albums) > 0 and empty($after[$image_id]))
    {
      $left[] = (int)$image_id;
    }
  }
  sort($left);
  return $left;
}

/**
 * The photos deleting albums while keeping their photos (core's
 * delete_categories() mode no_delete) would leave in no album. A photo stored
 * in one of the albums is deleted by core with them, so it is not left.
 *
 * @param array $photos image id => array('albums' => int[], 'storage' => int|null)
 * @param int[] $deleted the albums deleted, sub-albums included
 * @return int[] image ids, ascending
 */
function galleryrules_left_by_album_delete($photos, $deleted)
{
  $deleted = galleryrules_unique_ids($deleted);
  $before = $after = array();
  foreach ($photos as $image_id => $photo)
  {
    if ($photo['storage'] !== null and in_array((int)$photo['storage'], $deleted, true))
    {
      continue;
    }
    $before[$image_id] = $photo['albums'];
    $after[$image_id] = array_diff(galleryrules_unique_ids($photo['albums']), $deleted);
  }
  return galleryrules_left_without_album($before, $after);
}

/**
 * @param array $ids
 * @return int[] unique, ascending
 */
function galleryrules_unique_ids($ids)
{
  $ids = array_unique(array_map('intval', (array)$ids));
  sort($ids);
  return $ids;
}
