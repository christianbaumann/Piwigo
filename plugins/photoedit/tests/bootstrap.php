<?php
define('PHOTOEDIT_PATH', dirname(__DIR__) . '/');
define('PIWIGO_ROOT', dirname(dirname(dirname(__DIR__))) . '/');

// functions.inc.php guards on PHOTOEDIT_PATH and then only declares functions
// and constants, so it loads with no database and no Piwigo core.
// char_to_fraction() / fraction_to_char(), the centre of interest's encoding:
// core declares them in a file of functions and classes only.
require_once PIWIGO_ROOT . 'include/derivative_params.inc.php';
require_once PHOTOEDIT_PATH . 'include/functions.inc.php';

// Constants only (plus class declarations): lets a test name
// WS_ERR_MISSING_PARAM instead of transcribing 1002.
require_once PIWIGO_ROOT . 'include/ws_core.inc.php';

// Integration-layer support classes (no Piwigo core needed - they talk to the
// gallery over HTTP and to MariaDB directly).
require_once __DIR__ . '/Support/TestUsers.php';
require_once __DIR__ . '/Support/Config.php';
require_once __DIR__ . '/Support/Db.php';
require_once __DIR__ . '/Support/WsClient.php';
require_once __DIR__ . '/Support/FixtureBuilder.php';
require_once __DIR__ . '/Support/PiwigoRuntime.php';
