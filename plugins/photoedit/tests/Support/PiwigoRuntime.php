<?php
/**
 * Boots the parts of Piwigo the write pipeline needs - the real database
 * layer, table constants, core function library and logger - so a test can
 * call photoedit_apply() in-process and listen to its events.
 *
 * include/common.inc.php cannot be included from the CLI (it calls
 * session_start(), which dies without $_SERVER['REMOTE_ADDR']). Adapted from
 * plugins/persons/tests/Support/PiwigoRuntime.php; each plugin's suite stands
 * on its own.
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

        // image_ext_imagick::write() logs every command it runs.
        require_once PHPWG_ROOT_PATH . 'include/Logger.class.php';
        $logger = new Logger(array(
            'directory' => PHPWG_ROOT_PATH . $conf['data_location'] . $conf['log_dir'],
            'severity' => $conf['log_level'],
            'filename' => 'photoedit-tests.txt',
            ));

        self::$booted = true;
    }

    /** Loads the write pipeline on top of a booted runtime. */
    public static function loadPipeline(): void
    {
        self::boot();

        require_once PHOTOEDIT_PATH . 'include/pipeline.inc.php';
    }
}
