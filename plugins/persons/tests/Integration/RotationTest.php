<?php
use PHPUnit\Framework\TestCase;

/**
 * What a reindex does when the photo's orientation has changed since its
 * regions were written.
 *
 * MWG stores a region against the file's own pixel grid, before Exif
 * Orientation is applied. Two very different events look alike from the index's
 * side, and conflating them is what would corrupt correct data:
 *
 *   - images.rotation changed but the file's dimensions still match the
 *     AppliedToDimensions in it: only the *display* transform moved. The bytes
 *     and the regions in them are still right, and nothing may be rewritten.
 *   - the file's dimensions are now the transpose of its AppliedToDimensions:
 *     something outside Piwigo physically turned the file, and every region has
 *     to turn with it.
 *
 * The pure arithmetic is covered at the unit layer (RegionGeometryTest). What
 * needs a real file and a real exiftool is the decision to write or not to
 * write, so that is all this asserts.
 */
final class RotationTest extends TestCase
{
    private const NAME = 'Persons Rotation Jane';

    /** The region seeded into the file, in MWG centre-origin fractions. */
    private const X = 0.25;
    private const Y = 0.4;
    private const W = 0.2;
    private const H = 0.3;

    /** A file that reads back shorter than this is not a JPEG/PNG exiftool read. */
    private const MIN_JSON_BYTES = 20;

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private int $width;
    private int $height;

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
        $this->width = (int)$this->image['width'];
        $this->height = (int)$this->image['height'];

        // A square image transposes onto itself, so a physical rotation of one
        // is undetectable by construction and every assertion below would be
        // vacuous. Loud rather than skipped: it means the source photo the
        // fixture copies changed, which is worth knowing.
        $this->assertNotSame($this->width, $this->height,
            'the fixture photo is square; a transpose is indistinguishable from no rotation');

        // Written explicitly rather than left at the column default: the whole
        // decision under test is a comparison against this value.
        $this->db->query('UPDATE piwigo_images SET rotation = 0 WHERE id = ' . (int)$this->image['id']);

        $this->fixture->writeRegionsWithExiftool(
            $this->image,
            array(array('name' => self::NAME, 'x' => self::X, 'y' => self::Y, 'w' => self::W, 'h' => self::H)),
            $this->width,
            $this->height
        );

        $first = persons_reindex_image((int)$this->image['id'], $this->image['file']);
        $this->assertTrue($first['ok'], 'the fixture could not be indexed: ' . $first['message']);
        $this->assertSame(1, $first['regions'], 'anti-vacuity: no region was indexed to rotate');
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyPersons(array(self::NAME));
    }

    /**
     * [DT] A rotation code that changed while the file kept its shape is a
     * display-only change: the file is left byte-for-byte alone.
     */
    public function testADisplayOnlyRotationChangeRewritesNothing(): void
    {
        $before = md5_file($this->image['file']);
        $this->assertNotFalse($before, 'the fixture file vanished');

        $this->db->query('UPDATE piwigo_images SET rotation = 1 WHERE id = ' . (int)$this->image['id']);

        $outcome = persons_reindex_image((int)$this->image['id'], $this->image['file']);

        $this->assertTrue($outcome['ok'], $outcome['message']);
        clearstatcache(true, $this->image['file']);
        $this->assertSame($before, md5_file($this->image['file']),
            'the file was rewritten for a change that only affects how it is displayed');

        $row = $this->regionRow();
        $this->assertEqualsWithDelta(self::X, (float)$row['area_x'], 1e-9);
        $this->assertEqualsWithDelta(self::Y, (float)$row['area_y'], 1e-9);
        $this->assertSame(1, (int)$row['rotation_at_write'],
            'the new rotation was not recorded, so the same change would be seen again');
    }

    /**
     * [DT] A file whose dimensions are now the transpose of what its regions
     * were written against was physically turned: the regions turn with it, in
     * the file as well as in the index.
     */
    public function testAPhysicallyRotatedFileHasItsRegionsCorrectedOnReindex(): void
    {
        // What Piwigo would hold after an external tool rotated the file and a
        // sync picked the new dimensions up.
        $this->db->query(
            'UPDATE piwigo_images SET rotation = 1, width = ' . $this->height
            . ', height = ' . $this->width . ' WHERE id = ' . (int)$this->image['id']
        );

        $outcome = persons_reindex_image((int)$this->image['id'], $this->image['file']);
        $this->assertTrue($outcome['ok'], $outcome['message']);
        $this->assertSame(1, $outcome['regions']);

        $expected = persons_rotate_region(
            array('x' => self::X, 'y' => self::Y, 'w' => self::W, 'h' => self::H),
            1
        );

        $inFile = $this->regionInFile();
        $this->assertEqualsWithDelta($expected['x'], (float)$inFile['Area']['X'], 1e-6);
        $this->assertEqualsWithDelta($expected['y'], (float)$inFile['Area']['Y'], 1e-6);
        $this->assertEqualsWithDelta($expected['w'], (float)$inFile['Area']['W'], 1e-6);
        $this->assertEqualsWithDelta($expected['h'], (float)$inFile['Area']['H'], 1e-6);

        $applied = $this->appliedInFile();
        $this->assertSame($this->height, (int)$applied['W'],
            'AppliedToDimensions still describes the pre-rotation file');
        $this->assertSame($this->width, (int)$applied['H']);

        $row = $this->regionRow();
        $this->assertEqualsWithDelta($expected['x'], (float)$row['area_x'], 1e-6);
        $this->assertEqualsWithDelta($expected['y'], (float)$row['area_y'], 1e-6);
    }

    /**
     * [ST] The correction happens once. A second reindex of the same file finds
     * AppliedToDimensions and the file agreeing again and leaves it alone.
     */
    public function testTheCorrectionIsNotAppliedTwice(): void
    {
        $this->db->query(
            'UPDATE piwigo_images SET rotation = 1, width = ' . $this->height
            . ', height = ' . $this->width . ' WHERE id = ' . (int)$this->image['id']
        );

        persons_reindex_image((int)$this->image['id'], $this->image['file']);
        $afterFirst = md5_file($this->image['file']);

        persons_reindex_image((int)$this->image['id'], $this->image['file']);

        clearstatcache(true, $this->image['file']);
        $this->assertSame($afterFirst, md5_file($this->image['file']),
            'the regions were turned a second time, so every further rescan moves them again');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function regionRow(): array
    {
        $result = $this->db->query(
            'SELECT area_x, area_y, area_w, area_h, rotation_at_write FROM piwigo_person_region'
            . ' WHERE image_id = ' . (int)$this->image['id']
        );
        $row = $result->fetch_assoc();
        $this->assertIsArray($row, 'the photo has no indexed region to assert on');

        return $row;
    }

    /** The one region the file itself carries, read by a plain exiftool call. */
    private function regionInFile(): array
    {
        $decoded = $this->exiftoolJson();
        $list = $decoded[0]['RegionInfo']['RegionList'] ?? array();
        $this->assertCount(1, $list, 'the file does not carry exactly one region');

        return $list[0];
    }

    private function appliedInFile(): array
    {
        $decoded = $this->exiftoolJson();
        $applied = $decoded[0]['RegionInfo']['AppliedToDimensions'] ?? null;
        $this->assertIsArray($applied, 'the file carries no AppliedToDimensions');

        return $applied;
    }

    private function exiftoolJson(): array
    {
        $output = array();
        exec('exiftool -json -struct -XMP-mwg-rs:RegionInfo '
            . escapeshellarg($this->image['file']) . ' 2>/dev/null', $output);

        $json = implode('', $output);
        $this->assertGreaterThan(self::MIN_JSON_BYTES, strlen($json),
            'anti-vacuity: exiftool returned nothing, so every assertion on it is meaningless');

        return json_decode($json, true);
    }
}
