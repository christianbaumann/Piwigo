<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * Fork-local, temporary: the remote half of pwg-backup (tools/deploy, change
 * 2026-10-20-remote-backup). Rendered per run with the token's sha256, an expiry and the dump
 * directory, uploaded under a random name and removed by its own cleanup action. It answers
 * only a POST carrying the token before the expiry. It sits in the web root, so it is never
 * deployed: tools/ is not published.
 */

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');

define('AGENT_TOKEN_SHA256', '@@TOKEN_SHA256@@');
define('AGENT_EXPIRES', '@@EXPIRES@@');
define('AGENT_DUMP_DIR', '@@DUMP_DIR@@');
define('AGENT_MYSQLDUMP', '@@MYSQLDUMP@@');

define('AGENT_DUMP_FILE', 'dump.sql.gz');
define('AGENT_PLAIN_DUMP_FILE', 'dump.sql');
define('AGENT_DEFAULTS_FILE', 'client.cnf');
define('AGENT_CREATED_MARKER', '.created-by-agent');
define('AGENT_INSERT_BATCH_ROWS', 200);
define('AGENT_LISTED_DIRS', 'upload,galleries,_data/photoedit/originals');
define('AGENT_COUNTED_TABLES', 'images,categories,tags,users');
define('AGENT_CHARSET', 'utf8mb4');

/** A failure whose message is safe to show the caller. */
class AgentFailure extends Exception
{
  public $status = 500;

  public function __construct($message, $status = 500)
  {
    parent::__construct($message);
    $this->status = $status;
  }
}

function agent_answer($status, $data)
{
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data);
  exit;
}

function agent_refuse($message)
{
  agent_answer(403, array('error' => $message));
}

function agent_dump_dir()
{
  $dir = AGENT_DUMP_DIR;
  return (substr($dir, 0, 1) === '/') ? $dir : __DIR__.'/'.$dir;
}

function agent_database()
{
  $conf = array();
  $prefixeTable = '';
  include(__DIR__.'/local/config/database.inc.php');

  $host = $conf['db_host'];
  $port = null;
  if (strpos($host, ':') !== false)
  {
    list($host, $port) = explode(':', $host, 2);
  }

  mysqli_report(MYSQLI_REPORT_OFF);
  $db = @mysqli_connect($host, $conf['db_user'], $conf['db_pw'], $conf['db_base'], $port ? (int) $port : null);
  if (!$db)
  {
    throw new AgentFailure('database connection failed');
  }
  mysqli_set_charset($db, AGENT_CHARSET);

  return array($db, $conf, $prefixeTable);
}

function agent_query($db, $sql)
{
  $result = mysqli_query($db, $sql);
  if ($result === false)
  {
    throw new AgentFailure('database query failed');
  }
  return $result;
}

function agent_ensure_dump_dir()
{
  $dir = agent_dump_dir();
  $created = false;
  if (!is_dir($dir))
  {
    if (!@mkdir($dir, 0700, true))
    {
      throw new AgentFailure('dump directory could not be created');
    }
    $created = true;
  }
  if (!is_file($dir.'/.htaccess'))
  {
    $written = @file_put_contents(
      $dir.'/.htaccess',
      "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
      ."<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n"
      );
    if ($written === false)
    {
      throw new AgentFailure('dump directory could not be protected');
    }
  }
  if ($created)
  {
    file_put_contents($dir.'/'.AGENT_CREATED_MARKER, '');
  }
  return $dir;
}

function agent_counts($db, $prefix)
{
  $counts = array();
  foreach (explode(',', AGENT_COUNTED_TABLES) as $table)
  {
    $row = mysqli_fetch_row(agent_query($db, 'SELECT COUNT(*) FROM `'.$prefix.$table.'`'));
    $counts[$table] = (int) $row[0];
  }
  return $counts;
}

function agent_mysqldump_available()
{
  $output = array();
  $code = 1;
  @exec(escapeshellarg(AGENT_MYSQLDUMP).' --version 2>&1', $output, $code);
  return $code === 0;
}

function agent_dump_with_mysqldump($dir, $conf)
{
  $host = $conf['db_host'];
  $port = '';
  if (strpos($host, ':') !== false)
  {
    list($host, $port) = explode(':', $host, 2);
  }

  $defaults = $dir.'/'.AGENT_DEFAULTS_FILE;
  $lines = "[client]\nuser=".agent_option_value($conf['db_user'])."\n"
    .'password='.agent_option_value($conf['db_pw'])."\nhost=".agent_option_value($host)."\n"
    .($port !== '' ? 'port='.(int) $port."\n" : '');
  $plain = $dir.'/'.AGENT_PLAIN_DUMP_FILE;
  $command = escapeshellarg(AGENT_MYSQLDUMP)
    .' --defaults-extra-file='.escapeshellarg($defaults)
    .' --single-transaction --default-character-set='.AGENT_CHARSET
    .' --result-file='.escapeshellarg($plain)
    .' '.escapeshellarg($conf['db_base']).' 2>&1';
  $output = array();
  $code = 1;
  $old_umask = umask(0077);
  try
  {
    file_put_contents($defaults, $lines);
    @chmod($defaults, 0600);
    @exec($command, $output, $code);
  }
  finally
  {
    umask($old_umask);
    @unlink($defaults);
  }

  if ($code !== 0 or !is_file($plain) or filesize($plain) === 0)
  {
    @unlink($plain);
    return false;
  }

  agent_gzip($plain, $dir.'/'.AGENT_DUMP_FILE);
  unlink($plain);
  return true;
}

function agent_option_value($value)
{
  return '"'.strtr($value, array('\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r')).'"';
}

function agent_gzip($source, $target)
{
  $in = fopen($source, 'rb');
  $out = gzopen($target, 'wb6');
  if (!$in or !$out)
  {
    throw new AgentFailure('dump could not be written');
  }
  while (!feof($in))
  {
    gzwrite($out, fread($in, 1048576));
  }
  gzclose($out);
  fclose($in);
}

function agent_dump_with_php($dir, $db)
{
  $out = gzopen($dir.'/'.AGENT_DUMP_FILE, 'wb6');
  if (!$out)
  {
    throw new AgentFailure('dump could not be written');
  }
  gzwrite($out, "SET NAMES ".AGENT_CHARSET.";\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

  $tables = array();
  $result = agent_query($db, 'SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
  while ($row = mysqli_fetch_row($result))
  {
    $tables[] = $row[0];
  }

  foreach ($tables as $table)
  {
    $quoted = '`'.str_replace('`', '``', $table).'`';
    $create = mysqli_fetch_row(agent_query($db, 'SHOW CREATE TABLE '.$quoted));
    gzwrite($out, 'DROP TABLE IF EXISTS '.$quoted.";\n".$create[1].";\n\n");
    agent_dump_rows($out, $db, $quoted);
  }

  gzwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
  gzclose($out);
}

function agent_dump_rows($out, $db, $quoted)
{
  $result = mysqli_query($db, 'SELECT * FROM '.$quoted, MYSQLI_USE_RESULT);
  if ($result === false)
  {
    throw new AgentFailure('database query failed');
  }

  $batch = array();
  while ($row = mysqli_fetch_row($result))
  {
    $values = array();
    foreach ($row as $value)
    {
      $values[] = ($value === null) ? 'NULL' : "'".mysqli_real_escape_string($db, $value)."'";
    }
    $batch[] = '('.implode(',', $values).')';
    if (count($batch) >= AGENT_INSERT_BATCH_ROWS)
    {
      gzwrite($out, 'INSERT INTO '.$quoted.' VALUES '.implode(",\n", $batch).";\n");
      $batch = array();
    }
  }
  mysqli_free_result($result);

  if (count($batch) > 0)
  {
    gzwrite($out, 'INSERT INTO '.$quoted.' VALUES '.implode(",\n", $batch).";\n");
  }
  gzwrite($out, "\n");
}

function agent_action_dump()
{
  @set_time_limit(0);
  $dir = agent_ensure_dump_dir();
  list($db, $conf, $prefix) = agent_database();

  $method = 'php';
  if (agent_mysqldump_available() and agent_dump_with_mysqldump($dir, $conf))
  {
    $method = 'mysqldump';
  }
  else
  {
    try
    {
      agent_dump_with_php($dir, $db);
    }
    catch (Exception $e)
    {
      @unlink($dir.'/'.AGENT_DUMP_FILE);
      throw $e;
    }
  }

  return array(
    'path' => rtrim(AGENT_DUMP_DIR, '/').'/'.AGENT_DUMP_FILE,
    'size' => filesize($dir.'/'.AGENT_DUMP_FILE),
    'method' => $method,
    'counts' => agent_counts($db, $prefix),
    );
}

function agent_action_list()
{
  $files = array();
  foreach (explode(',', AGENT_LISTED_DIRS) as $listed)
  {
    $root = __DIR__.'/'.$listed;
    if (!is_dir($root))
    {
      continue;
    }
    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
      );
    foreach ($iterator as $file)
    {
      if ($file->isFile() and !$file->isLink())
      {
        $files[] = array(
          'path' => substr($file->getPathname(), strlen(__DIR__) + 1),
          'size' => $file->getSize(),
          'mtime' => $file->getMTime(),
          );
      }
    }
  }

  list($db, $conf, $prefix) = agent_database();
  $db_paths = array();
  $result = agent_query($db, 'SELECT path FROM `'.$prefix.'images`');
  while ($row = mysqli_fetch_row($result))
  {
    $db_paths[] = $row[0];
  }

  return array('files' => $files, 'db_paths' => $db_paths);
}

function agent_inside_dump_dir($path)
{
  $dir = realpath(agent_dump_dir());
  $absolute = (substr($path, 0, 1) === '/') ? $path : __DIR__.'/'.$path;
  $real = realpath($absolute);
  if ($dir === false or $real === false or !is_file($real))
  {
    return false;
  }
  return strpos($real, $dir.DIRECTORY_SEPARATOR) === 0 ? $real : false;
}

function agent_action_import()
{
  $path = isset($_POST['path']) ? (string) $_POST['path'] : '';
  $real = agent_inside_dump_dir($path);
  if ($real === false)
  {
    throw new AgentFailure('path is not a file inside the dump directory', 403);
  }

  @set_time_limit(0);
  list($db) = agent_database();
  $in = gzopen($real, 'rb');
  if (!$in)
  {
    throw new AgentFailure('dump could not be opened');
  }

  $statements = 0;
  $statement = '';
  $in_quote = false;
  while (($line = gzgets($in)) !== false)
  {
    if ($statement === '' and !$in_quote and (trim($line) === '' or substr($line, 0, 2) === '--'))
    {
      continue;
    }
    $statement .= $line;
    $in_quote = agent_quote_state($line, $in_quote);
    if (!$in_quote and substr(rtrim($line), -1) === ';')
    {
      if (mysqli_query($db, $statement) === false)
      {
        throw new AgentFailure(
          'statement '.($statements + 1).' failed: '.mysqli_error($db)
          );
      }
      $statements++;
      $statement = '';
    }
  }
  gzclose($in);

  return array('statements' => $statements);
}

/** Whether a quoted string is still open at the end of $line, given its state at the start. */
function agent_quote_state($line, $in_quote)
{
  $length = strlen($line);
  for ($i = 0; $i < $length; $i++)
  {
    $char = $line[$i];
    if ($in_quote)
    {
      if ($char === '\\')
      {
        $i++;
      }
      elseif ($char === "'")
      {
        $in_quote = false;
      }
    }
    elseif ($char === "'")
    {
      $in_quote = true;
    }
  }
  return $in_quote;
}

function agent_action_cleanup()
{
  $removed = array();
  $dir = agent_dump_dir();
  foreach (array(AGENT_DUMP_FILE, AGENT_PLAIN_DUMP_FILE, AGENT_DEFAULTS_FILE) as $name)
  {
    if (is_file($dir.'/'.$name) and unlink($dir.'/'.$name))
    {
      $removed[] = rtrim(AGENT_DUMP_DIR, '/').'/'.$name;
    }
  }

  if (is_file($dir.'/'.AGENT_CREATED_MARKER))
  {
    @unlink($dir.'/.htaccess');
    unlink($dir.'/'.AGENT_CREATED_MARKER);
    if (@rmdir($dir))
    {
      $removed[] = rtrim(AGENT_DUMP_DIR, '/');
    }
  }

  if (unlink(__FILE__))
  {
    $removed[] = basename(__FILE__);
  }
  return array('removed' => $removed);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST')
{
  agent_refuse('POST only');
}
if (time() > (int) AGENT_EXPIRES)
{
  agent_refuse('expired');
}
$agent_token = isset($_POST['token']) ? (string) $_POST['token'] : '';
if (!hash_equals(AGENT_TOKEN_SHA256, hash('sha256', $agent_token)))
{
  agent_refuse('wrong token');
}

$agent_action = isset($_POST['action']) ? (string) $_POST['action'] : '';
$agent_actions = array('dump', 'list', 'import', 'cleanup');
if (!in_array($agent_action, $agent_actions, true))
{
  agent_answer(400, array('error' => 'unknown action'));
}

try
{
  agent_answer(200, call_user_func('agent_action_'.$agent_action));
}
catch (AgentFailure $e)
{
  agent_answer($e->status, array('error' => $e->getMessage()));
}
catch (Throwable $e)
{
  agent_answer(500, array('error' => 'internal error'));
}
