<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * pwg.photoinfo.rescan across its real boundaries: ws.php, MariaDB and an
 * image file written by the plugin's own saves, then read back by exiftool.
 *
 * Requirements: design "The file carries photoinfo's values separately, and a
 * rescan rebuilds them" (decision 0023: the database is never deployed).
 */
final class RescanTest extends TestCase
{
    private const METHOD = 'pwg.photoinfo.rescan';
    private const INFO = "Hochzeit von Anna und Paul\nim Garten  \n<b>Wichtig</b>";

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
    }

    public static function datings(): array
    {
        // qualifier, start, end => the EDTF the file carries
        return array(
            // exiftool reads this one as a fraction without photoinfo's config.
            'range of years' => array('between', array('1965', '', ''), array('1970', '', ''), '1965/1970'),
            'range of a month and a day' => array('between', array('1965', '3', ''), array('1966', '5', '2'), '1965-03/1966-05-02'),
            'circa a day' => array('circa', array('1965', '3', '14'), array('', '', ''), '1965-03-14~'),
            'before a year' => array('before', array('1965', '', ''), array('', '', ''), '../1965'),
            );
    }

    /**
     * [HAPPY] After the row lost the date and the info text, a rescan puts back
     * what the saves stored - every column - and leaves the file as it was.
     */
    #[DataProvider('datings')]
    public function testARescanRestoresWhatTheDatabaseLost(string $qualifier, array $start, array $end, string $edtf): void
    {
        $this->save('pwg.photoinfo.setInfo', array('info' => self::INFO));
        $this->save('pwg.photoinfo.setDate', array('qualifier' => $qualifier,
            'year' => $start[0], 'month' => $start[1], 'day' => $start[2],
            'end_year' => $end[0], 'end_month' => $end[1], 'end_day' => $end[2]));
        $this->assertSame($edtf, FixtureBuilder::readDateTags($this->image['file'])['XMP-pwginfo:DateEDTF'],
            'anti-vacuity: the save put no EDTF into the file');
        $saved = $this->photoinfoColumns();
        $this->assertSame(self::INFO, $saved['comment'], 'anti-vacuity: the save stored another text');

        $this->clearRow();
        // The saves left exiftool's sidecar; one there afterwards is the rescan's.
        $sidecar = $this->image['file'] . '_original';
        $this->assertFileExists($sidecar, 'anti-vacuity: the saves wrote no file');
        unlink($sidecar);
        clearstatcache();
        $checksum = md5_file($this->image['file']);

        $res = $this->rescan((string)$this->image['id']);

        $this->assertSame(array('scanned' => 1, 'failed' => array()), $res['json']['result'], $res['body']);
        $this->assertSame($saved, $this->photoinfoColumns());
        clearstatcache();
        $this->assertSame($checksum, md5_file($this->image['file']), 'the rescan rewrote the file');
        $this->assertFileDoesNotExist($sidecar, 'the rescan ran an exiftool write');
    }

    /** [ECP] A file without photoinfo's tags leaves the row's date and text as they are. */
    public function testAFileWithoutTheTagsLeavesTheRowAlone(): void
    {
        $this->fixture->setComment($this->image['id'], 'nur in der Datenbank');
        $this->fixture->setDate($this->image['id'], '1987-06-21 14:30:00', null);
        $this->assertSame(array('info' => null, 'edtf' => null), $this->fileTags(), 'anti-vacuity: the fixture file has tags');
        $before = $this->photoinfoColumns();

        $res = $this->rescan((string)$this->image['id']);

        $this->assertSame(array('scanned' => 1, 'failed' => array()), $res['json']['result'], $res['body']);
        $this->assertSame($before, $this->photoinfoColumns());
    }

    /**
     * [NEG] An EDTF the editor cannot hold - here an unknown end - is reported
     * against the photo and leaves the date alone; the info text is restored.
     */
    public function testAnUnreadableDateIsReportedAndLeftAlone(): void
    {
        $this->writeTags(array('Info' => 'Taufe', 'DateEDTF' => '1965/'));
        $this->fixture->setDate($this->image['id'], '1950-05-06 00:00:00', 'day');

        $res = $this->rescan((string)$this->image['id']);

        $this->assertSame(0, $res['json']['result']['scanned'], $res['body']);
        $this->assertStringContainsString('1965/', $res['json']['result']['failed'][$this->image['id']] ?? '', $res['body']);
        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertSame('1950-05-06 00:00:00', $row['date_creation']);
        $this->assertSame('day', $row['photoinfo_date_precision']);
        $this->assertSame('Taufe', $row['comment']);
    }

    /** [NEG] An unknown photo in the chunk is reported, and the others are still read. */
    public function testAnUnknownPhotoIsReportedAndTheRestRead(): void
    {
        $this->writeTags(array('DateEDTF' => '1971'));
        $missing = (int)$this->db->scalar('SELECT MAX(id) FROM piwigo_images') + 1000;

        $res = $this->rescan($missing . ',' . $this->image['id']);

        $this->assertSame(array('scanned' => 1, 'failed' => array($missing => 'No such photo')), $res['json']['result'], $res['body']);
        $this->assertSame('1971-01-01 00:00:00', $this->fixture->imageRow($this->image['id'])['date_creation']);
    }

    /** [ECP] An administrator who is not the webmaster may rescan. */
    public function testAnAdministratorMayRescan(): void
    {
        $this->writeTags(array('DateEDTF' => '1971'));

        $res = $this->rescan((string)$this->image['id'], $this->clientAs(TestUsers::ADMIN));

        $this->assertSame(1, $res['json']['result']['scanned'] ?? null, $res['body']);
    }

    public static function refusedLists(): array
    {
        return array(
            // Core's ws layer reads an empty value as a missing parameter.
            'empty [NEG]' => array('', WS_ERR_MISSING_PARAM),
            'only commas [NEG]' => array(',,', WS_ERR_INVALID_PARAM),
            'not a number [NEG]' => array('12,abc', WS_ERR_INVALID_PARAM),
            'zero [BVA]' => array('0', WS_ERR_INVALID_PARAM),
            'one over the chunk [BVA]' => array(implode(',', range(1, PHOTOINFO_RESCAN_MAX_CHUNK + 1)), WS_ERR_INVALID_PARAM),
            );
    }

    /** [NEG] A list that is not 1 to 10 photo ids is refused whole, and nothing is read. */
    #[DataProvider('refusedLists')]
    public function testABadListIsRefused(string $ids, int $error): void
    {
        $res = $this->rescan($ids);

        $this->assertSame($error, $res['json']['err'] ?? null, $res['body']);
    }

    /** [BVA] A full chunk is accepted. */
    public function testAFullChunkIsAccepted(): void
    {
        $ids = array_fill(0, PHOTOINFO_RESCAN_MAX_CHUNK - 1, (int)$this->db->scalar('SELECT MAX(id) FROM piwigo_images') + 1000);
        foreach ($ids as $i => $id)
        {
            $ids[$i] = $id + $i;
        }
        $ids[] = $this->image['id'];

        $res = $this->rescan(implode(',', $ids));

        $this->assertSame(1, $res['json']['result']['scanned'] ?? null, $res['body']);
        $this->assertCount(PHOTOINFO_RESCAN_MAX_CHUNK - 1, $res['json']['result']['failed']);
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
        $this->writeTags(array('DateEDTF' => '1971'));
        $client = $this->clientAs($role);

        $res = $client->call(self::METHOD, array(
            'image_ids' => (string)$this->image['id'],
            'pwg_token' => $role === null ? 'none' : $client->token(),
        ));

        $this->assertSame(401, $res['json']['err'] ?? null, $res['body']);
        $this->assertNull($this->fixture->imageRow($this->image['id'])['date_creation']);
    }

    /** [NEG] A wrong token is refused. */
    public function testAWrongTokenIsRefused(): void
    {
        $this->writeTags(array('DateEDTF' => '1971'));

        $res = $this->ws->call(self::METHOD, array('image_ids' => (string)$this->image['id'], 'pwg_token' => 'wrong'));

        $this->assertSame(403, $res['json']['err'] ?? null, $res['body']);
        $this->assertNull($this->fixture->imageRow($this->image['id'])['date_creation']);
    }

    /** [NEG] A GET request is refused: the method changes the database. */
    public function testAGetRequestIsRefused(): void
    {
        $this->writeTags(array('DateEDTF' => '1971'));

        $res = $this->ws->callGet(self::METHOD, array('image_ids' => (string)$this->image['id'], 'pwg_token' => $this->ws->token()));

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

    private function rescan(string $ids, ?WsClient $client = null): array
    {
        $client = $client ?? $this->ws;

        return $client->call(self::METHOD, array('image_ids' => $ids, 'pwg_token' => $client->token()));
    }

    private function save(string $method, array $params): void
    {
        $res = $this->ws->call($method, array_merge(
            array('image_id' => $this->image['id'], 'pwg_token' => $this->ws->token()), $params));
        $this->assertTrue($res['json']['result']['written'] ?? false, $res['body']);
    }

    /** The row's comment and date columns, which a rescan rebuilds. */
    private function photoinfoColumns(): array
    {
        $row = $this->fixture->imageRow($this->image['id']);

        return array_intersect_key($row, array_flip(array('comment', 'date_creation', 'photoinfo_date_precision',
            'photoinfo_date_qualifier', 'photoinfo_date_end', 'photoinfo_date_end_precision')));
    }

    /** Clears the comment and every date column, as a fresh remote database has them, and asserts it. */
    private function clearRow(): void
    {
        $this->db->query('UPDATE piwigo_images SET comment = NULL, date_creation = NULL, photoinfo_date_precision = NULL,'
            . ' photoinfo_date_qualifier = NULL, photoinfo_date_end = NULL, photoinfo_date_end_precision = NULL'
            . ' WHERE id = ' . (int)$this->image['id']);
        $this->assertSame(array(null), array_unique(array_values($this->photoinfoColumns())), 'the row was not cleared');
    }

    /** Puts photoinfo's tags into the fixture file with a plain exiftool call, not through the plugin. */
    private function writeTags(array $tags): void
    {
        $command = 'exiftool -config ' . escapeshellarg(PHOTOINFO_PATH . 'exiftool/pwginfo.config') . ' -q -overwrite_original';
        foreach ($tags as $tag => $value)
        {
            $command .= ' ' . escapeshellarg('-XMP-pwginfo:' . $tag . '=' . $value);
        }
        FixtureBuilder::run($command . ' ' . escapeshellarg($this->image['file']));

        $read = $this->fileTags();
        $this->assertSame($tags['Info'] ?? null, $read['info'], 'the fixture tag was not written');
        $this->assertSame($tags['DateEDTF'] ?? null, $read['edtf'], 'the fixture tag was not written');
    }

    /** photoinfo's two tags as the file holds them, read through the raw XMP packet. */
    private function fileTags(): array
    {
        return array(
            'info' => FixtureBuilder::readFileTags($this->image['file'])['XMP-pwginfo:Info'],
            'edtf' => FixtureBuilder::readDateTags($this->image['file'])['XMP-pwginfo:DateEDTF'],
            );
    }
}
