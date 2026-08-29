<?php
defined('PROVENANCE_PATH') or die('Hacking attempt!');

/*
 * Inheritance: a photo that joins an album afterwards gets that album's
 * provenance without anyone re-running the copy-down by hand.
 *
 * Two core trigger_notify() calls feed this file. Everything that creates a
 * virtual link funnels through associate_images_to_categories() - the API, the
 * Batch Manager, the uploader - while admin/site_update.php inserts its storage
 * links directly and so needs a second entry point.
 *
 * The rules are the copy-down's rules, deliberately: the four album-sourced
 * columns are album-authoritative and are overwritten, the photo's own
 * provenance_note is never touched, and a field whose value did not change
 * writes no history row.
 */

/**
 * Handles the virtual-link funnel.
 *
 * Every named photo is now in every named album, so the pairs are the full
 * cross product.
 *
 * @param array $data image_ids, category_ids
 */
function provenance_inherit_associated($data)
{
  foreach ($data['category_ids'] as $category_id)
  {
    provenance_inherit_into($category_id, $data['image_ids']);
  }
}

/**
 * Handles the filesystem sync, whose payload is the link rows themselves.
 *
 * Grouped by album first: one album carries one set of values, so a directory
 * holding fifty new scans costs one read and one update rather than fifty.
 *
 * @param array $links each: image_id, category_id
 */
function provenance_inherit_site_update($links)
{
  $by_category = array();

  foreach ($links as $link)
  {
    $by_category[ (int)$link['category_id'] ][] = (int)$link['image_id'];
  }

  foreach ($by_category as $category_id => $image_ids)
  {
    provenance_inherit_into($category_id, $image_ids);
  }
}

/**
 * Copies one album's provenance onto the given photos.
 *
 * An album with nothing recorded is skipped outright rather than writing four
 * NULLs: inheritance exists to carry a fact down, and an album that has none
 * must not quietly erase what a photo already carries from somewhere else.
 *
 * @param int $category_id
 * @param array $image_ids
 * @return int history rows written
 */
function provenance_inherit_into($category_id, $image_ids)
{
  $category_id = (int)$category_id;

  $ids = array();
  foreach ($image_ids as $image_id)
  {
    $image_id = (int)$image_id;
    if ($image_id > 0)
    {
      $ids[$image_id] = $image_id;
    }
  }

  if ($category_id <= 0 or empty($ids))
  {
    return 0;
  }

  $result = pwg_query('
SELECT '.implode(', ', array_keys(provenance_album_columns())).'
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.$category_id.'
;');

  if (!pwg_db_num_rows($result))
  {
    return 0;
  }

  $album = pwg_db_fetch_assoc($result);

  if (!provenance_album_has_values($album))
  {
    return 0;
  }

  // The copy-down target columns, and what the album puts in each of them.
  $values = array();
  foreach (provenance_copy_down_map() as $album_column => $image_column)
  {
    $values[$image_column] = (string)$album[$album_column];
  }

  $id_list = implode(',', $ids);

  // Read before the update, so the history rows say what really changed.
  $before = query2array('
SELECT id, '.implode(', ', array_keys($values)).'
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.$id_list.')
;', 'id');

  // mass_updates() applies no escaping of its own.
  $escaped = array();
  foreach ($values as $column => $value)
  {
    $escaped[$column] = pwg_db_real_escape_string($value);
  }

  $datas = array();
  foreach ($ids as $image_id)
  {
    $datas[] = array_merge(array('id' => $image_id), $escaped);
  }

  mass_updates(
    IMAGES_TABLE,
    array('primary' => array('id'), 'update' => array_keys($values)),
    $datas
    );

  $changes = array();
  foreach ($ids as $image_id)
  {
    foreach ($values as $column => $value)
    {
      $changes[] = array(
        'object'    => 'photo',
        'object_id' => $image_id,
        'field'     => $column,
        'old'       => isset($before[$image_id]) ? $before[$image_id][$column] : null,
        'new'       => $value,
        'source'    => 'inherit',
        );
    }
  }

  return provenance_record_changes($changes);
}

/**
 * Whether an album has any provenance worth handing down.
 *
 * @param array $album album column => value
 * @return bool
 */
function provenance_album_has_values($album)
{
  foreach (array_keys(provenance_album_columns()) as $column)
  {
    if (isset($album[$column]) and trim((string)$album[$column]) !== '')
    {
      return true;
    }
  }

  return false;
}
