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
 * What happens to a photo that already carries provenance is the mode's
 * decision, not this file's. Core gives no way to tell a move from a plain
 * association at the trigger, so the choice arrives as an explicit request
 * parameter and defaults to keep - a photo never has its provenance rewritten
 * by a link it did not ask for. Only apply, the deliberate admin action,
 * overwrites unconditionally (decision C3).
 *
 * Two rules hold under every mode: the photo's own provenance_note is never
 * touched, and a field whose value did not change writes no history row.
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
 * Applies one album's provenance to the given photos, under the request's mode.
 *
 *  - keep (the default): the album's values land only on photos that carry none
 *    of the four yet. A photo that already has provenance is left alone, so a
 *    move never silently rewrites where a scan came from.
 *  - replace: every named photo takes the album's values.
 *  - clear: every named photo has the four emptied, whatever the album holds.
 *
 * Under keep and replace an album with nothing recorded is skipped outright
 * rather than writing four blanks: inheritance exists to carry a fact down, and
 * an album that has none must not quietly erase what a photo already carries
 * from somewhere else. Clear is the mode that says to erase it, and says so.
 *
 * @param int $category_id
 * @param array $image_ids
 * @param string|null $mode one of provenance_move_modes(); read from the
 *        request when omitted
 * @return int history rows written
 */
function provenance_inherit_into($category_id, $image_ids, $mode = null)
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

  if ($mode === null)
  {
    $mode = provenance_resolve_mode($_POST, PROVENANCE_MOVE_MODE_PARAM, provenance_move_modes());
  }

  if ($mode == PROVENANCE_MODE_CLEAR)
  {
    return provenance_write_inherited(
      $ids,
      array_fill_keys(array_values(provenance_copy_down_map()), ''),
      PROVENANCE_HISTORY_SOURCE_MOVE
      );
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

  if ($mode == PROVENANCE_MODE_KEEP)
  {
    $ids = provenance_photos_without_provenance($ids);

    if (empty($ids))
    {
      return 0;
    }
  }

  return provenance_write_inherited(
    $ids,
    $values,
    $mode == PROVENANCE_MODE_KEEP ? 'inherit' : PROVENANCE_HISTORY_SOURCE_MOVE
    );
}

/**
 * Narrows a set of photos to those carrying none of the four album-sourced
 * values.
 *
 * A photo carrying any one of them has a provenance already, and keep leaves it
 * whole rather than filling the gaps from a different album - a half-and-half
 * record would say a scan came from two places at once.
 *
 * @param array $ids image id => image id
 * @return array the same shape, narrowed
 */
function provenance_photos_without_provenance($ids)
{
  $columns = array_values(provenance_copy_down_map());

  $empty = array();
  foreach ($columns as $column)
  {
    $empty[] = "(`$column` IS NULL OR `$column` = '')";
  }

  $found = query2array('
SELECT id
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', $ids).')
    AND '.implode("\n    AND ", $empty).'
;', null, 'id');

  $narrowed = array();
  foreach ($found as $id)
  {
    $narrowed[(int)$id] = (int)$id;
  }

  return $narrowed;
}

/**
 * Writes one set of values onto a set of photos and records what changed.
 *
 * The single write path behind every mode, so a mode can change what is written
 * but never whether it is logged.
 *
 * @param array $ids image id => image id
 * @param array $values image column => value ('' clears)
 * @param string $source history source
 * @return int history rows written
 */
function provenance_write_inherited($ids, $values, $source)
{
  // Read before the update, so the history rows say what really changed.
  $before = query2array('
SELECT id, '.implode(', ', array_keys($values)).'
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', $ids).')
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

  // Deliberately without MASS_UPDATES_SKIP_EMPTY: clear has to reach the
  // columns, not be skipped for being empty.
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
        'source'    => $source,
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
