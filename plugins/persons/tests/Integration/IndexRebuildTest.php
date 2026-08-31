<?php
use PHPUnit\Framework\TestCase;

/**
 * The claim the whole design rests on: the database is a derived index, and the
 * image files are the source of truth.
 *
 * Nothing else in the suite tests it end to end. Every other rebuild test
 * rescans one photo or a handful; this one takes both tables away entirely -
 * through the same uninstall an administrator clicks - reinstalls, rescans the
 * gallery through the same web-service method the admin screen's button drives,
 * and asserts the index that comes back is the one that went away.
 *
 * This is the Phase 8 manual box, automated.
 *
 * It is destructive by construction: for the length of one test this install
 * has no person index at all. The rows are snapshotted verbatim beforehand and
 * put back in teardown, so a failure - which is exactly the case where the
 * rescan did *not* restore everything - does not leave the install short.
 */
final class IndexRebuildTest extends TestCase
{
    private const PLUGIN_ID = 'persons';
    private const PERSONS_TABLE = 'piwigo_persons';
    private const REGION_TABLE = 'piwigo_person_region';

    private const JANE = 'Persons Rebuild Jane';
    private const JOHN = 'Persons Rebuild John';

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;

    /** table => rows exactly as they were before this test ran. */
    private array $snapshot = array();

    /** @var array[] the two fixture photos */
    private array $images = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        PiwigoRuntime::loadPlugin();
        PiwigoRuntime::resetRequestCaches();

        if (!$this->fixture->tableExists(self::REGION_TABLE))
        {
            $this->markTestSkipped('the persons plugin is not installed; activate it first');
        }

        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->takeSnapshot();

        $album = $this->fixture->createTestAlbum('Persons rebuild fixture');

        // Two photos and two people, one of them on both: the shape that would
        // survive a rebuild which merged persons, or one which created a second
        // person row per photo, without either being visible on a single photo.
        $first = $this->fixture->createTestImage();
        $second = $this->fixture->createTestImage();
        $this->images = array($first, $second);

        foreach ($this->images as $image)
        {
            $this->fixture->attachImage((int)$image['id'], $album);
        }
        $this->fixture->invalidateUserCache();

        $this->fixture->writeRegionsWithExiftool($first, array(
            array('name' => self::JANE, 'x' => 0.30, 'y' => 0.40, 'w' => 0.10, 'h' => 0.20),
            array('name' => self::JOHN, 'x' => 0.70, 'y' => 0.35, 'w' => 0.16, 'h' => 0.12),
            ), (int)$first['width'], (int)$first['height']);

        $this->fixture->writeRegionsWithExiftool($second, array(
            array('name' => self::JANE, 'x' => 0.50, 'y' => 0.50, 'w' => 0.12, 'h' => 0.18),
            ), (int)$second['width'], (int)$second['height']);

        foreach ($this->images as $image)
        {
            $outcome = persons_reindex_image((int)$image['id'], $image['file']);
            $this->assertTrue($outcome['ok'], 'the fixture could not be indexed: ' . $outcome['message']);
        }
    }

    protected function tearDown(): void
    {
        $this->forceActive();

        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPersons(array(self::JANE, self::JOHN));

        $this->restoreSnapshot();

        $this->ws->logout();
    }

    /**
     * [ST] Both tables dropped, recreated and refilled from the image files
     * alone give the index back.
     *
     * Compared by content rather than by row id: the rows are inserted again
     * from scratch, so their primary keys are new by definition, and asserting
     * on them would fail for the one reason that does not matter.
     *
     * Two assertions, and the difference between them is deliberate:
     *
     *   - Over the whole index, nothing that was in it may be missing
     *     afterwards. That is the disposability claim.
     *   - Over the fixture's own two photos, the rebuilt rows must match
     *     exactly. Extra rows anywhere else are not a regression: a rescan
     *     reads every file, and a file whose regions were never indexed - one
     *     tagged in digiKam, or left behind by an earlier run - contributes new
     *     rows. That is the rescan doing its job. Asserting the whole index came
     *     back byte-identical would be asserting that no file in the gallery has
     *     ever drifted, which is a fact about the gallery and not about this
     *     code.
     */
    public function testDroppingTheTablesAndRescanningRebuildsTheIndex(): void
    {
        $before = $this->indexContent();

        // Anti-vacuity: an empty index is rebuilt into an empty index by doing
        // nothing at all, and every assertion below would pass on that.
        $this->assertGreaterThan(0, count($before['regions']), 'nothing was indexed to rebuild');
        $this->assertGreaterThan(0, count($before['persons']));
        $this->assertGreaterThan(0, count($before['tags']));

        $this->performAction('uninstall');
        $this->assertFalse($this->fixture->tableExists(self::PERSONS_TABLE), 'uninstall left the persons table');
        $this->assertFalse($this->fixture->tableExists(self::REGION_TABLE), 'uninstall left the region table');

        $this->forceActive();
        $this->assertTrue($this->fixture->tableExists(self::PERSONS_TABLE));
        $this->assertTrue($this->fixture->tableExists(self::REGION_TABLE));
        $this->assertSame(array('persons' => array(), 'regions' => array(), 'tags' => array()),
            $this->indexContent(),
            'the reinstalled index was not empty, so what comes back is not only what the files say');

        $scanned = $this->rescanEveryPhoto();
        $this->assertGreaterThan(0, $scanned, 'the rescan covered no photo at all');

        $after = $this->indexContent();

        foreach (array('persons', 'regions', 'tags') as $part)
        {
            foreach ($before[$part] as $row)
            {
                $this->assertContains($row, $after[$part],
                    "a $part row the index held before the rebuild did not come back");
            }
        }

        $this->assertEquals($this->onlyFixtureRows($before), $this->onlyFixtureRows($after),
            "the fixture photos' rows did not come back exactly as they were");
    }

    /**
     * The part of an index snapshot that belongs to this test's own photos and
     * people - the only part it controls, and so the only part it may demand be
     * identical.
     */
    private function onlyFixtureRows(array $content): array
    {
        $imageIds = array();
        foreach ($this->images as $image)
        {
            $imageIds[] = (string)(int)$image['id'];
        }
        $names = array(self::JANE, self::JOHN);

        $keep = function (array $rows, array $keys) use ($imageIds, $names)
        {
            return array_values(array_filter($rows, function ($row) use ($imageIds, $names, $keys)
            {
                if (in_array('image_id', $keys, true) and !in_array((string)$row['image_id'], $imageIds, true))
                {
                    return false;
                }
                return in_array($row['name'], $names, true);
            }));
        };

        return array(
            'persons' => $keep($content['persons'], array('name')),
            'regions' => $keep($content['regions'], array('image_id', 'name')),
            'tags' => $keep($content['tags'], array('image_id', 'name')),
            );
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Runs the gallery through pwg.persons.rescan in the chunks the method
     * accepts - the same loop the admin screen's button drives.
     *
     * @return int photos the rescan reported it read
     */
    private function rescanEveryPhoto(): int
    {
        $ids = array();
        $result = $this->db->query('SELECT id FROM piwigo_images ORDER BY id');
        while ($row = $result->fetch_assoc())
        {
            $ids[] = (int)$row['id'];
        }

        $scanned = 0;
        foreach (array_chunk($ids, PERSONS_WRITEBACK_MAX_CHUNK) as $chunk)
        {
            $res = $this->ws->call('pwg.persons.rescan', array(
                'image_ids' => implode(',', $chunk),
                'pwg_token' => $this->ws->token(),
                ));

            $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
            $scanned += (int)$res['json']['result']['scanned'];
        }

        return $scanned;
    }

    /**
     * The index as content: no primary keys, and every person referred to by
     * name, which is the only identifier that survives a rebuild.
     */
    private function indexContent(): array
    {
        return array(
            'persons' => $this->rows('
SELECT name, url_name, tag_id
  FROM ' . self::PERSONS_TABLE . '
  ORDER BY name
'),
            'regions' => $this->rows('
SELECT r.image_id, p.name, r.area_x, r.area_y, r.area_w, r.area_h,
       r.applied_w, r.applied_h, r.rotation_at_write, r.region_type, r.source
  FROM ' . self::REGION_TABLE . ' AS r
  JOIN ' . self::PERSONS_TABLE . ' AS p ON p.id = r.person_id
  ORDER BY r.image_id, p.name, r.area_x, r.area_y
'),
            'tags' => $this->rows('
SELECT it.image_id, p.name
  FROM piwigo_image_tag AS it
  JOIN ' . self::PERSONS_TABLE . ' AS p ON p.tag_id = it.tag_id
  ORDER BY it.image_id, p.name
'),
            );
    }

    private function rows(string $sql): array
    {
        $out = array();
        $result = $this->db->query($sql);
        while ($row = $result->fetch_assoc())
        {
            $out[] = $row;
        }
        return $out;
    }

    private function takeSnapshot(): void
    {
        $this->snapshot = array(
            self::PERSONS_TABLE => $this->rows('SELECT * FROM ' . self::PERSONS_TABLE),
            self::REGION_TABLE => $this->rows('SELECT * FROM ' . self::REGION_TABLE),
            );
    }

    /**
     * Puts the install back exactly as it was found.
     *
     * Cleared first and re-inserted with their original keys: the test leaves
     * rows of its own behind on success and, on failure, may have lost some -
     * either way an INSERT on top of what is there would not be the state that
     * was snapshotted.
     */
    private function restoreSnapshot(): void
    {
        $this->db->query('DELETE FROM ' . self::REGION_TABLE);
        $this->db->query('DELETE FROM ' . self::PERSONS_TABLE);

        foreach (array(self::PERSONS_TABLE, self::REGION_TABLE) as $table)
        {
            foreach ($this->snapshot[$table] ?? array() as $row)
            {
                $columns = array();
                $values = array();
                foreach ($row as $column => $value)
                {
                    $columns[] = "`$column`";
                    $values[] = $value === null ? 'NULL' : "'" . $this->db->escape((string)$value) . "'";
                }
                $this->db->query(
                    'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ')'
                    . ' VALUES (' . implode(', ', $values) . ')'
                );
            }
        }

        $this->snapshot = array();
    }

    private function performAction(string $action): array
    {
        $res = $this->ws->call('pwg.plugins.performAction', array(
            'action' => $action,
            'plugin' => self::PLUGIN_ID,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, "could not $action the plugin: " . $res['body']);

        return $res;
    }

    private function forceActive(): void
    {
        $state = $this->db->scalar("SELECT state FROM piwigo_plugins WHERE id = '" . self::PLUGIN_ID . "'");
        if ($state === 'active')
        {
            return;
        }

        $this->performAction('activate');
    }
}
