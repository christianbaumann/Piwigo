<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Reads the provenance audit trail for one object.
 *
 * Follows the shape of ws_getActivityList() (include/ws_functions/pwg.php:453)
 * rather than inventing a new one, but returns the rows unaggregated: this is a
 * value-level trail, and collapsing consecutive rows the way the activity screen
 * does would hide exactly what it exists to show.
 *
 * @param array $param
 * @param object $service
 * @return array|PwgError
 */
function ws_provenance_getHistory($param, &$service)
{
  if (!in_array($param['object'], provenance_history_objects(), true))
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid object');
  }

  $where = array(
    'object = "'.pwg_db_real_escape_string($param['object']).'"',
    'object_id = '.(int)$param['object_id'],
    );

  foreach (array('date_min' => '>=', 'date_max' => '<=') as $datefield => $operator)
  {
    if (empty($param[$datefield]))
    {
      continue;
    }

    if (!is_valid_mysql_datetime($param[$datefield]))
    {
      return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid '.$datefield);
    }

    $where[] = 'occured_on '.$operator.' "'.pwg_db_real_escape_string($param[$datefield]).'"';
  }

  $where = 'WHERE '.implode("\n    AND ", $where);

  list($total_count) = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*)
  FROM '.PROVENANCE_HISTORY_TABLE.'
  '.$where.'
;'));

  $rows = query2array('
SELECT id, object, object_id, field, old_value, new_value, source, performed_by, occured_on
  FROM '.PROVENANCE_HISTORY_TABLE.'
  '.$where.'
  ORDER BY occured_on DESC, id DESC
  LIMIT '.(int)$param['per_page'].'
;');

  foreach ($rows as $i => $row)
  {
    $rows[$i]['id'] = (int)$row['id'];
    $rows[$i]['object_id'] = (int)$row['object_id'];
    $rows[$i]['performed_by'] = $row['performed_by'] === null ? null : (int)$row['performed_by'];
  }

  return array(
    'paging' => array(
      'per_page' => (int)$param['per_page'],
      'count' => count($rows),
      'total_count' => (int)$total_count,
      ),
    'histories' => $rows,
    );
}
