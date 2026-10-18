<?php
define('GALLERYRULES_PATH', dirname(__DIR__) . '/');
define('PIWIGO_ROOT', dirname(dirname(dirname(__DIR__))) . '/');

// Integration-layer support classes (no Piwigo core needed - they talk to the
// gallery over HTTP and to MariaDB directly).
require_once __DIR__ . '/Support/TestUsers.php';
require_once __DIR__ . '/Support/Config.php';
require_once __DIR__ . '/Support/Db.php';
require_once __DIR__ . '/Support/OrphanTagSnapshot.php';
require_once __DIR__ . '/Support/WsClient.php';
require_once __DIR__ . '/Support/FixtureBuilder.php';
