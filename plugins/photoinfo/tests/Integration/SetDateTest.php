<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * pwg.photoinfo.setDate across its real boundaries: ws.php, MariaDB and the
 * image file, read back with a plain exiftool call.
 *
 * Requirements: design "Date model", "Date tags in the file", "Never write
 * DateTimeOriginal", "Year bounds 1800 to the current year".
 */
final class SetDateTest extends TestCase
{
    private const METHOD = 'pwg.photoinfo.setDate';
    private const CAPTION_SLOTS = array('XMP-dc:Description', 'IPTC:Caption-Abstract', 'EXIF:ImageDescription');
    private const SCAN_DATE = '2019:11:02 10:15:00';

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private WsClient $ws;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');

        $this->image = $this->fixture->createTestImage();
        $this->ws = $this->clientAs(TestUsers::WEBMASTER);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    public static function precisions(): array
    {
        // year, month, day => date_creation, precision, shown, XMP, IPTC, EDTF
        return array(
            'year' => array('1965', '', '', '1965-01-01 00:00:00', 'year', '1965', '1965', '1965:00:00', '1965'),
            'month' => array('1965', '3', '', '1965-03-01 00:00:00', 'month', 'März 1965', '1965-03', '1965:03:00', '1965-03'),
            'day' => array('1965', '3', '14', '1965-03-14 00:00:00', 'day', '14. März 1965', '1965-03-14', '1965:03:14', '1965-03-14'),
            );
    }

    /**
     * [HAPPY] Each precision lands in the row, in the three date tags and at the
     * head of the caption - and DateTimeOriginal stays the scan date.
     */
    #[DataProvider('precisions')]
    public function testEachPrecisionGoesIntoRowAndFile(string $year, string $month, string $day,
        string $start, string $precision, string $shown, string $xmp, string $iptc, string $edtf): void
    {
        FixtureBuilder::writeDateTimeOriginal($this->image['file'], self::SCAN_DATE);

        $res = $this->setDate($this->ws, $year, $month, $day);

        $this->assertTrue($res['json']['result']['written'], $res['body']);
        $this->assertSame($shown, $res['json']['result']['date']);

        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertSame($start, $row['date_creation']);
        $this->assertSame($precision, $row['photoinfo_date_precision']);

        $this->assertSame(array(
            'XMP-photoshop:DateCreated' => $xmp,
            'IPTC:DateCreated' => $iptc,
            'XMP-pwginfo:DateEDTF' => $edtf,
            'EXIF:DateTimeOriginal' => self::SCAN_DATE,
            ), FixtureBuilder::readDateTags($this->image['file']));

        $tags = FixtureBuilder::readFileTags($this->image['file']);
        foreach (self::CAPTION_SLOTS as $slot)
        {
            $this->assertSame($shown, $tags[$slot], $slot);
        }
    }

    /** [HAPPY] Date, info text and provenance share the caption in that order. */
    public function testTheDateLeadsTheCaptionAheadOfInfoAndProvenance(): void
    {
        $this->fixture->setProvenance($this->image['id'], array('provenance_owner' => 'Anna Mueller'));
        $this->assertWriteBack();
        $provenance = FixtureBuilder::readFileTags($this->image['file'])['XMP-dc:Description'];
        $this->assertNotEmpty($provenance, 'anti-vacuity: no provenance caption');

        $this->setInfo('Hochzeit');
        $this->setDate($this->ws, '1965', '3', '');

        $tags = FixtureBuilder::readFileTags($this->image['file']);
        $this->assertSame("März 1965\nHochzeit\n\n" . $provenance, $tags['XMP-dc:Description']);
        $this->assertSame('Hochzeit', $tags['XMP-pwginfo:Info'], 'a date save leaves the info tag alone');

        // Saving the info text afterwards keeps the date in the caption.
        $this->setInfo('Taufe');
        $this->assertSame("März 1965\nTaufe\n\n" . $provenance,
            FixtureBuilder::readFileTags($this->image['file'])['XMP-dc:Description']);
    }

    /** [HAPPY] A provenance write-back keeps the date first. */
    public function testAProvenanceWriteBackKeepsTheDateFirst(): void
    {
        $this->setDate($this->ws, '1965', '', '');
        $this->fixture->setProvenance($this->image['id'], array('provenance_owner' => 'Paul Schmidt'));

        $this->assertWriteBack();

        $caption = FixtureBuilder::readFileTags($this->image['file'])['XMP-dc:Description'];
        $this->assertStringStartsWith("1965\n\n", $caption);
        $this->assertStringContainsString('Paul Schmidt', $caption);
        $this->assertSame('1965', FixtureBuilder::readDateTags($this->image['file'])['XMP-pwginfo:DateEDTF']);
    }

    /** [ST] Clearing removes the date from the row and every date tag, and keeps the scan date. */
    public function testClearingRemovesTheDateFromRowAndFile(): void
    {
        FixtureBuilder::writeDateTimeOriginal($this->image['file'], self::SCAN_DATE);
        $this->setDate($this->ws, '1965', '3', '14');

        $res = $this->setDate($this->ws, '', '', '');

        $this->assertTrue($res['json']['result']['written'], $res['body']);
        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertNull($row['date_creation']);
        $this->assertNull($row['photoinfo_date_precision']);
        $this->assertSame(array(
            'XMP-photoshop:DateCreated' => null,
            'IPTC:DateCreated' => null,
            'XMP-pwginfo:DateEDTF' => null,
            'EXIF:DateTimeOriginal' => self::SCAN_DATE,
            ), FixtureBuilder::readDateTags($this->image['file']));
        $this->assertNull(FixtureBuilder::readFileTags($this->image['file'])['XMP-dc:Description']);
    }

    /** [HAPPY] The calendar lists the photo under its start date. */
    public function testTheCalendarListsThePhotoUnderTheStartDate(): void
    {
        $album = $this->fixture->createTestAlbum('Photoinfo test ' . bin2hex(random_bytes(4)));
        $this->fixture->attachImage((int)$this->image['id'], $album);
        $this->fixture->invalidateUserCache();

        $this->setDate($this->ws, '1965', '3', '');

        $link = 'picture.php?/' . $this->image['id'] . '/';
        $guest = new WsClient();
        $startDay = $guest->fetchPage('/index.php?/created-monthly-list-1965-03-01');
        $this->assertStringContainsString('id="thumbnails"', $startDay, 'anti-vacuity: not a calendar list');
        $this->assertStringContainsString($link, $startDay);
        $this->assertStringNotContainsString($link, $guest->fetchPage('/index.php?/created-monthly-list-1965-03-02'));
    }

    public static function refusedDates(): array
    {
        return array(
            '1799 [BVA]' => array('1799', '', ''),
            'next year [BVA]' => array((string)((int)date('Y') + 1), '', ''),
            '31 April [BVA]' => array('1965', '4', '31'),
            '29 February 1965 [BVA]' => array('1965', '2', '29'),
            'day without a month [NEG]' => array('1965', '', '14'),
            'month without a year [NEG]' => array('', '3', ''),
            );
    }

    /** [NEG] An impossible date is refused, and nothing changes. */
    #[DataProvider('refusedDates')]
    public function testAnImpossibleDateIsRefused(string $year, string $month, string $day): void
    {
        $this->fixture->setDate($this->image['id'], '1950-05-06 00:00:00', 'day');
        $before = md5_file($this->image['file']);

        $res = $this->call($this->ws, $year, $month, $day);

        $this->assertSame(WS_ERR_INVALID_PARAM, $res['json']['err'] ?? null, $res['body']);
        $this->assertSame('1950-05-06 00:00:00', $this->fixture->imageRow($this->image['id'])['date_creation']);
        clearstatcache();
        $this->assertSame($before, md5_file($this->image['file']), 'the file was rewritten');
    }

    /** [NEG] A year that is not a string is refused. */
    public function testAnArrayYearIsRefused(): void
    {
        $res = $this->ws->call(self::METHOD, array(
            'image_id' => $this->image['id'],
            'year' => array('1965'),
            'pwg_token' => $this->ws->token(),
        ));

        $this->assertSame(WS_ERR_INVALID_PARAM, $res['json']['err'] ?? null, $res['body']);
    }

    /** [ECP] An administrator who is not the webmaster may save too. */
    public function testAnAdministratorMaySave(): void
    {
        $this->setDate($this->clientAs(TestUsers::ADMIN), '1971', '', '');

        $this->assertSame('1971-01-01 00:00:00', $this->fixture->imageRow($this->image['id'])['date_creation']);
    }

    public static function refusedAccounts(): array
    {
        return array(
            'normal user' => array(TestUsers::NORMAL),
            'guest' => array(null),
            );
    }

    /** [NEG] A normal user and a guest are refused, and nothing changes. */
    #[DataProvider('refusedAccounts')]
    public function testOthersAreRefused(?string $role): void
    {
        $client = $this->clientAs($role);
        $before = md5_file($this->image['file']);

        $res = $client->call(self::METHOD, array(
            'image_id' => $this->image['id'],
            'year' => '1965',
            'pwg_token' => $role === null ? 'none' : $client->token(),
        ));

        $this->assertSame(401, $res['json']['err'] ?? null, $res['body']);
        $this->assertNull($this->fixture->imageRow($this->image['id'])['date_creation']);
        clearstatcache();
        $this->assertSame($before, md5_file($this->image['file']), 'the file was rewritten');
    }

    /** [NEG] A wrong token is refused. */
    public function testAWrongTokenIsRefused(): void
    {
        $res = $this->ws->call(self::METHOD, array('image_id' => $this->image['id'], 'year' => '1965', 'pwg_token' => 'wrong'));

        $this->assertSame(403, $res['json']['err'] ?? null, $res['body']);
        $this->assertNull($this->fixture->imageRow($this->image['id'])['date_creation']);
    }

    /** [NEG] A GET request is refused: the method changes state. */
    public function testAGetRequestIsRefused(): void
    {
        $res = $this->ws->callGet(self::METHOD, array('image_id' => $this->image['id'], 'year' => '1965', 'pwg_token' => $this->ws->token()));

        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
        $this->assertNull($this->fixture->imageRow($this->image['id'])['date_creation']);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function clientAs(?string $role): WsClient
    {
        $client = new WsClient();
        if ($role !== null)
        {
            list($username, $password) = Config::credentials($role);
            $client->login($username, $password);
        }
        return $client;
    }

    private function call(WsClient $client, string $year, string $month, string $day): array
    {
        return $client->call(self::METHOD, array(
            'image_id' => $this->image['id'],
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'pwg_token' => $client->token(),
        ));
    }

    private function setDate(WsClient $client, string $year, string $month, string $day): array
    {
        $res = $this->call($client, $year, $month, $day);
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        return $res;
    }

    private function setInfo(string $info): void
    {
        $res = $this->ws->call('pwg.photoinfo.setInfo', array(
            'image_id' => $this->image['id'],
            'info' => $info,
            'pwg_token' => $this->ws->token(),
        ));
        $this->assertTrue($res['json']['result']['written'] ?? false, $res['body']);
    }

    private function assertWriteBack(): void
    {
        $res = $this->ws->call('pwg.provenance.writeBack', array(
            'image_ids' => (string)$this->image['id'],
            'pwg_token' => $this->ws->token(),
        ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(1, $res['json']['result']['written'], $res['body']);
    }
}
