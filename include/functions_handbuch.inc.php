<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * Fork-local: which file under handbuch/ handbuch.php may serve (decision 0063).
 * Declares functions and constants only, so it loads without Piwigo.
 *
 * @package functions\handbuch
 */

/** The file types the handbook is made of, and the content type each is sent with. */
const HANDBUCH_CONTENT_TYPES = array(
  'html' => 'text/html; charset=utf-8',
  'css' => 'text/css; charset=utf-8',
  'png' => 'image/png',
  );

/** The page a bare /handbuch/ request gets. */
const HANDBUCH_INDEX = 'index.html';

/** The handbook's dev tooling, never served. */
const HANDBUCH_TOOLS_DIR = 'tools/';

/**
 * Resolves a requested handbook path to a file that may be served.
 *
 * @param string $handbuch_dir absolute path of the handbuch/ directory
 * @param string $requested path relative to it, as the rewrite passes it
 * @return string|null absolute path of the file, or null when it is refused
 */
function handbuch_resolve($handbuch_dir, $requested)
{
  if ($requested === '' or substr($requested, -1) === '/')
  {
    $requested.= HANDBUCH_INDEX;
  }

  if (strpos($requested, "\0") !== false or strpos($requested, '\\') !== false or $requested[0] === '/')
  {
    return null;
  }

  foreach (explode('/', $requested) as $segment)
  {
    if ($segment === '' or $segment[0] === '.')
    {
      return null;
    }
  }

  if (stripos($requested, HANDBUCH_TOOLS_DIR) === 0)
  {
    return null;
  }

  $extension = strtolower(pathinfo($requested, PATHINFO_EXTENSION));
  if (!isset(HANDBUCH_CONTENT_TYPES[$extension]))
  {
    return null;
  }

  $base = realpath($handbuch_dir);
  $file = realpath($base.'/'.$requested);
  if ($base === false or $file === false or !is_file($file) or strpos($file, $base.'/') !== 0)
  {
    return null;
  }

  return $file;
}

/**
 * The gallery root relative to the URL the browser asked for. Through the rewrite that URL is
 * /handbuch/<requested>, so core's relative links (the login redirect) need to climb out of it.
 *
 * @param string $request_path path of the URL the browser asked for
 * @param string $requested path relative to handbuch/, as the rewrite passes it
 * @return string
 */
function handbuch_root_path($request_path, $requested)
{
  if (substr($request_path, -strlen('/handbuch.php')) === '/handbuch.php')
  {
    return './';
  }
  return str_repeat('../', substr_count($requested, '/') + 1);
}

/**
 * The content type a resolved handbook file is sent with.
 *
 * @param string $file a path handbuch_resolve() returned
 * @return string
 */
function handbuch_content_type($file)
{
  return HANDBUCH_CONTENT_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))];
}
