<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for core's metadata sync of images.date_creation:
 * pwg.images.syncMetadata, the Batch Manager's "Synchronize metadata" action,
 * landing in sync_metadata() (admin/include/functions_metadata.php), which
 * maps EXIF DateTimeOriginal into date_creation ($conf['use_exif_mapping']).
 *
 * Every case here is [ERR]: the oracle is the current implementation, not a
 * requirement. Nothing promises that a file without a date leaves the stored
 * one alone, or that a PNG's EXIF is never read - these record that it does
 * today. They report a change; they do not prove the behaviour right.
 *
 * They land and pass on their first run, which is normally the tell that a test
 * recorded code rather than drove it. Here that is the point, so each was
 * watched go red by breaking the behaviour it claims to watch.
 *
 * Every file here carries camera metadata (Make, Model): what core does with a
 * date from a file without it is photoinfo's to decide
 * (plugins/photoinfo/tests/Integration/SyncMetadataTest.php).
 */
final class CoreMetadataSyncCharacterizationTest extends TestCase
{
    private const CAMERA_DATE = '2019:05:04 13:14:15';
    private const CAMERA_DATE_IN_ROW = '2019-05-04 13:14:15';
    private const STORED_DATE = '1965-01-01 00:00:00';

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
        $this->fixture = new FixtureBuilder($this->db);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->ws->logout();
    }

    /** [ERR] [HAPPY] A JPEG's DateTimeOriginal becomes date_creation, time included. */
    public function testAJpegsDateTimeOriginalBecomesTheCreationDate(): void
    {
        $image = $this->fixture->createTestImageAs('jpg');
        $this->writeCameraTags($image['file'], self::CAMERA_DATE);
        $this->assertNull($this->dateCreation($image['id']), 'anti-vacuity: the row starts without a date');

        $this->sync($image['id']);

        $this->assertSame(self::CAMERA_DATE_IN_ROW, $this->dateCreation($image['id']));
    }

    /** [ERR] [ST] A JPEG's DateTimeOriginal overwrites a date the row already holds. */
    public function testAJpegsDateTimeOriginalOverwritesAStoredDate(): void
    {
        $image = $this->fixture->createTestImageAs('jpg');
        $this->writeCameraTags($image['file'], self::CAMERA_DATE);
        $this->setDateCreation($image['id'], self::STORED_DATE);

        $this->sync($image['id']);

        $this->assertSame(self::CAMERA_DATE_IN_ROW, $this->dateCreation($image['id']));
    }

    /**
     * [ERR] [NEG] A JPEG without DateTimeOriginal leaves a stored date alone: the
     * missing value is skipped (MASS_UPDATES_SKIP_EMPTY), not written as NULL.
     */
    public function testAJpegWithoutADateKeepsTheStoredDate(): void
    {
        $image = $this->fixture->createTestImageAs('jpg');
        $this->writeCameraTags($image['file'], null);
        $this->setDateCreation($image['id'], self::STORED_DATE);

        $this->sync($image['id']);

        $this->assertSame(self::STORED_DATE, $this->dateCreation($image['id']));
    }

    /** [ERR] [NEG] The file's modification time is not a creation date: no EXIF date, no date. */
    public function testTheFileTimeIsNeverUsed(): void
    {
        $image = $this->fixture->createTestImageAs('jpg');
        $this->writeCameraTags($image['file'], null);
        $this->assertTrue(touch($image['file'], mktime(12, 0, 0, 6, 1, 2001)), 'cannot backdate the fixture file');

        $this->sync($image['id']);

        $this->assertNull($this->dateCreation($image['id']));
    }

    /**
     * [ERR] [ECP] PHP reads no EXIF from a PNG (exif_read_data() returns false, PHP
     * 8.4, measured 2026-10-08), so its DateTimeOriginal never reaches the row.
     */
    public function testAPngsDateTimeOriginalIsNotRead(): void
    {
        $image = $this->fixture->createTestImage();
        $this->writeCameraTags($image['file'], self::CAMERA_DATE);
        $this->setDateCreation($image['id'], self::STORED_DATE);

        $this->sync($image['id']);

        $this->assertSame(self::STORED_DATE, $this->dateCreation($image['id']));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Writes Make, Model and, unless null, DateTimeOriginal into a fixture
     * file, and asserts they are there.
     */
    private function writeCameraTags(string $file, ?string $dateTimeOriginal): void
    {
        $args = array('-EXIF:Make=PiwigoTestCam', '-EXIF:Model=T1');
        if ($dateTimeOriginal !== null)
        {
            $args[] = '-EXIF:DateTimeOriginal=' . $dateTimeOriginal;
        }
        $this->exiftool('-q -overwrite_original ' . implode(' ', array_map('escapeshellarg', $args)) . ' ' . escapeshellarg($file));

        $read = json_decode($this->exiftool('-j -EXIF:Make -EXIF:DateTimeOriginal ' . escapeshellarg($file)), true);
        $this->assertSame('PiwigoTestCam', $read[0]['Make'] ?? null, 'the camera tags were not written');
        $this->assertSame($dateTimeOriginal, $read[0]['DateTimeOriginal'] ?? null, 'DateTimeOriginal is not as intended');
    }

    private function exiftool(string $arguments): string
    {
        $output = array();
        $status = 1;
        exec('exiftool ' . $arguments, $output, $status);
        $this->assertSame(0, $status, 'exiftool failed: ' . implode("\n", $output));
        return implode("\n", $output);
    }

    private function sync(int $imageId): void
    {
        $res = $this->ws->call('pwg.images.syncMetadata', array(
            'image_id' => $imageId,
            'pwg_token' => $this->ws->token(),
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(1, (int)($res['json']['result']['nb_synchronized'] ?? 0), $res['body']);
    }

    private function setDateCreation(int $imageId, string $date): void
    {
        $this->db->query("UPDATE piwigo_images SET date_creation = '" . $this->db->escape($date) . "' WHERE id = $imageId");
        $this->assertSame($date, $this->dateCreation($imageId), 'the stored date was not set');
    }

    private function dateCreation(int $imageId): ?string
    {
        $value = $this->db->scalar("SELECT date_creation FROM piwigo_images WHERE id = $imageId");
        return $value === null ? null : (string)$value;
    }
}
