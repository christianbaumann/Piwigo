<?php
/**
 * Forces a known database state and asserts it took effect, so a test never
 * runs over a state it merely hoped for.
 *
 * Starts as the guard every galleryrules suite runs behind; the fixtures the
 * behaviour changes need are added with them.
 */
class FixtureBuilder
{
    private Db $db;

    /** The piwigo_config row that marks an install as expendable; see create-test-users.php. */
    public const THROWAWAY_PARAM = 'galleryrules_throwaway_install';

    public function __construct(Db $db)
    {
        $this->db = $db;
        self::assertThrowawayInstall($db);
    }

    /**
     * Refuses to build a fixture against an install that has not been declared
     * expendable. Fails closed, naming the script that sets the marker.
     */
    public static function assertThrowawayInstall(Db $db): void
    {
        $marker = $db->scalar(
            "SELECT value FROM piwigo_config WHERE param = '" . $db->escape(self::THROWAWAY_PARAM) . "'"
        );

        if ((string)$marker !== '1')
        {
            throw new RuntimeException(
                "This install is not marked as a throwaway, and the galleryrules suites change gallery content.\n" .
                "Mark an install whose gallery you can afford to lose with:\n" .
                "  ddev exec php plugins/galleryrules/tests/Support/create-test-users.php\n" .
                "Never mark a production install."
            );
        }
    }

    /** Fails naming the plugin when it is not active. */
    public function assertPluginActive(string $plugin = 'galleryrules'): void
    {
        $state = $this->db->scalar("SELECT state FROM piwigo_plugins WHERE id = '" . $this->db->escape($plugin) . "'");
        if ($state !== 'active')
        {
            throw new RuntimeException(
                'The ' . $plugin . ' plugin is not active (state: ' . var_export($state, true) . ').' . "\n" .
                'Activate it on Administration > Plugins before running the suites.'
            );
        }
    }
}
