<?php
defined('PROVENANCE_PATH') or die('Hacking attempt!');

/**
 * The read-only provenance page, a public section of index.php, and the link to
 * it from an album page. Pulled in only on index.php.
 *
 * Every logged-in account may open it for an album or photo it can see. It shows
 * data and nothing else: the forms that change provenance stay on the admin
 * screens (docs/agents/decisions/0064-normal-users-read-provenance-and-tag-groups-on-read-only-pages.md).
 */

/**
 * Takes over the request when its URL is the page's, before index.php builds a
 * thumbnail page. Guests go to the login page; an album or photo the account
 * cannot see is not found.
 */
function provenance_page_section_init()
{
  global $tokens, $page, $template, $conf;

  $route = provenance_page_route(isset($tokens) ? $tokens : array());
  if ($route === null)
  {
    return;
  }

  // Every logged-in account, generic included: ACCESS_CLASSIC would refuse generic.
  if (is_a_guest())
  {
    access_denied();
  }
  load_language('plugin.lang', PROVENANCE_PATH);

  if ($route['object'] === null)
  {
    page_not_found(l10n('Page not found'));
  }

  $data = $route['object'] == 'album'
    ? provenance_page_album($route['id'])
    : provenance_page_photo($route['id']);

  if ($data === null)
  {
    page_not_found(l10n('Page not found'));
  }

  $history = provenance_fetch_history($route['object'], $route['id'], PROVENANCE_HISTORY_PER_PAGE_DEFAULT);

  $title = l10n('Provenance').': '.$data['name'];

  $page['section'] = PROVENANCE_PAGE_SECTION;
  $page['is_external'] = true;
  $page['items'] = array();
  $page['start'] = 0;
  $page['title'] = strip_tags($title);
  // The name is already HTML: core's render events made it, and galleryrules
  // escapes it there (decision 0073). Escaping it again would show "&amp;".
  $page['section_title'] = '<a href="'.get_gallery_home_url().'">'.l10n('Home').'</a>'
    .$conf['level_separator'].$title;
  unset($page['category'], $page['is_homepage']);

  $template->assign('PROVENANCE_PAGE', array(
    'OBJECT' => $route['object'],
    'VALUES' => $data['values'],
    'BACK_URL' => $data['url'],
    'BACK_NAME' => $data['name'],
    'HISTORY' => provenance_page_history_rows($history['rows']),
    'HISTORY_SHOWN' => count($history['rows']),
    'HISTORY_TOTAL' => $history['total_count'],
    ));
  $template->assign('PROVENANCE_PATH', PROVENANCE_PATH);
}

/**
 * One album the account can see, with its four values, or null.
 *
 * @param int $cat_id
 * @return array|null name, url, values
 */
function provenance_page_album($cat_id)
{
  $query = '
SELECT id, name, permalink, provenance_physical_album, provenance_owner,
       provenance_scanned_on, provenance_note
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.(int)$cat_id.'
  '.get_sql_condition_FandF(
      array('forbidden_categories' => 'id', 'visible_categories' => 'id'),
      'AND'
      ).'
;';
  $rows = query2array($query);
  if (empty($rows))
  {
    return null;
  }
  $album = $rows[0];

  $labels = array(
    'provenance_physical_album' => 'Physical album',
    'provenance_owner' => 'Owner',
    'provenance_scanned_on' => 'Scanned on',
    'provenance_note' => 'Note',
    );

  return array(
    'name' => trigger_change('render_category_name', $album['name'], 'provenance_page'),
    'url' => make_index_url(array('category' => $album)),
    'values' => provenance_page_values($album, $labels),
    );
}

/**
 * One photo the account can see through at least one of its albums, with the
 * values it carries, or null. A photo in no album is not found.
 *
 * @param int $image_id
 * @return array|null name, url, values
 */
function provenance_page_photo($image_id)
{
  $query = '
SELECT i.id, i.file, i.name, provenance_physical_album, provenance_owner,
       provenance_scanned_on, provenance_album_note, provenance_note
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE i.id = '.(int)$image_id.'
  '.get_sql_condition_FandF(
      array(
        'forbidden_categories' => 'ic.category_id',
        'visible_categories' => 'ic.category_id',
        'visible_images' => 'i.id',
        ),
      'AND'
      ).'
  LIMIT 1
;';
  $rows = query2array($query);
  if (empty($rows))
  {
    return null;
  }
  $photo = $rows[0];

  return array(
    'name' => render_element_name($photo),
    'url' => make_picture_url(array('image_id' => $photo['id'], 'image_file' => $photo['file'])),
    'values' => provenance_page_values($photo, provenance_caption_label_keys()),
    );
}

/**
 * The labelled values, in the order given, empty ones included: a page about
 * provenance says that a field is empty rather than leaving it out.
 *
 * @param array $row
 * @param array $label_keys column => l10n key
 * @return array
 */
function provenance_page_values($row, $label_keys)
{
  $values = array();
  foreach ($label_keys as $column => $key)
  {
    $values[] = array(
      'FIELD' => $column,
      'LABEL' => l10n($key),
      'VALUE' => isset($row[$column]) ? (string)$row[$column] : '',
      );
  }
  return $values;
}

/**
 * History rows shaped for the template: labels, a readable date and the name
 * of who made the change.
 *
 * @param array $rows from provenance_fetch_history()
 * @return array
 */
function provenance_page_history_rows($rows)
{
  global $conf;

  $user_ids = array();
  foreach ($rows as $row)
  {
    if ($row['performed_by'] !== null)
    {
      $user_ids[$row['performed_by']] = true;
    }
  }

  $names = array();
  if (!empty($user_ids))
  {
    $names = query2array('
SELECT '.$conf['user_fields']['id'].' AS id, '.$conf['user_fields']['username'].' AS username
  FROM '.USERS_TABLE.'
  WHERE '.$conf['user_fields']['id'].' IN ('.implode(',', array_keys($user_ids)).')
;', 'id', 'username');
  }

  $field_keys = provenance_history_field_label_keys();
  $source_keys = provenance_history_source_label_keys();

  $shaped = array();
  foreach ($rows as $row)
  {
    // exiftool's message names server paths; only administrators read it.
    if ($row['field'] === PROVENANCE_HISTORY_FIELD_FILE_ERROR and !is_admin())
    {
      $row['new_value'] = l10n('The file could not be written');
    }
    $shaped[] = array(
      'DATE' => format_date($row['occured_on'], array('day', 'month', 'year', 'time')),
      'FIELD' => isset($field_keys[$row['field']]) ? l10n($field_keys[$row['field']]) : $row['field'],
      'OLD' => (string)$row['old_value'],
      'NEW' => (string)$row['new_value'],
      'SOURCE' => isset($source_keys[$row['source']]) ? l10n($source_keys[$row['source']]) : $row['source'],
      'BY' => isset($names[$row['performed_by']]) ? $names[$row['performed_by']] : '',
      );
  }
  return $shaped;
}

/**
 * Renders the page into the content slot of index.tpl, and puts the link to it
 * among an album page's buttons.
 */
function provenance_page_end_index()
{
  global $page, $template;

  if ($page['section'] == PROVENANCE_PAGE_SECTION)
  {
    $template->set_filename('provenance_page', realpath(PROVENANCE_PATH.'template/provenance_page.tpl'));
    $template->assign_var_from_handle('CONTENT', 'provenance_page');
    return;
  }

  if ($page['section'] == 'categories' and isset($page['category']) and !is_a_guest())
  {
    load_language('plugin.lang', PROVENANCE_PATH);

    $template->add_index_button(
      '<a id="provenancePageLink" href="'
      .make_index_url(array('section' => provenance_page_section('album', $page['category']['id'])))
      .'" title="'.l10n('Provenance').'" class="pwg-state-default pwg-button" rel="nofollow">'
      .'<span class="pwg-icon gallery-icon-album"></span>'
      .'<span class="pwg-button-text">'.l10n('Provenance').'</span></a>'
      );
  }
}
