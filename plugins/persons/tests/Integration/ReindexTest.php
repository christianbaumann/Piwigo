<?php
use PHPUnit\Framework\TestCase;

/**
 * The index rebuild across its real boundaries: a real PNG on disk, a real
 * exiftool, the real MariaDB and core's own tag_id_from_tag_name().
 *
 * Read-only against the image: nothing here writes a region with the plugin -
 * that is Phase 3. The regions are seeded with a plain exiftool call, so what is
 * proved is that the indexer reads what an independent writer produced, not that
 * it agrees with itself.
 *
 * Every photo is one this suite created (FixtureBuilder::createTestImage), never
 * a scan of the collection.
 */
final class ReindexTest extends TestCase
{
    /** Names this test seeds. Distinctive, so a leftover row is obvious. */
    private const JANE = 'Persons Test Jane Doe';
    private const JOHN = 'Persons Test John Smith';
    private const REX = 'Persons Test Rex';

    /** The AppliedToDimensions the seeded regions are written against. */
    private const APPLIED_W = 4000;
    private const APPLIED_H = 3000;

    private Db $db;
    private FixtureBuilder $fixture;
    /** @var array id, db_path, file, width, height */
    private array $image;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);

        PiwigoRuntime::loadPlugin();
        PiwigoRuntime::resetRequestCaches();

        if (!$this->fixture->tableExists('piwigo_person_region'))
        {
            $this->markTestSkipped('the persons plugin is not installed; activate it first');
        }

        $this->image = $this->fixture->createTestImage();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyPersons(array(self::JANE, self::JOHN, self::REX));
    }

    /**
     * [HAPPY] Two faces in the file become two persons, two region rows and two
     * mirrored image_tag rows.
     *
     * This is the Phase 2 success criterion in one test.
     */
    public function testTheIndexMatchesWhatTheFileHolds(): void
    {
        $this->seed(array(
            array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2),
            array('name' => self::JOHN, 'x' => 0.2, 'y' => 0.3, 'w' => 0.1, 'h' => 0.1),
        ));

        $result = persons_reindex_image($this->image['id'], $this->image['file']);

        $this->assertTrue($result['ok'], 'reindex failed: ' . $result['message']);
        $this->assertSame(2, $result['regions'], 'anti-vacuity: the fixture yielded no regions');

        $this->assertSame(2, $this->regionCount());
        $this->assertNotNull($this->personId(self::JANE));
        $this->assertNotNull($this->personId(self::JOHN));

        $this->assertSame(2, $this->imageTagCount(), 'both persons must be mirrored as tags on the photo');
        $this->assertTrue($this->photoCarriesTag(self::JANE));
        $this->assertTrue($this->photoCarriesTag(self::JOHN));
    }

    /** [HAPPY] The stored coordinates are the file's, to full double precision. */
    public function testTheStoredCoordinatesAreTheOnesInTheFile(): void
    {
        $this->seed(array(
            array('name' => self::JANE, 'x' => 0.333333, 'y' => 0.666666, 'w' => 0.101, 'h' => 0.202),
        ));

        persons_reindex_image($this->image['id'], $this->image['file']);

        $row = $this->regionRow(self::JANE);
        $this->assertNotNull($row, 'anti-vacuity: no region row to inspect');
        $this->assertEqualsWithDelta(0.333333, (float)$row['area_x'], 1e-9);
        $this->assertEqualsWithDelta(0.666666, (float)$row['area_y'], 1e-9);
        $this->assertEqualsWithDelta(0.101, (float)$row['area_w'], 1e-9);
        $this->assertEqualsWithDelta(0.202, (float)$row['area_h'], 1e-9);
        $this->assertSame(self::APPLIED_W, (int)$row['applied_w']);
        $this->assertSame(self::APPLIED_H, (int)$row['applied_h']);
    }

    /**
     * [ST] Reindexing replaces this image's rows rather than adding to them.
     * The index is derived: after a second run it still says exactly what the
     * file says.
     */
    public function testReindexingTwiceLeavesTheSameRows(): void
    {
        $this->seed(array(
            array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2),
        ));

        persons_reindex_image($this->image['id'], $this->image['file']);
        $first = $this->regionCount();
        persons_reindex_image($this->image['id'], $this->image['file']);

        $this->assertSame(1, $first, 'anti-vacuity: the first pass indexed nothing');
        $this->assertSame(1, $this->regionCount());
        $this->assertSame(1, $this->imageTagCount());
    }

    /**
     * [ST] A person removed from the file is removed from the index and loses
     * the mirrored tag on that photo - the tag row itself survives, because it
     * may be on other photos.
     */
    public function testAPersonNoLongerInTheFileLosesTheIndexRowAndTheTag(): void
    {
        $this->seed(array(
            array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2),
            array('name' => self::JOHN, 'x' => 0.2, 'y' => 0.3, 'w' => 0.1, 'h' => 0.1),
        ));
        persons_reindex_image($this->image['id'], $this->image['file']);
        $this->assertSame(2, $this->regionCount(), 'anti-vacuity: the precondition did not take');

        $this->seed(array(
            array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2),
        ));
        persons_reindex_image($this->image['id'], $this->image['file']);

        $this->assertSame(1, $this->regionCount());
        $this->assertFalse($this->photoCarriesTag(self::JOHN));
        $this->assertTrue($this->photoCarriesTag(self::JANE));
        $this->assertNotNull($this->personId(self::JOHN), 'the person row itself is not deleted by a reindex');
    }

    /**
     * [ECP] A Pet region is indexed as foreign and does not become a tag: a pet
     * is not a person, and mirroring it would put it in the gallery's people.
     */
    public function testAPetRegionIsIndexedAsForeignAndNotMirroredAsATag(): void
    {
        $this->seed(array(
            array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2),
            array('name' => self::REX, 'x' => 0.2, 'y' => 0.3, 'w' => 0.1, 'h' => 0.1, 'type' => 'Pet'),
        ));

        persons_reindex_image($this->image['id'], $this->image['file']);

        $this->assertSame(2, $this->regionCount());
        $this->assertSame('foreign', $this->regionRow(self::REX)['source']);
        $this->assertSame('Pet', $this->regionRow(self::REX)['region_type']);
        $this->assertSame(1, $this->imageTagCount(), 'only the face is mirrored as a tag');
        $this->assertTrue($this->photoCarriesTag(self::JANE));
    }

    /** [NEG] A file with no regions leaves the image with an empty index. */
    public function testAFileWithNoRegionsYieldsNoRows(): void
    {
        $result = persons_reindex_image($this->image['id'], $this->image['file']);

        $this->assertTrue($result['ok'], 'a file with no regions is not an error: ' . $result['message']);
        $this->assertSame(0, $result['regions']);
        $this->assertSame(0, $this->regionCount());
    }

    /** [NEG] A missing file is reported, not fatal, and changes no rows. */
    public function testAMissingFileIsReportedAsFailed(): void
    {
        $result = persons_reindex_image($this->image['id'], $this->image['file'] . '.nope');

        $this->assertFalse($result['ok']);
        $this->assertNotSame('', $result['message']);
        $this->assertSame(0, $this->regionCount());
    }

    /** [HAPPY] The same person on two photos is one person row and two regions. */
    public function testOnePersonAcrossTwoPhotosIsOnePersonRow(): void
    {
        $second = $this->fixture->createTestImage();

        foreach (array($this->image, $second) as $image)
        {
            $this->fixture->writeRegionsWithExiftool(
                $image,
                array(array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2)),
                self::APPLIED_W,
                self::APPLIED_H
            );
            persons_reindex_image($image['id'], $image['file']);
        }

        $personId = $this->personId(self::JANE);
        $this->assertNotNull($personId);
        $this->assertSame(2, (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_person_region WHERE person_id = ' . (int)$personId
        ));
        $this->assertSame(1, (int)$this->db->scalar(
            "SELECT COUNT(*) FROM piwigo_persons WHERE name = '" . $this->db->escape(self::JANE) . "'"
        ));
    }

    /**
     * [NEG] One unreadable file does not abort a rescan of several.
     *
     * The batch keeps going and reports which photo failed, so an album with one
     * broken file still indexes the rest.
     */
    public function testOneUnreadableFileDoesNotAbortTheBatch(): void
    {
        $second = $this->fixture->createTestImage();
        $this->fixture->writeRegionsWithExiftool(
            $second,
            array(array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2)),
            self::APPLIED_W,
            self::APPLIED_H
        );

        // The first photo's file is gone; the second is fine.
        unlink($this->image['file']);

        $result = persons_rescan_images(array($this->image['id'], $second['id']));

        $this->assertSame(1, $result['scanned']);
        $this->assertArrayHasKey($this->image['id'], $result['failed']);
        $this->assertNotSame('', $result['failed'][$this->image['id']]);
        $this->assertTrue($this->photoCarriesTag(self::JANE, $second['id']));
    }

    /**
     * [ERR] A file tagged by a third-party tool, driven through the rescan
     * entry point rather than through persons_reindex_image() directly.
     *
     * This is the automated half of the plan's Phase 2 manual box ("point the
     * rescan at a photo tagged in digiKam and confirm the names land in
     * piwigo_persons"). What makes a digiKam file different from one this
     * plugin wrote is that it carries no AppliedToDimensions at all - KDE bug
     * 429219 - so the case is seeded that way rather than by naming digiKam.
     *
     * [ERR] because the oracle is digiKam's observed output, not a requirement:
     * nothing obliges a writer to omit AppliedToDimensions, and MWG says it
     * should be there. Running an actual digiKam stays a hand check - see the
     * open table in docs/agents/TESTING.md.
     *
     * The unknown dimensions must survive as NULL: a 0 would make the region
     * look infinitely stale on the picture page, which is the visible symptom
     * this test exists to keep out.
     */
    public function testARescanIndexesAFileTaggedByAThirdPartyToolWithNoAppliedToDimensions(): void
    {
        $this->fixture->writeRegionsWithExiftool(
            $this->image,
            array(
                array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2),
                array('name' => self::JOHN, 'x' => 0.2, 'y' => 0.3, 'w' => 0.1, 'h' => 0.1),
            ),
            null,
            null
        );

        // The precondition is forced, then asserted: if the seeder ever starts
        // writing AppliedToDimensions this test would silently become a copy of
        // testTheIndexMatchesWhatTheFileHolds.
        $seeded = persons_read_regions($this->image['file']);
        $this->assertTrue($seeded['ok'], 'anti-vacuity: the seeded file could not be read back');
        $this->assertCount(2, $seeded['regions'], 'anti-vacuity: the seeded file holds no regions');
        $this->assertNull($seeded['applied']['w'], 'precondition: the fixture must carry no AppliedToDimensions');

        $result = persons_rescan_images(array($this->image['id']));

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(array(), $result['failed']);

        $this->assertNotNull($this->personId(self::JANE), 'the name did not land in piwigo_persons');
        $this->assertNotNull($this->personId(self::JOHN), 'the name did not land in piwigo_persons');
        $this->assertSame(2, $this->regionCount());
        $this->assertNull($this->regionRow(self::JANE)['applied_w'], 'unknown dimensions must stay unknown, not 0');
        $this->assertNull($this->regionRow(self::JANE)['applied_h']);
        $this->assertTrue($this->photoCarriesTag(self::JANE));
        $this->assertTrue($this->photoCarriesTag(self::JOHN));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function seed(array $regions): void
    {
        $this->fixture->writeRegionsWithExiftool($this->image, $regions, self::APPLIED_W, self::APPLIED_H);
    }

    private function regionCount(): int
    {
        return (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . (int)$this->image['id']
        );
    }

    private function regionRow(string $name): ?array
    {
        $escaped = $this->db->escape($name);
        $result = $this->db->query(
            'SELECT r.* FROM piwigo_person_region AS r' .
            ' JOIN piwigo_persons AS p ON p.id = r.person_id' .
            " WHERE r.image_id = " . (int)$this->image['id'] . " AND p.name = '$escaped'"
        );
        $row = $result->fetch_assoc();
        return $row === null ? null : $row;
    }

    private function personId(string $name): ?int
    {
        $id = $this->db->scalar(
            "SELECT id FROM piwigo_persons WHERE name = '" . $this->db->escape($name) . "'"
        );
        return $id === null ? null : (int)$id;
    }

    private function imageTagCount(): int
    {
        return (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_image_tag WHERE image_id = ' . (int)$this->image['id']
        );
    }

    private function photoCarriesTag(string $name, ?int $imageId = null): bool
    {
        $escaped = $this->db->escape($name);
        return (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_image_tag AS it' .
            ' JOIN piwigo_tags AS t ON t.id = it.tag_id' .
            ' WHERE it.image_id = ' . (int)($imageId ?? $this->image['id']) . " AND t.name = '$escaped'"
        ) > 0;
    }
}
