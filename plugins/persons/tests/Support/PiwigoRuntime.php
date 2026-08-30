<?php
/**
 * Boots the parts of Piwigo this plugin's server code actually needs - the real
 * database layer and the real table constants - and nothing else.
 *
 * include/common.inc.php cannot be included from the CLI (it calls
 * session_start(), which dies without $_SERVER['REMOTE_ADDR']), so a test that
 * wants to run the indexer for real has to assemble a smaller runtime. What it
 * assembles is production code, not a copy of it: pwg_query(), mass_inserts()
 * and pwg_db_real_escape_string() are the same functions the plugin calls in a
 * request, running against the same MariaDB.
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

        global $conf, $prefixeTable, $page, $user;

        $conf = array();
        $page = array();
        $user = array();

        // The install's own credentials and table prefix, read rather than retyped.
        require PIWIGO_ROOT . 'local/config/database.inc.php';
        require_once PIWIGO_ROOT . 'include/dblayer/functions_' . $conf['dblayer'] . '.inc.php';

        // pwg_query() reads both on every call, including the one pwg_db_connect()
        // makes itself, so they are set before the connection is opened.
        $conf['die_on_sql_error'] = true;
        $conf['show_queries'] = false;

        pwg_db_connect($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
        pwg_db_check_charset();

        // Constants only (plus class declarations): lets a test name
        // WS_ERR_INVALID_PARAM instead of transcribing 1003.
        require_once PIWIGO_ROOT . 'include/ws_core.inc.php';

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

    /** Sets who the plugin's writes will be attributed to. */
    public static function actAs(?int $userId): void
    {
        global $user;
        $user = $userId === null ? array() : array('id' => $userId);
    }
}
