<?php
defined('PERSONS_PATH') or die('Hacking attempt!');

/*
 * The persons list: who is in the index, on how many photos, and the three
 * things an administrator can do about it.
 *
 * Rename and delete reach past a single photo - both rewrite every file the
 * person appears in - so both go through the administrator-only web-service
 * methods rather than being done here. This file only renders; the writes all
 * happen where the permission model is stated once.
 *
 * admin/plugin.php has already run check_status(ACCESS_ADMINISTRATOR) before
 * including this; it is repeated so the file cannot be reached authorised by
 * its caller alone.
 */

check_status(ACCESS_ADMINISTRATOR);

include_once(PERSONS_PATH.'include/index.inc.php');
include_once(PERSONS_PATH.'include/exiftool.inc.php');

$query = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

// LIKE, not a full-text match: the picker searches the same way, and a list an
// administrator filters by typing part of a name must agree with it.
$where = '';
if ($query !== '')
{
  $where = " WHERE p.name LIKE '%".pwg_db_real_escape_string($query)."%'";
}

$persons = query2array('
SELECT p.id, p.name, p.url_name, p.tag_id,
       COUNT(r.id) AS regions,
       COUNT(DISTINCT r.image_id) AS photos
  FROM '.PERSONS_TABLE.' AS p
  LEFT JOIN '.PERSONS_REGION_TABLE.' AS r ON r.person_id = p.id
  '.$where.'
  GROUP BY p.id, p.name, p.url_name, p.tag_id
  ORDER BY p.name
;');

$rows = array();
$total_regions = 0;

foreach ($persons as $person)
{
  $total_regions += (int)$person['regions'];

  $rows[] = array(
    'ID'      => (int)$person['id'],
    'NAME'    => $person['name'],
    'PHOTOS'  => (int)$person['photos'],
    'REGIONS' => (int)$person['regions'],
    'URL'     => persons_person_gallery_url($person),
    );
}

// The client cuts the rescan into chunks itself, so it needs the ids up front -
// the same shape plugins/provenance uses on its album screen. One id list is
// cheaper than the paging round-trips a web-service listing would cost.
$photo_ids = query2array('SELECT id FROM '.IMAGES_TABLE.' ORDER BY id;', null, 'id');

$last_rescan = isset($conf[PERSONS_LAST_RESCAN_PARAM]) ? (string)$conf[PERSONS_LAST_RESCAN_PARAM] : '';

$template->assign(array(
  'PERSONS_PATH'          => PERSONS_PATH,
  'PERSONS_LIST'          => $rows,
  'PERSONS_QUERY'         => $query,
  'PERSONS_TOTAL_PERSONS' => count($rows),
  'PERSONS_TOTAL_REGIONS' => $total_regions,
  'PERSONS_PHOTO_IDS'     => implode(',', $photo_ids),
  'PERSONS_PHOTO_COUNT'   => count($photo_ids),
  'PERSONS_MAX_CHUNK'     => PERSONS_WRITEBACK_MAX_CHUNK,
  'PERSONS_LAST_RESCAN'   => $last_rescan,
  // A rescan reads every file with exiftool, so a host without it is offered
  // nothing to press rather than a button that can only fail.
  'PERSONS_EXIFTOOL'      => persons_exiftool_available(),
  'PERSONS_TOKEN'         => get_pwg_token(),
  ));

$template->set_filename('persons_admin_list', realpath(PERSONS_PATH.'template/admin_persons.tpl'));
$template->assign_var_from_handle('ADMIN_CONTENT', 'persons_admin_list');
