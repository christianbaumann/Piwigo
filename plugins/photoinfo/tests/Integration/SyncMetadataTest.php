<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Core's metadata sync (pwg.images.syncMetadata, the Batch Manager's
 * "Synchronize metadata") against photoinfo's format_exif_data handler, over
 * ws.php, MariaDB and real image files.
 *
 * Requirements: design "Never write DateTimeOriginal; protect the date on
 * re-sync", "A file's own date counts only with camera metadata". What core
 * does without the handler is recorded in provenance's
 * CoreMetadataSyncCharacterizationTest.
 */
final class SyncMetadataTest extends TestCase
{
    private const CAMERA_DATE = '2019:05:04 13:14:15';

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');

        $this->ws = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->ws->login($username, $password);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [HAPPY] A photoinfo date survives a sync of a camera file that carries a date of its own. */
    public function testAQualifiedPhotoinfoDateSurvivesTheSync(): void
    {
        $image = $this->fixture->createTestImageAs('jpg');
        FixtureBuilder::writeExifDate($image['file'], self::CAMERA_DATE, true);
        $this->fixture->setDate($image['id'], '1965-01-01 00:00:00', 'year');
        $this->fixture->setQualifier($image['id'], 'circa');
        $this->assertSame('ca. 1965', $this->shown($image['id']), 'anti-vacuity: the fixture date');

        $this->sync($image['id']);

        $this->assertSame('ca. 1965', $this->shown($image['id']));
    }

    /**
     * [HAPPY] A range keeps start, qualifier and end together: a sync that
     * rewrote only date_creation would leave an end before its start.
     */
    public function testARangeSurvivesTheSync(): void
    {
        $image = $this->fixture->createTestImageAs('jpg');
        FixtureBuilder::writeExifDate($image['file'], self::CAMERA_DATE, true);
        $this->fixture->setDate($image['id'], '1965-01-01 00:00:00', 'year');
        $this->fixture->setQualifier($image['id'], 'between', '1970-01-01', 'year');
        $before = $this->dateColumns($image['id']);

        $this->sync($image['id']);

        $this->assertSame($before, $this->dateColumns($image['id']));
        $this->assertSame("1965\u{2013}1970", $this->shown($image['id']));
    }

    /** [NEG] A file without camera metadata - a scan - gives the photo no date. */
    public function testAScansDateIsNotTaken(): void
    {
        $image = $this->fixture->createTestImageAs('jpg');
        FixtureBuilder::writeExifDate($image['file'], self::CAMERA_DATE, false);

        $this->sync($image['id']);

        $this->assertNull($this->fixture->imageRow($image['id'])['date_creation']);
    }

    /** [HAPPY] A camera file's date is taken as an exact day, with no qualifier. */
    public function testACameraFilesDateIsTakenAsAnExactDay(): void
    {
        $image = $this->fixture->createTestImageAs('jpg');
        FixtureBuilder::writeExifDate($image['file'], self::CAMERA_DATE, true);

        $this->sync($image['id']);

        $row = $this->fixture->imageRow($image['id']);
        $this->assertSame('2019-05-04 13:14:15', $row['date_creation']);
        $this->assertNull($row['photoinfo_date_qualifier']);
        $this->assertSame('4. Mai 2019', $this->shown($image['id']));
    }

    /**
     * [ECP] A date photoinfo did not set - no precision stored, as core's own
     * screens leave it - is not protected: a camera file's date replaces it.
     */
    public function testADateNotSetWithPhotoinfoIsReplacedByACameraDate(): void
    {
        $image = $this->fixture->createTestImageAs('jpg');
        FixtureBuilder::writeExifDate($image['file'], self::CAMERA_DATE, true);
        $this->fixture->setDate($image['id'], '2001-01-01 00:00:00', null);

        $this->sync($image['id']);

        $this->assertSame('2019-05-04 13:14:15', $this->fixture->imageRow($image['id'])['date_creation']);
    }

    /** [NEG] Without any date in the file, the file's own time is not used either. */
    public function testTheFileTimeIsNeverUsed(): void
    {
        $image = $this->fixture->createTestImageAs('jpg');
        FixtureBuilder::writeExifDate($image['file'], null, true);
        $this->assertTrue(touch($image['file'], mktime(12, 0, 0, 6, 1, 2001)), 'cannot backdate the fixture file');

        $this->sync($image['id']);

        $this->assertNull($this->fixture->imageRow($image['id'])['date_creation']);
    }

    public static function formatsPhpCannotRead(): array
    {
        return array('PNG' => array('png'), 'HEIC' => array('heic'));
    }

    /**
     * [ERR] PHP 8.4 reads no EXIF from a PNG or a HEIC (exif_read_data()
     * returns false, measured 2026-10-08), so core hands the handler null and a
     * camera date in such a file is never taken. Records PHP's behaviour, not a
     * requirement: a PHP that learns to read either would change it.
     */
    #[DataProvider('formatsPhpCannotRead')]
    public function testACameraDateInAFormatPhpCannotReadIsNotTaken(string $extension): void
    {
        $image = $extension === 'png'
            ? $this->fixture->createTestImage()
            : $this->fixture->createTestImageAs($extension);
        FixtureBuilder::writeExifDate($image['file'], self::CAMERA_DATE, true);

        $this->sync($image['id']);

        $this->assertNull($this->fixture->imageRow($image['id'])['date_creation']);
    }

    /**
     * [HAPPY] A HEIC uploaded under ImageMagick gets a JPEG representative that
     * keeps the phone's Make, Model and date, and core reads EXIF from that
     * representative. A photoinfo date still survives the sync.
     */
    public function testAPhotoinfoDateSurvivesASyncThatReadsTheRepresentative(): void
    {
        $image = $this->fixture->createTestImageAs('heic');
        $representative = $this->fixture->addRepresentative($image);
        FixtureBuilder::writeExifDate($representative, self::CAMERA_DATE, true);
        $this->fixture->setDate($image['id'], '1965-01-01 00:00:00', 'year');

        $this->sync($image['id']);

        $this->assertSame('1965', $this->shown($image['id']));
    }

    public static function emptyOverrideFormats(): array
    {
        return array('JPEG with a camera date' => array('jpg', self::CAMERA_DATE), 'PNG' => array('png', null));
    }

    /**
     * [HAPPY] The filesystem sync with "meta_empty_overrides" writes every value
     * the file lacks as NULL (admin/site_update.php, mass_updates() without
     * MASS_UPDATES_SKIP_EMPTY). A photoinfo date survives it whole - whether the
     * file had a date of its own or PHP read nothing at all.
     */
    #[DataProvider('emptyOverrideFormats')]
    public function testAPhotoinfoDateSurvivesASyncThatOverridesWithEmptyValues(string $extension, ?string $fileDate): void
    {
        $image = $this->fixture->createPhysicalTestImage($extension);
        if ($fileDate !== null)
        {
            FixtureBuilder::writeExifDate($image['file'], $fileDate, true);
        }
        $this->fixture->setDate($image['id'], '1965-01-01 00:00:00', 'year');
        $this->fixture->setQualifier($image['id'], 'between', '1970-01-01', 'year');
        $before = $this->dateColumns($image['id']);
        $this->assertNull($this->fixture->imageRow($image['id'])['date_metadata_update'], 'anti-vacuity: never synced');

        $this->ws->postPage('/admin.php?page=site_update&site=1', array(
            'sync' => 'metadata',
            'cat' => $image['album'],
            'sync_meta' => '1',
            'meta_all' => '1',
            'meta_empty_overrides' => '1',
            'simulate' => '0',
            'submit' => 'Submit',
            ));

        $this->assertNotNull($this->fixture->imageRow($image['id'])['date_metadata_update'], 'the filesystem sync did not run');
        $this->assertSame($before, $this->dateColumns($image['id']));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function sync(int $imageId): void
    {
        $res = $this->ws->call('pwg.images.syncMetadata', array(
            'image_id' => $imageId,
            'pwg_token' => $this->ws->token(),
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(1, (int)($res['json']['result']['nb_synchronized'] ?? 0), $res['body']);
    }

    /** The photo's date as the picture page shows it. */
    private function shown(int $imageId): string
    {
        return photoinfo_dating_display(photoinfo_dating_from_row($this->dateColumns($imageId)));
    }

    private function dateColumns(int $imageId): array
    {
        return array_intersect_key($this->fixture->imageRow($imageId), photoinfo_dating_columns(null));
    }
}
