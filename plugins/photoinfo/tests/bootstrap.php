<?php
define('PHOTOINFO_PATH', dirname(__DIR__) . '/');
define('PIWIGO_ROOT', dirname(dirname(dirname(__DIR__))) . '/');

// Constants only (plus class declarations): lets a test name
// WS_ERR_MISSING_PARAM instead of transcribing 1002.
require_once PIWIGO_ROOT . 'include/ws_core.inc.php';

// The pure layers: photoinfo's own, and provenance's, which it composes with.
define('PROVENANCE_PATH', PIWIGO_ROOT . 'plugins/provenance/');
require_once PROVENANCE_PATH . 'include/functions.inc.php';
require_once PHOTOINFO_PATH . 'include/functions.inc.php';

// Integration-layer support classes (no Piwigo core needed - they talk to the
// gallery over HTTP and to MariaDB directly).
require_once __DIR__ . '/Support/TestUsers.php';
require_once __DIR__ . '/Support/Config.php';
require_once __DIR__ . '/Support/Db.php';
require_once __DIR__ . '/Support/WsClient.php';
require_once __DIR__ . '/Support/FixtureBuilder.php';
require_once __DIR__ . '/Support/PiwigoRuntime.php';
