<?php
/**
 * Boots the parts of Piwigo this plugin's server code actually needs - the real
 * database layer, the real table constants and the real core function library -
 * and nothing else.
 *
 * include/common.inc.php cannot be included from the CLI (it calls
 * session_start(), which dies without $_SERVER['REMOTE_ADDR']), so a test that
 * wants to run the indexer for real has to assemble a smaller runtime. What it
 * assembles is production code, not a copy of it: pwg_query(), mass_inserts(),
 * tag_id_from_tag_name() and pwg_db_real_escape_string() are the same functions
 * the plugin calls in a request, running against the same MariaDB.
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

        global $conf, $prefixeTable, $page, $user, $lang, $lang_info;

        $conf = array();
        $page = array();
        $user = array();
        $lang = array();
        $lang_info = array();

        if (!defined('PHPWG_ROOT_PATH'))
        {
            define('PHPWG_ROOT_PATH', PIWIGO_ROOT);
        }

        // The defaults first, then the install's own overrides and credentials -
        // read rather than retyped. constants.php reads $conf['data_location']
        // and $conf['themes_dir'], so it cannot come before them.
        require PHPWG_ROOT_PATH . 'include/config_default.inc.php';
        @include PHPWG_ROOT_PATH . 'local/config/config.inc.php';
        require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
        require_once PHPWG_ROOT_PATH . 'include/constants.php';
        require_once PHPWG_ROOT_PATH . 'include/dblayer/functions_' . $conf['dblayer'] . '.inc.php';

        // pwg_query() reads both on every call, including the one pwg_db_connect()
        // makes itself, so they are set before the connection is opened.
        $conf['die_on_sql_error'] = true;
        $conf['show_queries'] = false;

        pwg_db_connect($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
        pwg_db_check_charset();

        // The core function library: query2array(), mass_inserts(),
        // trigger_change() and the rest, which admin/include/functions.php and
        // the plugin both call. In a request common.inc.php loads it.
        require_once PHPWG_ROOT_PATH . 'include/functions.inc.php';

        // Constants only (plus class declarations): lets a test name
        // WS_ERR_INVALID_PARAM instead of transcribing 1003.
        require_once PHPWG_ROOT_PATH . 'include/ws_core.inc.php';

        if (!defined('PERSONS_TABLE'))
        {
            define('PERSONS_TABLE', $prefixeTable . 'persons');
        }
        if (!defined('PERSONS_REGION_TABLE'))
        {
            define('PERSONS_REGION_TABLE', $prefixeTable . 'person_region');
        }

        self::$booted = true;
    }

    /**
     * Loads the plugin's server-side includes on top of a booted runtime.
     *
     * include/index.inc.php pulls in admin/include/functions.php itself, exactly
     * as it does inside ws.php, so this loads no core file the plugin would not
     * load in a request.
     */
    public static function loadPlugin(): void
    {
        self::boot();

        require_once PERSONS_PATH . 'include/exiftool.inc.php';
        require_once PERSONS_PATH . 'include/index.inc.php';
        require_once PERSONS_PATH . 'include/rescan.inc.php';
    }

    /**
     * Clears the request-scoped state core caches in $page.
     *
     * tag_id_from_tag_name() memoises name => id in
     * $page['tag_id_from_tag_name_cache'] for the life of a request
     * (admin/include/functions.php:1714). One PHPUnit process is many
     * simulated requests, and a test that deletes its tags in teardown would
     * otherwise hand the next test an id that no longer exists.
     */
    public static function resetRequestCaches(): void
    {
        global $page;
        $page = array();
    }

    /** Sets who the plugin's writes will be attributed to. */
    public static function actAs(?int $userId): void
    {
        global $user;
        $user = $userId === null ? array() : array('id' => $userId);
    }
}
