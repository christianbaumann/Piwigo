<?php
// functions.inc.php guards on PERSONS_PATH and then only declares functions and
// constants, so it loads with no database and no Piwigo core.
define('PERSONS_PATH', dirname(__DIR__) . '/');
define('PIWIGO_ROOT', dirname(dirname(dirname(__DIR__))) . '/');
require_once PERSONS_PATH . 'include/functions.inc.php';

// Integration-layer support classes (no Piwigo core needed - they talk to
// ws.php over HTTP and to MariaDB directly).
require_once __DIR__ . '/Support/TestUsers.php';
require_once __DIR__ . '/Support/Config.php';
require_once __DIR__ . '/Support/Db.php';
require_once __DIR__ . '/Support/WsClient.php';
require_once __DIR__ . '/Support/FixtureBuilder.php';
require_once __DIR__ . '/Support/PiwigoRuntime.php';
