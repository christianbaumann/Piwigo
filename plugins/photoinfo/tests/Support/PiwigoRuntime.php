<?php
/**
 * Boots the parts of Piwigo an in-process test needs - the real database
 * layer, table constants, core function library (which brings the plugin
 * event layer) and logger - so a test can call photoinfo's functions directly.
 *
 * include/common.inc.php cannot be included from the CLI (it calls
 * session_start(), which dies without $_SERVER['REMOTE_ADDR']). Adapted from
 * plugins/photoedit/tests/Support/PiwigoRuntime.php; each plugin's suite
 * stands on its own.
 */
class PiwigoRuntime
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted)
        {
            return;
        }

        global $conf, $prefixeTable, $page, $user, $lang, $lang_info, $logger;

        $conf = array();
        $page = array();
        $user = array();
        $lang = array();
        $lang_info = array();

        if (!defined('PHPWG_ROOT_PATH'))
        {
            define('PHPWG_ROOT_PATH', PIWIGO_ROOT);
        }

        require PHPWG_ROOT_PATH . 'include/config_default.inc.php';
        @include PHPWG_ROOT_PATH . 'local/config/config.inc.php';
        require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
        require_once PHPWG_ROOT_PATH . 'include/constants.php';
        require_once PHPWG_ROOT_PATH . 'include/dblayer/functions_' . $conf['dblayer'] . '.inc.php';

        $conf['die_on_sql_error'] = true;
        $conf['show_queries'] = false;

        pwg_db_connect($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
        pwg_db_check_charset();

        require_once PHPWG_ROOT_PATH . 'include/functions.inc.php';

        require_once PHPWG_ROOT_PATH . 'include/Logger.class.php';
        $logger = new Logger(array(
            'directory' => PHPWG_ROOT_PATH . $conf['data_location'] . $conf['log_dir'],
            'severity' => $conf['log_level'],
            'filename' => 'photoinfo-tests.txt',
            ));

        self::$booted = true;
    }
}
