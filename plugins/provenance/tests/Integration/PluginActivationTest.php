<?php
use PHPUnit\Framework\TestCase;

/**
 * The plugin's schema lifecycle, driven through the same web-service method the
 * admin plugin screen calls (pwg.plugins.performAction) rather than by calling
 * maintain.class.php directly - so what is asserted is what an administrator
 * clicking "Activate" actually gets.
 *
 * Every test forces its own starting state and puts the install back afterwards:
 * uninstall drops the columns, taking any real provenance values with them, so
 * the fixture snapshots and restores them around the destructive cases.
 */
final class PluginActivationTest extends TestCase
{
    private const PLUGIN_ID = 'provenance';
    private const HISTORY_TABLE = 'piwigo_provenance_history';

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
        $this->fixture = new FixtureBuilder($this->db);

        $this->forceActive();
        $this->fixture->recordAllProvenance();
    }

    protected function tearDown(): void
    {
        $this->forceActive();
        $this->fixture->restore();
        $this->ws->logout();
    }

    /** [HAPPY] The plugin the admin plugin list shows as Active. */
    public function testPluginIsInstalledAndActive(): void
    {
        $this->assertSame('active', $this->pluginState());
    }

    /**
     * [HAPPY] Install creates all nine columns and the history table.
     *
     * The column lists come from FixtureBuilder rather than being typed again
     * here; a second hand-written copy of the schema would rot on the first
     * column added.
     */
    public function testInstallCreatesEveryColumnAndTheHistoryTable(): void
    {
        $this->assertGreaterThan(
            0,
            count(FixtureBuilder::albumColumns()) + count(FixtureBuilder::imageColumns()),
            'anti-vacuity: with no columns listed this test would assert nothing'
        );

        foreach (FixtureBuilder::albumColumns() as $column)
        {
            $this->assertTrue(
                $this->fixture->columnExists('piwigo_categories', $column),
                "piwigo_categories.$column is missing after install"
            );
        }

        foreach (FixtureBuilder::imageColumns() as $column)
        {
            $this->assertTrue(
                $this->fixture->columnExists('piwigo_images', $column),
                "piwigo_images.$column is missing after install"
            );
        }

        $this->assertTrue($this->fixture->tableExists(self::HISTORY_TABLE));
    }

    /** [HAPPY] The history table carries the columns every later phase writes. */
    public function testHistoryTableHasTheRecordedShape(): void
    {
        $expected = array(
            'id', 'object', 'object_id', 'field', 'old_value', 'new_value',
            'source', 'performed_by', 'occured_on',
            );

        $result = $this->db->query('SHOW COLUMNS FROM `' . self::HISTORY_TABLE . '`');
        $actual = array();
        while ($row = $result->fetch_assoc())
        {
            $actual[] = $row['Field'];
        }

        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    /** [ST] Uninstall removes every column and the table again. */
    public function testUninstallRemovesEveryColumnAndTheHistoryTable(): void
    {
        $this->performAction('uninstall');

        $this->assertNull($this->pluginState(), 'the plugin row survived uninstall');

        foreach (FixtureBuilder::albumColumns() as $column)
        {
            $this->assertFalse(
                $this->fixture->columnExists('piwigo_categories', $column),
                "piwigo_categories.$column survived uninstall"
            );
        }

        foreach (FixtureBuilder::imageColumns() as $column)
        {
            $this->assertFalse(
                $this->fixture->columnExists('piwigo_images', $column),
                "piwigo_images.$column survived uninstall"
            );
        }

        $this->assertFalse($this->fixture->tableExists(self::HISTORY_TABLE));
    }

    /**
     * [ST] Install is idempotent: activating an already-installed plugin runs
     * install() again through update(), and must not fail on the existing
     * columns.
     */
    public function testInstallIsIdempotent(): void
    {
        $res = $this->performAction('activate');
        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);

        $this->assertSame('active', $this->pluginState());
        $this->assertTrue($this->fixture->columnExists('piwigo_images', 'provenance_owner'));
        $this->assertTrue($this->fixture->tableExists(self::HISTORY_TABLE));
    }

    /** [NEG] A guest cannot install or uninstall the plugin. */
    public function testGuestCannotPerformPluginActions(): void
    {
        $token = $this->ws->token();
        $this->ws->logout();

        $res = $this->ws->call('pwg.plugins.performAction', array(
            'action' => 'uninstall',
            'plugin' => self::PLUGIN_ID,
            'pwg_token' => $token,
        ));

        $this->ws->login(Config::username(), Config::password());

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame('active', $this->pluginState(), 'the rejected call must not have uninstalled the plugin');
    }

    /**
     * [NEG] An authenticated non-admin cannot either.
     *
     * The interesting half of the gate: ws_plugins_performAction checks
     * is_webmaster(), not merely "logged in", so being a real user is not enough.
     */
    public function testNormalUserCannotPerformPluginActions(): void
    {
        $this->ws->logout();
        $this->ws->login(Config::normalUsername(), Config::normalPassword());

        $res = $this->ws->call('pwg.plugins.performAction', array(
            'action' => 'uninstall',
            'plugin' => self::PLUGIN_ID,
            'pwg_token' => $this->ws->token(),
        ));

        $this->ws->logout();
        $this->ws->login(Config::username(), Config::password());

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame('active', $this->pluginState(), 'the rejected call must not have uninstalled the plugin');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function pluginState(): ?string
    {
        $state = $this->db->scalar(
            "SELECT state FROM piwigo_plugins WHERE id = '" . self::PLUGIN_ID . "'"
        );
        return $state === null ? null : (string)$state;
    }

    private function performAction(string $action): array
    {
        return $this->ws->call('pwg.plugins.performAction', array(
            'action' => $action,
            'plugin' => self::PLUGIN_ID,
            'pwg_token' => $this->ws->token(),
        ));
    }

    /** Forces the precondition every test starts from, and asserts it took effect. */
    private function forceActive(): void
    {
        if ($this->pluginState() !== 'active')
        {
            $res = $this->performAction('activate');
            if ($this->pluginState() !== 'active')
            {
                throw new RuntimeException('Could not activate the plugin: ' . $res['body']);
            }
        }
    }
}
