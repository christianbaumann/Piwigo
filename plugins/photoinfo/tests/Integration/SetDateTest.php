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
        $this->assertNull($row['photoinfo_date_qualifier']);
        $this->assertNull($row['photoinfo_date_end']);
        $this->assertSame(array(
            'XMP-photoshop:DateCreated' => null,
            'IPTC:DateCreated' => null,
            'XMP-pwginfo:DateEDTF' => null,
            'EXIF:DateTimeOriginal' => self::SCAN_DATE,
            ), FixtureBuilder::readDateTags($this->image['file']));
        $this->assertNull(FixtureBuilder::readFileTags($this->image['file'])['XMP-dc:Description']);
    }

    public static function qualified(): array
    {
        // qualifier, start, end => date_creation, qualifier, end, end precision, shown, XMP, IPTC, EDTF
        return array(
            'ca.' => array('circa', array('1965', '', ''), array('', '', ''),
                '1965-01-01 00:00:00', 'circa', null, null, 'ca. 1965', '1965', '1965:00:00', '1965~'),
            'vor' => array('before', array('1965', '', ''), array('', '', ''),
                '1965-01-01 00:00:00', 'before', null, null, 'vor 1965', '1965', '1965:00:00', '../1965'),
            'nach' => array('after', array('1965', '3', ''), array('', '', ''),
                '1965-03-01 00:00:00', 'after', null, null, 'nach März 1965', '1965-03', '1965:03:00', '1965-03/..'),
            'zwischen' => array('between', array('1965', '', ''), array('1970', '', ''),
                '1965-01-01 00:00:00', 'between', '1970-01-01', 'year', "1965\u{2013}1970", '1965', '1965:00:00', '1965/1970'),
            'zwischen, end with a day' => array('between', array('1965', '3', ''), array('1966', '5', '2'),
                '1965-03-01 00:00:00', 'between', '1966-05-02', 'day', "März 1965\u{2013}2. Mai 1966", '1965-03', '1965:03:00', '1965-03/1966-05-02'),
            );
    }

    /**
     * [DT] Each row of the design's combination table: the columns, the start
     * in both DateCreated slots, the EDTF string and the caption's text.
     */
    #[DataProvider('qualified')]
    public function testEachQualifierGoesIntoRowAndFile(string $qualifier, array $start, array $end,
        string $dateCreation, string $storedQualifier, ?string $storedEnd, ?string $endPrecision,
        string $shown, string $xmp, string $iptc, string $edtf): void
    {
        FixtureBuilder::writeDateTimeOriginal($this->image['file'], self::SCAN_DATE);

        $res = $this->setDate($this->ws, $start[0], $start[1], $start[2], $qualifier, $end);

        $this->assertTrue($res['json']['result']['written'], $res['body']);
        $this->assertSame($shown, $res['json']['result']['date']);
        $this->assertSame($edtf, $res['json']['result']['edtf']);

        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertSame($dateCreation, $row['date_creation']);
        $this->assertSame($storedQualifier, $row['photoinfo_date_qualifier']);
        $this->assertSame($storedEnd, $row['photoinfo_date_end']);
        $this->assertSame($endPrecision, $row['photoinfo_date_end_precision']);

        $this->assertSame(array(
            'XMP-photoshop:DateCreated' => $xmp,
            'IPTC:DateCreated' => $iptc,
            'XMP-pwginfo:DateEDTF' => $edtf,
            'EXIF:DateTimeOriginal' => self::SCAN_DATE,
            ), FixtureBuilder::readDateTags($this->image['file']));
        $this->assertSame($shown, FixtureBuilder::readFileTags($this->image['file'])['XMP-dc:Description']);
    }

    /** [ST] Going from a range back to an exact date clears the qualifier and the end. */
    public function testAnExactDateClearsAFormerRange(): void
    {
        $this->setDate($this->ws, '1965', '', '', 'between', array('1970', '', ''));

        $res = $this->setDate($this->ws, '1965', '3', '');

        $this->assertSame('März 1965', $res['json']['result']['date']);
        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertNull($row['photoinfo_date_qualifier']);
        $this->assertNull($row['photoinfo_date_end']);
        $this->assertNull($row['photoinfo_date_end_precision']);
        $this->assertSame('1965-03', FixtureBuilder::readDateTags($this->image['file'])['XMP-pwginfo:DateEDTF']);
    }

    public static function refusedRanges(): array
    {
        return array(
            'end before start [BVA]' => array('between', array('1965', '', ''), array('1964', '', '')),
            'end one day before start [BVA]' => array('between', array('1965', '3', '14'), array('1965', '3', '13')),
            'between with no end [NEG]' => array('between', array('1965', '', ''), array('', '', '')),
            'circa with an end [NEG]' => array('circa', array('1965', '', ''), array('1970', '', '')),
            'qualifier with no date [NEG]' => array('circa', array('', '', ''), array('', '', '')),
            'unknown qualifier [NEG]' => array('exact', array('1965', '', ''), array('', '', '')),
            'end in the next year [BVA]' => array('between', array('1965', '', ''), array((string)((int)date('Y') + 1), '', '')),
            );
    }

    /** [NEG] A range or qualifier the date model does not allow is refused, and nothing changes. */
    #[DataProvider('refusedRanges')]
    public function testAnImpossibleRangeIsRefused(string $qualifier, array $start, array $end): void
    {
        $this->fixture->setDate($this->image['id'], '1950-05-06 00:00:00', 'day');
        $before = md5_file($this->image['file']);

        $res = $this->call($this->ws, $start[0], $start[1], $start[2], $qualifier, $end);

        $this->assertSame(WS_ERR_INVALID_PARAM, $res['json']['err'] ?? null, $res['body']);
        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertSame('1950-05-06 00:00:00', $row['date_creation']);
        $this->assertNull($row['photoinfo_date_qualifier']);
        clearstatcache();
        $this->assertSame($before, md5_file($this->image['file']), 'the file was rewritten');
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

    private function call(WsClient $client, string $year, string $month, string $day,
        string $qualifier = '', array $end = array('', '', '')): array
    {
        return $client->call(self::METHOD, array(
            'image_id' => $this->image['id'],
            'qualifier' => $qualifier,
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'end_year' => $end[0],
            'end_month' => $end[1],
            'end_day' => $end[2],
            'pwg_token' => $client->token(),
        ));
    }

    private function setDate(WsClient $client, string $year, string $month, string $day,
        string $qualifier = '', array $end = array('', '', '')): array
    {
        $res = $this->call($client, $year, $month, $day, $qualifier, $end);
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
