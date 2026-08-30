<?php
/**
 * One real region write, run as its own process.
 *
 * The concurrency test needs N read-merge-write cycles genuinely in flight
 * against one file. Parallel requests to ws.php would not give that: PHP
 * serialises requests sharing a session, so they would queue and the test would
 * pass with no locking at all.
 *
 * The workers wait on a shared wall-clock start time before starting: PHP
 * startup and the database connect alone spread the processes far enough apart
 * to hide the very contention the test exists to reproduce.
 *
 * Usage: php write-regions-worker.php <image_id> <name> <x> <start_at>
 */
define('PERSONS_PATH', dirname(__DIR__, 2) . '/');
define('PIWIGO_ROOT', dirname(__DIR__, 4) . '/');

require_once PERSONS_PATH . 'include/functions.inc.php';
require_once __DIR__ . '/TestUsers.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/PiwigoRuntime.php';

PiwigoRuntime::loadPlugin();

if (isset($argv[4]))
{
    time_sleep_until((float)$argv[4]);
}

$result = persons_apply_change(
    (int)$argv[1],
    array(array('name' => $argv[2], 'x' => (float)$argv[3], 'y' => 0.5, 'w' => 0.05, 'h' => 0.05)),
    array()
);

echo json_encode($result), "\n";
exit($result['ok'] ? 0 : 1);
