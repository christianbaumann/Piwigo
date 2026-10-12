<?php
defined('PHOTOEDIT_PATH') or die('Hacking attempt!');

/**
 * Adds the edit button and its controls to the picture page's toolbar.
 *
 * For every logged-in account that may see the photo (decision 0059), the
 * same gate pwg.photoedit.apply applies.
 *
 * @return void
 */
function photoedit_picture_button()
{
  global $template, $picture;

  include_once(PHOTOEDIT_PATH.'include/pipeline.inc.php');

  if (!photoedit_user_may_edit($picture['current']['id']))
  {
    return;
  }

  load_language('plugin.lang', PHOTOEDIT_PATH);

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
