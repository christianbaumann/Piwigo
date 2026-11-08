<?php
use PHPUnit\Framework\TestCase;

/**
 * The change-log entry of a turn or crop. Requirement: task 05 of change
 * 2026-10-20-picture-page-edit-ux - one `photo` entry per written edit,
 * naming the account, no old value; a dry run, a refusal and a failed edit
 * write none. The photo is a copy (FixtureBuilder), never a real scan.
 */
final class HistoryPhotoEditTest extends TestCase
{
    private const READ_ONLY = 0555;
    private const WRITABLE = 0755;
    private const ORIGINALS_DIR = PIWIGO_ROOT . '_data/photoedit/originals';

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
        $this->fixture->assertPluginActive('photoedit');

        $this->albumId = $this->fixture->createTestAlbum('photoinfo-photoedit-history-' . bin2hex(random_bytes(4)));
        $this->image = $this->fixture->createTestImage();
        $this->fixture->attachImage($this->image['id'], $this->albumId);

        $this->ws = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->ws->login($username, $password);
    }

    protected function tearDown(): void
    {
        if (is_dir(self::ORIGINALS_DIR))
        {
            chmod(self::ORIGINALS_DIR, self::WRITABLE);
        }
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [HAPPY] A turn writes one photo entry naming the account, without an old value. */
    public function testATurnWritesOneEntryNamingTheAccount(): void
    {
        $this->assertSame('ok', $this->apply(array('turns' => 1))['json']['stat']);

        $rows = $this->entries();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['old_value']);
        $this->assertSame(array('turns' => 1, 'crop' => null), photoinfo_history_photo_parse($rows[0]['new_value']));
        $this->assertSame('picture_page', $rows[0]['source']);
        list($username) = Config::credentials(TestUsers::WEBMASTER);
        $this->assertSame((int)$this->db->scalar("SELECT id FROM piwigo_users WHERE username = '" . $this->db->escape($username) . "'"),
            (int)$rows[0]['performed_by']);
    }

    /** [HAPPY] A crop is one entry that carries the size of the result. */
    public function testACropWritesOneEntryWithItsSize(): void
    {
        $this->assertSame('ok', $this->apply(array('crop' => '0,0,0.5,1'))['json']['stat']);

        $rows = $this->entries();
        $this->assertCount(1, $rows);
        $value = photoinfo_history_photo_parse($rows[0]['new_value']);
        $this->assertSame(0, $value['turns']);
        $this->assertGreaterThan(0, $value['crop']['w']);
        $this->assertGreaterThan(0, $value['crop']['h']);
    }

    /** [NEG] A dry run writes nothing. */
    public function testADryRunWritesNothing(): void
    {
        $this->assertSame('ok', $this->apply(array('turns' => 1, 'dry_run' => 'true'))['json']['stat']);

        $this->assertSame(array(), $this->entries());
    }

    /** [NEG] A refused request (bad token, then a crop with nothing in it) writes nothing. */
    public function testARefusedEditWritesNothing(): void
    {
        $bad = $this->apply(array('turns' => 1, 'pwg_token' => 'wrong'));
        $this->assertSame('fail', $bad['json']['stat'], $bad['body']);
        $empty = $this->apply(array('crop' => '0.5,0.5,0.5,0.5'));
        $this->assertSame('fail', $empty['json']['stat'], $empty['body']);

        $this->assertSame(array(), $this->entries());
    }

    /** [NEG] An edit whose write fails (backup directory read-only) answers failure and writes nothing. */
    public function testAFailedEditWritesNothing(): void
    {
        if (!is_dir(self::ORIGINALS_DIR))
        {
            mkdir(self::ORIGINALS_DIR, self::WRITABLE, true);
        }
        chmod(self::ORIGINALS_DIR, self::READ_ONLY);
        clearstatcache();
        $this->assertFalse(is_writable(self::ORIGINALS_DIR),
            'anti-vacuity: the directory is still writable (running as root?)');

        $res = $this->apply(array('turns' => 1));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(array(), $this->entries());
    }

    private function apply(array $params): array
    {
        $params += array('image_id' => $this->image['id'], 'pwg_token' => $this->ws->token());
        return $this->ws->call('pwg.photoedit.apply', $params);
    }

    private function entries(): array
    {
        $result = $this->db->query("SELECT field, old_value, new_value, source, performed_by FROM piwigo_provenance_history"
            . " WHERE object = 'photo' AND object_id = " . $this->image['id'] . " AND field = 'photo' ORDER BY id");
        $rows = array();
        while ($row = $result->fetch_assoc())
        {
            $rows[] = $row;
        }
        return $rows;
    }
}
