<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Fork-local: serves the German handbook to logged-in users only (decision 0063).
// /handbuch/<file> is rewritten here by .ddev/nginx/handbuch.conf and handbuch/.htaccess.

// Under Apache the rewrite leaves REDIRECT_URL=/handbuch/..., which core's cookie_path() would
// scope a new session cookie to; that cookie then shadows the one the login sets at the root.
unset($_SERVER['REDIRECT_URL'], $_SERVER['REDIRECT_SCRIPT_NAME']);

define('PHPWG_ROOT_PATH','./');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');
include_once(PHPWG_ROOT_PATH.'include/functions_handbuch.inc.php');

$requested = isset($_GET['f']) ? (string)$_GET['f'] : '';
$page['root_path'] = handbuch_root_path((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $requested);

if (is_a_guest())
{
  access_denied();
}

$file = handbuch_resolve(PHPWG_ROOT_PATH.'handbuch', $requested);
if ($file === null)
{
  set_status_header(404);
  echo l10n('Page not found');
  exit();
}

header('Content-Type: '.handbuch_content_type($file));
header('Content-Length: '.filesize($file));
readfile($file);
