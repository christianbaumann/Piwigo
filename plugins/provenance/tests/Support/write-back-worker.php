<?php
/**
 * One real write-back, run as its own process.
 *
 * The concurrency test needs N exiftool invocations genuinely in flight against
 * one file. Parallel requests to ws.php would not give that: PHP serialises
 * requests sharing a session, so they would queue and the test would pass with
 * no locking at all.
 *
 * The workers wait on a shared wall-clock start time before invoking exiftool:
 * PHP startup and the database connect alone spread twelve processes far enough
 * apart to hide the very contention the test exists to reproduce.
 *
 * Usage: php write-back-worker.php <image_id> <db_path> <owner> <start_at>
 */
define('PROVENANCE_PATH', dirname(__DIR__, 2) . '/');
define('PIWIGO_ROOT', dirname(__DIR__, 4) . '/');
define('PHPWG_ROOT_PATH', PIWIGO_ROOT);
define('PROVENANCE_XMP_CONFIG', PROVENANCE_PATH . 'exiftool/pwgprov.config');

require_once PROVENANCE_PATH . 'include/functions.inc.php';
require_once PROVENANCE_PATH . 'include/history.inc.php';
require_once PROVENANCE_PATH . 'include/exiftool.inc.php';
require_once __DIR__ . '/TestUsers.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/PiwigoRuntime.php';

PiwigoRuntime::boot();

if (isset($argv[4]))
{
    time_sleep_until((float)$argv[4]);
}

$result = provenance_write_back(
    array(array(
        'id' => (int)$argv[1],
        'path' => $argv[2],
        'provenance_owner' => $argv[3],
    )),
    array('provenance_owner' => 'Owner')
);

echo json_encode($result), "\n";
exit($result['written'] === 1 ? 0 : 1);
