<?php
use PHPUnit\Framework\TestCase;

/**
 * The plugin's schema lifecycle, driven through the same web-service method the
 * admin plugin screen calls (pwg.plugins.performAction) rather than by calling
 * maintain.class.php directly - so what is asserted is what an administrator
 * clicking "Activate" actually gets.
 *
 * This closes the three manual boxes Phase 1 of the plan left open: the plugin
 * installs, a second activation is a no-op rather than an error, and uninstall
 * takes both tables away again.
 *
 * Uninstall drops the two tables, so every destructive test snapshots their rows
 * first and puts them back afterwards. The index is rebuildable from the image
 * files by design, but a suite that destroys state it cannot restore is a suite
 * that has to be run in a particular order.
 */
final class PluginActivationTest extends TestCase
{
    private const PLUGIN_ID = 'persons';
    private const PERSONS_TABLE = 'piwigo_persons';
    private const REGION_TABLE = 'piwigo_person_region';

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;

    /** table => list of rows, snapshotted before a test that uninstalls. */
    private array $indexRows = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
        $this->fixture = new FixtureBuilder($this->db);

        $this->forceActive();
        $this->recordIndex();
    }

    protected function tearDown(): void
    {
        $this->forceActive();
        $this->restoreIndex();
        $this->ws->logout();
    }

    /** [HAPPY] The state the admin plugin list shows. */
    public function testPluginIsInstalledAndActive(): void
    {
        $this->assertSame('active', $this->pluginState());
    }

    /** [HAPPY] Install creates both index tables. */
    public function testInstallCreatesBothTables(): void
    {
        $this->assertTrue($this->fixture->tableExists(self::PERSONS_TABLE));
        $this->assertTrue($this->fixture->tableExists(self::REGION_TABLE));
    }

    /**
     * [HAPPY] Each table carries exactly the columns the shared definition
     * declares - no more, no fewer.
     *
     * The expected list comes from production code rather than being typed
     * again here; a second hand-written copy would rot on the first column
     * added. The unit suite checks the two sides agree as text, this checks the
     * database actually took them.
     */
    public function testEachTableHasTheDeclaredShape(): void
    {
        // Forced, not hoped for. A table left out of step with the code is
        // rebuilt by any sibling test that uninstalls, so without this the
        // assertion reports drift or does not depending purely on execution
        // order - which is how a genuinely missing column would go unreported.
        //
        // A fresh install is the only repair a test can drive: see
        // testAVersionBumpAddsAColumnAnOlderInstallIsMissing() below for why an
        // ALTER on an existing table is out of reach here.
        $this->reinstallFromScratch();

        foreach (array(
            self::PERSONS_TABLE => array_keys(persons_person_columns()),
            self::REGION_TABLE  => array_keys(persons_region_columns()),
        ) as $table => $expected)
        {
            $this->assertGreaterThan(0, count($expected), "anti-vacuity: no columns declared for $table");

            $result = $this->db->query("SHOW COLUMNS FROM `$table`");
            $actual = array();
            while ($row = $result->fetch_assoc())
            {
                $actual[] = $row['Field'];
            }

            sort($expected);
            sort($actual);
            $this->assertSame($expected, $actual, "$table does not match the shared definition");
        }
    }

    /**
     * [DT] The two ENUM columns accept every value the parser and indexer will
     * write.
     *
     * The lists are the same fact stored twice - once in a MySQL ENUM, once in
     * persons_region_types() / persons_region_sources() - and MySQL turns a
     * value outside an ENUM into '', leaving a row that silently claims
     * nothing. A file already holding a Pet region is the case that would hit
     * it first.
     */
    public function testTheEnumColumnsAcceptEveryDeclaredValue(): void
    {
        foreach (array(
            'region_type' => persons_region_types(),
            'source'      => persons_region_sources(),
        ) as $column => $values)
        {
            $this->assertGreaterThan(0, count($values), "anti-vacuity: no values declared for $column");

            $type = (string)$this->db->scalar(
                'SELECT COLUMN_TYPE FROM information_schema.COLUMNS' .
                ' WHERE TABLE_SCHEMA = DATABASE()' .
                " AND TABLE_NAME = '" . self::REGION_TABLE . "'" .
                " AND COLUMN_NAME = '" . $column . "'"
            );

            $declared = array();
            if (preg_match('/^enum\((.*)\)$/i', $type, $m))
            {
                $declared = array_map(
                    static fn (string $value): string => trim($value, "'"),
                    explode(',', $m[1])
                );
            }

            sort($values);
            sort($declared);
            $this->assertSame($values, $declared, "the $column ENUM is out of step with the code: $type");
        }
    }

    /**
     * [HAPPY] persons.name is unique, so the same person cannot end up as two
     * rows - which is what makes find-or-create by name safe.
     */
    public function testThePersonNameIsUnique(): void
    {
        $result = $this->db->query("SHOW INDEX FROM `" . self::PERSONS_TABLE . "` WHERE Column_name = 'name'");
        $unique = null;
        while ($row = $result->fetch_assoc())
        {
            $unique = ($row['Non_unique'] === '0' || $row['Non_unique'] === 0);
        }

        $this->assertTrue($unique, 'piwigo_persons.name carries no unique index');
    }

    /**
     * [ST] Activating an already-installed plugin runs install() again through
     * update() and must not fail on the existing tables. This is the "deactivate
     * then activate again" manual box.
     */
    public function testInstallIsIdempotent(): void
    {
        $res = $this->performAction('deactivate');
        $this->assertSame('ok', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);

        $res = $this->performAction('activate');
        $this->assertSame('ok', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);

        $this->assertSame('active', $this->pluginState());
        $this->assertTrue($this->fixture->tableExists(self::PERSONS_TABLE));
        $this->assertTrue($this->fixture->tableExists(self::REGION_TABLE));
    }

    /** [ST] Uninstall removes the plugin row and both tables again. */
    public function testUninstallRemovesBothTables(): void
    {
        $this->performAction('uninstall');

        $this->assertNull($this->pluginState(), 'the plugin row survived uninstall');
        $this->assertFalse($this->fixture->tableExists(self::PERSONS_TABLE));
        $this->assertFalse($this->fixture->tableExists(self::REGION_TABLE));
    }

    /**
     * [ST] A reinstall after an uninstall comes back with the full schema.
     *
     * This is the rollback claim in the plan's migration notes: the regions stay
     * in the image files, so an uninstall costs only the index and reinstalling
     * gives somewhere to rebuild it into.
     */
    public function testReinstallingAfterAnUninstallRestoresTheSchema(): void
    {
        $this->performAction('uninstall');
        $this->assertFalse($this->fixture->tableExists(self::PERSONS_TABLE), 'precondition: the tables are gone');

        $res = $this->performAction('activate');
        $this->assertSame('ok', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);

        $this->assertTrue($this->fixture->tableExists(self::PERSONS_TABLE));
        $this->assertTrue($this->fixture->tableExists(self::REGION_TABLE));
    }

    /**
     * [ST] A version bump adds a column an older install does not have.
     *
     * Skipped, with no successor at any layer. This is the upgrade path:
     * CREATE TABLE IF NOT EXISTS never touches an existing table, so a new
     * column reaches one only through the ALTER in add_missing_columns(). That
     * runs from install(), and install() is re-entered on an already-installed
     * plugin only through update() - which perform_action() reaches only by
     * downloading and extracting a real extension archive
     * (admin/include/plugins.class.php:155-185). Its 'install' case breaks out
     * immediately while a piwigo_plugins row exists (lines 133-137), and
     * 'activate' on an installed plugin calls no maintain method at all
     * (lines 187-195).
     *
     * The path is real in production - an upstream version bump ships as an
     * archive and does call update() - but nothing available here can drive it.
     * This test was written against a deactivate/activate cycle first, watched
     * fail for exactly this reason, and turned into a skip rather than deleted
     * or weakened into something that passes without executing the ALTER.
     *
     * Recorded in docs/agents/decisions/0018-persons-upgrade-path-is-untestable.md.
     * The same trap is recorded for plugins/provenance in decisions/0010.
     */
    public function testAVersionBumpAddsAColumnAnOlderInstallIsMissing(): void
    {
        $this->markTestSkipped(
            'install() is only re-entered through update(), which needs a real extension archive; ' .
            'no layer available here can drive it - see decisions/0018'
        );
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

    /**
     * The only route back to a schema that matches the code: uninstall drops
     * both tables, and the next activate installs them from the shared
     * definition. A deactivate/activate cycle would not do - it calls no
     * maintain method at all.
     */
    private function reinstallFromScratch(): void
    {
        $res = $this->performAction('uninstall');
        $this->assertSame('ok', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);

        $res = $this->performAction('activate');
        $this->assertSame('ok', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);
    }

    /** Snapshots both index tables, because uninstall drops them. */
    private function recordIndex(): void
    {
        $this->indexRows = array();
        foreach (array(self::PERSONS_TABLE, self::REGION_TABLE) as $table)
        {
            $rows = array();
            $result = $this->db->query("SELECT * FROM `$table`");
            while ($row = $result->fetch_assoc())
            {
                $rows[] = $row;
            }
            $this->indexRows[$table] = $rows;
        }
    }

    private function restoreIndex(): void
    {
        foreach ($this->indexRows as $table => $rows)
        {
            if (count($rows) === 0)
            {
                continue;
            }

            foreach ($rows as $row)
            {
                $columns = array();
                $values = array();
                foreach ($row as $column => $value)
                {
                    $columns[] = "`$column`";
                    $values[] = $value === null ? 'NULL' : "'" . $this->db->escape((string)$value) . "'";
                }
                $this->db->query(
                    "INSERT IGNORE INTO `$table` (" . implode(', ', $columns) . ')' .
                    ' VALUES (' . implode(', ', $values) . ')'
                );
            }
        }
        $this->indexRows = array();
    }
}
