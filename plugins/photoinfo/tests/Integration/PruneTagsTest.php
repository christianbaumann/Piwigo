<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * pwg.photoinfo.pruneTags across ws.php, MariaDB and an image file: it removes
 * the tags a photo has that its marked file does not name, the step the
 * deploy takes only with --prune-tags.
 *
 * Requirement: plan 2026-10-09 "Tags in the image file", Phase 6 and Desired
 * End State 3 (Q8, Q13a).
 */
final class PruneTagsTest extends TestCase
{
    private const METHOD = 'pwg.photoinfo.pruneTags';
    /** The config row provenance's runner takes the exiftool directory from. */
    private const EXIFTOOL_PATH_PARAM = 'provenance_exiftool_path';

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private WsClient $ws;
    private string $suffix;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');

        $this->image = $this->fixture->createTestImage();
        FixtureBuilder::stripRegionsAndKeywords($this->image['file']);
        $this->suffix = bin2hex(random_bytes(4));
        $this->ws = $this->clientAs(TestUsers::WEBMASTER);
    }

    protected function tearDown(): void
    {
        $this->db->query("DELETE FROM piwigo_config WHERE param = '" . self::EXIFTOOL_PATH_PARAM . "'");
        $this->fixture->destroyTestTags();
        $this->fixture->destroyTestImages();
    }

    /** [HAPPY] Only the tag the marked file does not name is removed; the file is not written. */
    public function testItRemovesOnlyWhatTheMarkedFileDoesNotName(): void
    {
        $kept = $this->linkedTag('Anna');
        $gone = $this->linkedTag('Zug');
        FixtureBuilder::writeKeywords($this->image['file'], array($this->tagName('Anna')), array(), true);
        clearstatcache();
        $checksum = md5_file($this->image['file']);

        $res = $this->prune((string)$this->image['id']);

        $this->assertSame(array('removed' => array((string)$this->image['id'] => array($this->tagName('Zug'))), 'failed' => array()),
            $res['json']['result'] ?? null, $res['body']);
        $this->assertSame(array($kept), $this->fixture->tagIdsOf($this->image['id']));
        $this->assertNotContains($gone, $this->fixture->tagIdsOf($this->image['id']));
        clearstatcache();
        $this->assertSame($checksum, md5_file($this->image['file']), 'the prune rewrote the file');
    }

    /** [ECP] A tag the file names in other case is the same tag, and stays. */
    public function testANameDifferingInCaseOnlyIsKept(): void
    {
        $tag = $this->linkedTag('Kirche');
        FixtureBuilder::writeKeywords($this->image['file'], array(strtolower($this->tagName('Kirche'))), array(), true);

        $res = $this->prune((string)$this->image['id']);

        $this->assertSame(array('removed' => array(), 'failed' => array()), $res['json']['result'] ?? null, $res['body']);
        $this->assertSame(array($tag), $this->fixture->tagIdsOf($this->image['id']));
    }

    /** [NEG] Without exiftool every photo is reported and nothing is removed. */
    public function testWithoutExiftoolEveryPhotoIsReported(): void
    {
        $tag = $this->linkedTag('Zug');
        FixtureBuilder::writeKeywords($this->image['file'], array(), array(), true);
        $this->db->query("INSERT INTO piwigo_config (param, value) VALUES ('" . self::EXIFTOOL_PATH_PARAM . "', '/nonexistent/')");

        $res = $this->prune((string)$this->image['id']);

        $this->assertSame(array('removed' => array(), 'failed' => array((string)$this->image['id'] => 'exiftool is not available on this server')),
            $res['json']['result'] ?? null, $res['body']);
        $this->assertSame(array($tag), $this->fixture->tagIdsOf($this->image['id']));
    }

    /** [NEG] A file without the marker was never written with tags: nothing is removed. */
    public function testAnUnmarkedFileIsLeftAlone(): void
    {
        $tag = $this->linkedTag('Zug');

        $res = $this->prune((string)$this->image['id']);

        $this->assertSame(array('removed' => array(), 'failed' => array()), $res['json']['result'] ?? null, $res['body']);
        $this->assertSame(array($tag), $this->fixture->tagIdsOf($this->image['id']));
    }

    /** [NEG] A local-only tag is never in a file, so it is never pruned. */
    public function testALocalOnlyTagIsNeverRemoved(): void
    {
        $tag = $this->linkedTag('Name ?');
        FixtureBuilder::writeKeywords($this->image['file'], array(), array(), true);

        $res = $this->prune((string)$this->image['id']);

        $this->assertSame(array('removed' => array(), 'failed' => array()), $res['json']['result'] ?? null, $res['body']);
        $this->assertSame(array($tag), $this->fixture->tagIdsOf($this->image['id']));
    }

    /** [BVA] A full chunk is accepted; unknown photos are reported, the known one pruned. */
    public function testAFullChunkIsAccepted(): void
    {
        $this->linkedTag('Zug');
        FixtureBuilder::writeKeywords($this->image['file'], array(), array(), true);
        $first = (int)$this->db->scalar('SELECT MAX(id) FROM piwigo_images') + 1000;
        $ids = range($first, $first + PHOTOINFO_RESCAN_MAX_CHUNK - 2);
        $ids[] = $this->image['id'];

        $res = $this->prune(implode(',', $ids));

        $this->assertCount(PHOTOINFO_RESCAN_MAX_CHUNK - 1, $res['json']['result']['failed'] ?? array(), $res['body']);
        $this->assertSame(array(), $this->fixture->tagIdsOf($this->image['id']));
    }

    /** [BVA] One over the chunk is refused whole. */
    public function testOneOverTheChunkIsRefused(): void
    {
        $res = $this->prune(implode(',', range(1, PHOTOINFO_RESCAN_MAX_CHUNK + 1)));

        $this->assertSame(WS_ERR_INVALID_PARAM, $res['json']['err'] ?? null, $res['body']);
    }

    /** [ECP] An administrator who is not the webmaster may prune, as they may rescan. */
    public function testAnAdministratorMayPrune(): void
    {
        $this->linkedTag('Zug');
        FixtureBuilder::writeKeywords($this->image['file'], array(), array(), true);

        $res = $this->prune((string)$this->image['id'], $this->clientAs(TestUsers::ADMIN));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(array(), $this->fixture->tagIdsOf($this->image['id']));
    }

    public static function refusedAccounts(): array
    {
        return array(
            'normal user' => array(TestUsers::NORMAL),
            'guest' => array(null),
            );
    }

    /** [NEG] A normal user and a guest are refused, and nothing is removed. */
    #[DataProvider('refusedAccounts')]
    public function testOthersAreRefused(?string $role): void
    {
        $tag = $this->linkedTag('Zug');
        FixtureBuilder::writeKeywords($this->image['file'], array(), array(), true);
        $client = $this->clientAs($role);

        $res = $client->call(self::METHOD, array(
            'image_ids' => (string)$this->image['id'],
            'pwg_token' => $role === null ? 'none' : $client->token(),
        ));

        $this->assertSame(401, $res['json']['err'] ?? null, $res['body']);
        $this->assertSame(array($tag), $this->fixture->tagIdsOf($this->image['id']));
    }

    /** [NEG] A wrong token is refused. */
    public function testAWrongTokenIsRefused(): void
    {
        $tag = $this->linkedTag('Zug');
        FixtureBuilder::writeKeywords($this->image['file'], array(), array(), true);

        $res = $this->ws->call(self::METHOD, array('image_ids' => (string)$this->image['id'], 'pwg_token' => 'wrong'));

        $this->assertSame(403, $res['json']['err'] ?? null, $res['body']);
        $this->assertSame(array($tag), $this->fixture->tagIdsOf($this->image['id']));
    }

    /** [NEG] A GET request is refused: the method changes the database. */
    public function testAGetRequestIsRefused(): void
    {
        $tag = $this->linkedTag('Zug');
        FixtureBuilder::writeKeywords($this->image['file'], array(), array(), true);

        $res = $this->ws->callGet(self::METHOD, array('image_ids' => (string)$this->image['id'], 'pwg_token' => $this->ws->token()));

        $this->assertSame(405, $res['json']['err'] ?? null, $res['body']);
        $this->assertSame(array($tag), $this->fixture->tagIdsOf($this->image['id']));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function tagName(string $base): string
    {
        return $base . ' ' . $this->suffix;
    }

    private function linkedTag(string $base): int
    {
        $id = $this->fixture->createTag($this->tagName($base));
        $this->fixture->linkTag($this->image['id'], $id);
        return $id;
    }

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

    private function prune(string $ids, ?WsClient $client = null): array
    {
        $client = $client ?? $this->ws;

        return $client->call(self::METHOD, array('image_ids' => $ids, 'pwg_token' => $client->token()));
    }
}
