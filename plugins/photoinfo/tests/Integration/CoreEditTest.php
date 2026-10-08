<?php
use PHPUnit\Framework\TestCase;

/**
 * A date or description saved in one of core's screens reaches photoinfo's
 * columns and the image file: the photo properties screen, the Batch Manager's
 * global "Set creation date", and pwg.images.setInfo (the Batch Manager's unit
 * mode). Each over its real boundary, the file read back with a plain exiftool
 * call.
 *
 * Requirements: design "Core admin screens set an exact date", "Core
 * description edits rewrite the caption".
 */
final class CoreEditTest extends TestCase
{
    private const CAPTION_SLOTS = array('XMP-dc:Description', 'IPTC:Caption-Abstract', 'EXIF:ImageDescription');
    /** A rendered admin page shorter than this is an error page or a redirect. */
    private const MIN_PAGE_BYTES = 2000;

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private int $albumId;
    private array $image;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');

        $this->albumId = $this->fixture->createTestAlbum('photoinfo-core-edit-' . bin2hex(random_bytes(4)));
        $this->image = $this->newPhoto();

        $this->ws = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->ws->login($username, $password);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    // ── the photo properties screen ───────────────────────────────────────

    /** [HAPPY] A date set there is an exact day, in the row and in the file. */
    public function testAPropertiesDateIsAnExactDayInRowAndFile(): void
    {
        $this->saveProperties($this->image['id'], array('date_creation' => '1965-03-14'));

        $this->assertExactDay($this->image, '1965-03-14 00:00:00');
        $this->assertDateInFile($this->image, '1965-03-14', '1965:03:14', '1965-03-14', '14. März 1965');
    }

    /** [ST] "ca. 1965" changed there shows the new exact date, without "ca.". */
    public function testAPropertiesDateReplacesAQualifiedOne(): void
    {
        $this->fixture->setDate($this->image['id'], '1965-01-01 00:00:00', 'year');
        $this->fixture->setQualifier($this->image['id'], 'circa');

        $this->saveProperties($this->image['id'], array('date_creation' => '1966-05-02'));

        $this->assertExactDay($this->image, '1966-05-02 00:00:00');
        $this->assertDateInFile($this->image, '1966-05-02', '1966:05:02', '1966-05-02', '2. Mai 1966');
    }

    /** [ST] A range changed there loses its end too, so it never reads "2019–1970". */
    public function testAPropertiesDateEndsARange(): void
    {
        $this->fixture->setDate($this->image['id'], '1965-01-01 00:00:00', 'year');
        $this->fixture->setQualifier($this->image['id'], 'between', '1970-01-01', 'year');

        $this->saveProperties($this->image['id'], array('date_creation' => '2019-11-02'));

        $this->assertExactDay($this->image, '2019-11-02 00:00:00');
        $this->assertDateInFile($this->image, '2019-11-02', '2019:11:02', '2019-11-02', '2. November 2019');
    }

    /** [ST] "1965" set to 14.03.1965 there shows the day, not the year. */
    public function testAPropertiesDateRaisesAYearToADay(): void
    {
        $this->fixture->setDate($this->image['id'], '1965-01-01 00:00:00', 'year');

        $this->saveProperties($this->image['id'], array('date_creation' => '1965-03-14'));

        $this->assertExactDay($this->image, '1965-03-14 00:00:00');
    }

    /**
     * [NEG] The screen posts the date on every save: posted back unchanged, it
     * leaves "ca. 1965" alone, and with the description unchanged too nothing is
     * written into the file.
     */
    public function testAnUnchangedPropertiesDateKeepsTheQualifier(): void
    {
        $this->fixture->setDate($this->image['id'], '1965-01-01 00:00:00', 'year');
        $this->fixture->setQualifier($this->image['id'], 'circa');

        $this->saveProperties($this->image['id'], array('date_creation' => '1965-01-01 00:00:00', 'name' => 'Neuer Titel'));

        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertSame('Neuer Titel', $row['name'], 'anti-vacuity: the save must have run');
        $this->assertSame('year', $row['photoinfo_date_precision']);
        $this->assertSame('circa', $row['photoinfo_date_qualifier']);
        $this->assertNull(FixtureBuilder::readDateTags($this->image['file'])['XMP-pwginfo:DateEDTF'], 'the file was written');
    }

    /** [HAPPY] A description changed there is first in each caption, and alone in the info tag. */
    public function testAPropertiesDescriptionLeadsTheCaption(): void
    {
        $provenance = $this->writeProvenance(array('provenance_owner' => 'Anna Mueller'));

        $this->saveProperties($this->image['id'], array('comment' => 'Hochzeit von Anna und Paul'));

        $tags = FixtureBuilder::readFileTags($this->image['file']);
        foreach (self::CAPTION_SLOTS as $slot)
        {
            $this->assertSame("Hochzeit von Anna und Paul\n\n" . $provenance, $tags[$slot], $slot);
        }
        $this->assertSame('Hochzeit von Anna und Paul', $tags['XMP-pwginfo:Info']);
    }

    /** [ST] Removing the date there clears the plugin's columns and the file's date tags. */
    public function testRemovingThePropertiesDateClearsColumnsAndFile(): void
    {
        $this->fixture->setDate($this->image['id'], '1965-01-01 00:00:00', 'year');
        $this->fixture->setQualifier($this->image['id'], 'between', '1970-01-01', 'year');
        $this->saveProperties($this->image['id'], array('date_creation' => '1965-03-14'));
        $this->assertSame('1965-03-14', FixtureBuilder::readDateTags($this->image['file'])['XMP-photoshop:DateCreated'],
            'anti-vacuity: the file must start with a date');

        $this->saveProperties($this->image['id'], array('date_creation' => ''));

        $this->assertNoDate($this->image);
    }

    // ── the Batch Manager, global mode ────────────────────────────────────

    /** [HAPPY] A date set for several photos reaches each one's row and file. */
    public function testABatchDateReachesEveryFile(): void
    {
        $other = $this->newPhoto();
        $this->fixture->setDate($other['id'], '1965-01-01 00:00:00', 'year');
        $this->fixture->setQualifier($other['id'], 'circa');

        $this->batchDate(array($this->image['id'], $other['id']), array('date_creation' => '1965-03-14'));

        foreach (array($this->image, $other) as $photo)
        {
            $this->assertExactDay($photo, '1965-03-14 00:00:00');
            $this->assertDateInFile($photo, '1965-03-14', '1965:03:14', '1965-03-14', '14. März 1965');
        }
    }

    /** [ST] "remove creation date" clears every selected photo's columns and file. */
    public function testABatchRemovalClearsEveryFile(): void
    {
        $other = $this->newPhoto();
        $ids = array($this->image['id'], $other['id']);
        $this->batchDate($ids, array('date_creation' => '1965-03-14'));
        $this->assertSame('1965-03-14', FixtureBuilder::readDateTags($other['file'])['XMP-photoshop:DateCreated'],
            'anti-vacuity: the file must start with a date');

        $this->batchDate($ids, array('date_creation' => '1965-03-14', 'remove_date_creation' => 'on'));

        $this->assertNoDate($this->image);
        $this->assertNoDate($other);
    }

    // ── pwg.images.setInfo, the Batch Manager's unit mode ─────────────────

    /** [HAPPY] Date and description changed together land in row and file, and the answer says so. */
    public function testSetInfoWritesDateAndDescription(): void
    {
        $this->fixture->setDate($this->image['id'], '1965-01-01 00:00:00', 'year');
        $this->fixture->setQualifier($this->image['id'], 'circa');

        $res = $this->setInfo(array('date_creation' => '1965-03-14', 'comment' => 'Am See'));

        $this->assertTrue($res['json']['result']['written'], $res['body']);
        $this->assertExactDay($this->image, '1965-03-14 00:00:00');
        $this->assertDateInFile($this->image, '1965-03-14', '1965:03:14', '1965-03-14', "14. März 1965\nAm See");
        $this->assertSame('Am See', FixtureBuilder::readFileTags($this->image['file'])['XMP-pwginfo:Info']);
    }

    /**
     * [NEG] The unit mode posts every field of every photo it saves: the stored
     * date and description posted back keep "ca. 1965", write nothing, and leave
     * core's answer as it was.
     */
    public function testSetInfoWithTheStoredValuesKeepsTheQualifier(): void
    {
        $this->fixture->setDate($this->image['id'], '1965-01-01 00:00:00', 'year');
        $this->fixture->setQualifier($this->image['id'], 'circa');
        $this->fixture->setComment($this->image['id'], 'Am See');

        $res = $this->setInfo(array('date_creation' => '1965-01-01 00:00:00', 'comment' => 'Am See', 'name' => 'Neu'));

        $this->assertNull($res['json']['result'], $res['body']);
        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertSame('Neu', $row['name'], 'anti-vacuity: the save must have run');
        $this->assertSame('circa', $row['photoinfo_date_qualifier']);
        $this->assertNull(FixtureBuilder::readFileTags($this->image['file'])['XMP-pwginfo:Info'], 'the file was written');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function newPhoto(): array
    {
        $photo = $this->fixture->createTestImage();
        $this->fixture->attachImage($photo['id'], $this->albumId);
        return $photo;
    }

    private function saveProperties(int $imageId, array $fields): void
    {
        $row = $this->fixture->imageRow($imageId);
        $body = $this->ws->postPage('/admin.php?page=photo-' . $imageId . '-properties', array_merge(array(
            'name' => (string)$row['name'],
            'author' => (string)$row['author'],
            'comment' => (string)$row['comment'],
            'date_creation' => (string)$row['date_creation'],
            'level' => 0,
            'associate' => array($this->albumId),
            'pwg_token' => $this->ws->token(),
            'submit' => 1,
            ), $fields));

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), 'the properties screen did not answer');
        $this->assertStringNotContainsString('Photo Info:', $body, 'the file write failed');
    }

    private function batchDate(array $ids, array $fields): void
    {
        $body = $this->ws->postPage('/admin.php?page=batch_manager&mode=global', array_merge(array(
            'pwg_token' => $this->ws->token(),
            'selection' => $ids,
            'selectAction' => 'date_creation',
            'submit' => 1,
            ), $fields));

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), 'the Batch Manager did not answer');
        $this->assertStringNotContainsString('Photo Info:', $body, 'a file write failed');
    }

    private function setInfo(array $fields): array
    {
        $res = $this->ws->call('pwg.images.setInfo', array_merge(array(
            'image_id' => $this->image['id'],
            'single_value_mode' => 'replace',
            'pwg_token' => $this->ws->token(),
            ), $fields));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        return $res;
    }

    /** Writes the photo's provenance into its file and returns the caption that put there. */
    private function writeProvenance(array $values): string
    {
        $this->fixture->setProvenance($this->image['id'], $values);
        $res = $this->ws->call('pwg.provenance.writeBack', array(
            'image_ids' => (string)$this->image['id'],
            'pwg_token' => $this->ws->token(),
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(1, $res['json']['result']['written'], $res['body']);

        $caption = FixtureBuilder::readFileTags($this->image['file'])['XMP-dc:Description'];
        $this->assertNotEmpty($caption, 'anti-vacuity: the provenance write-back put no caption into the file');
        return $caption;
    }

    private function assertExactDay(array $photo, string $dateCreation): void
    {
        $row = $this->fixture->imageRow($photo['id']);
        $this->assertSame($dateCreation, $row['date_creation']);
        $this->assertSame('day', $row['photoinfo_date_precision']);
        $this->assertNull($row['photoinfo_date_qualifier']);
        $this->assertNull($row['photoinfo_date_end']);
        $this->assertNull($row['photoinfo_date_end_precision']);
    }

    private function assertDateInFile(array $photo, string $xmp, string $iptc, string $edtf, string $caption): void
    {
        $dates = FixtureBuilder::readDateTags($photo['file']);
        $this->assertSame($xmp, $dates['XMP-photoshop:DateCreated']);
        $this->assertSame($iptc, $dates['IPTC:DateCreated']);
        $this->assertSame($edtf, $dates['XMP-pwginfo:DateEDTF']);

        $tags = FixtureBuilder::readFileTags($photo['file']);
        foreach (self::CAPTION_SLOTS as $slot)
        {
            $this->assertSame($caption, $tags[$slot], $slot);
        }
    }

    private function assertNoDate(array $photo): void
    {
        $row = $this->fixture->imageRow($photo['id']);
        $this->assertNull($row['date_creation']);
        foreach (array('photoinfo_date_precision', 'photoinfo_date_qualifier', 'photoinfo_date_end', 'photoinfo_date_end_precision') as $column)
        {
            $this->assertNull($row[$column], $column);
        }

        $dates = FixtureBuilder::readDateTags($photo['file']);
        $this->assertNull($dates['XMP-photoshop:DateCreated']);
        $this->assertNull($dates['IPTC:DateCreated']);
        $this->assertNull($dates['XMP-pwginfo:DateEDTF']);
    }
}
