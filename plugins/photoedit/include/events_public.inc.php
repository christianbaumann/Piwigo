<?php
defined('PHOTOEDIT_PATH') or die('Hacking attempt!');

/**
 * Adds the edit button and its controls to the picture page's toolbar.
 *
 * Webmaster only: an edit rewrites the image file, which is the source of truth
 * for every other plugin and the only thing a deploy carries (decision 0034).
 * An administrator who is not a webmaster does not get it either.
 *
 * @return void
 */
function photoedit_picture_button()
{
  global $template, $picture;

  if (!is_webmaster())
  {
    return;
  }

  load_language('plugin.lang', PHOTOEDIT_PATH);
  include_once(PHOTOEDIT_PATH.'include/pipeline.inc.php');

  // The editor stays on the page when it cannot save, disabled and saying why,
  // rather than vanishing without explanation.
  // Probing exiftool starts a Perl process; once per session is enough for
  // the button, and a save probes again anyway.
  $reason = pwg_get_session_var('photoedit_unavailable');
  if ($reason === null)
  {
    $reason = photoedit_unavailable_reason();
    pwg_set_session_var('photoedit_unavailable', $reason);
  }
  if ($reason === ''
      and !in_array(strtolower(get_extension($picture['current']['path'])), photoedit_supported_extensions()))
  {
    $reason = l10n('This file type cannot be edited.');
  }

  $template->assign(array(
    'PHOTOEDIT_PATH' => PHOTOEDIT_PATH,
    'PHOTOEDIT_IMAGE_ID' => (int)$picture['current']['id'],
    'PHOTOEDIT_TOKEN' => get_pwg_token(),
    'PHOTOEDIT_WS_URL' => get_root_url().'ws.php?format=json',
    'PHOTOEDIT_UNAVAILABLE' => $reason,
    ));
  $template->set_filename('photoedit_button', realpath(PHOTOEDIT_PATH . 'template/picture_button.tpl'));
  $template->add_picture_button($template->parse('photoedit_button', true));
}
