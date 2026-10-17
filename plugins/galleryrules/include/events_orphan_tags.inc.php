<?php
defined('GALLERYRULES_PATH') or die('Hacking attempt!');

/*
 * The unused-tag cleanup leaves coloured-group tags and a person's tag alone:
 * the groups hold their tags on purpose, and a person's tag is the person's.
 * Core's get_orphan_tags() feeds the tags page, both maintenance paths and the
 * confirm dialog's name list; its result passes through here.
 */

/**
 * get_orphan_tags (fork-local filter): drops the tags in a colour group and the
 * tags that belong to a person.
 *
 * @param array $tags rows with id and name
 * @return array the rows that are neither
 */
function galleryrules_orphan_tags_without_kept($tags)
{
  if (empty($tags))
  {
    return $tags;
  }

  $kept = array_merge(galleryrules_coloured_tag_ids($tags), galleryrules_person_tag_ids($tags));

  return array_values(array_filter($tags, function ($tag) use ($kept)
  {
    return !in_array((int)$tag['id'], $kept, true);
  }));
}

/**
 * @param array $tags rows with id
 * @return int[] ids of those in a colour group (empty while typetags has no column)
 */
function galleryrules_coloured_tag_ids($tags)
{
  $result = pwg_query('SHOW COLUMNS FROM '.TAGS_TABLE.' LIKE \'id_typetags\';');
  if (pwg_db_num_rows($result) == 0)
  {
    return array();
  }

  $query = '
SELECT id
  FROM '.TAGS_TABLE.'
  WHERE id IN ('.implode(',', array_map('intval', array_column($tags, 'id'))).')
    AND id_typetags IS NOT NULL
;';

  return array_map('intval', query2array($query, null, 'id'));
}

/**
 * @param array $tags rows with id
 * @return int[] ids of those a person owns (empty while persons has no table)
 */
function galleryrules_person_tag_ids($tags)
{
  $persons_table = $GLOBALS['prefixeTable'].'persons';
  $result = pwg_query('SHOW TABLES LIKE \''.$persons_table.'\';');
  if (pwg_db_num_rows($result) == 0)
  {
    return array();
  }

  $query = '
SELECT tag_id
  FROM '.$persons_table.'
  WHERE tag_id IN ('.implode(',', array_map('intval', array_column($tags, 'id'))).')
;';

  return array_map('intval', query2array($query, null, 'tag_id'));
}
