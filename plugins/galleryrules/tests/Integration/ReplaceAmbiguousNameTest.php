<?php
use PHPUnit\Framework\TestCase;

/**
 * The replace option of Fotos hinzufügen (pwg.images.upload with update_mode)
 * never overwrites a photo it had to guess: with more than one photo of the
 * file name in the album it refuses, names them and changes no file (design:
 * "Replace refuses when a file name is ambiguous"). Core took the lower id.
 *
 * Fixture, all this suite's own: one album, generated PNGs whose rows are
 * given one shared file name. Runs as the webmaster: the method is admin-only,
 * and the gate is core's, not this rule's.
 */
final class ReplaceAmbiguousNameTest extends TestCase
{
    /** The file name the photos share and the upload carries. */
    private const SHARED_NAME = 'galleryrules-same-name.png';

    private const PHOTOEDIT_ORIGINALS = PIWIGO_ROOT . '_data/photoedit/originals/';

    private Db $db;
    private FixtureBuilder $fixture;
    private int $albumId;
    private ?string $replacement = null;
    private array $imageIds = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive();
        $this->albumId = $this->fixture->createTestAlbum('galleryrules-replace-' . bin2hex(random_bytes(4)));

        $this->replacement = sys_get_temp_dir() . '/' . self::SHARED_NAME;
        $output = array();
        $status = 1;
        exec('convert -size 120x80 xc:#00ff00 ' . escapeshellarg('PNG:' . $this->replacement) . ' 2>&1', $output, $status);
        if ($status !== 0 or !is_file($this->replacement))
        {
            throw new RuntimeException('convert did not produce the replacement: ' . implode("\n", $output));
        }
    }

    protected function tearDown(): void
    {
        if ($this->replacement !== null)
        {
            @unlink($this->replacement);
        }
        // photoedit, when active, keeps a copy of a replaced photo under its id.
        foreach ($this->imageIds as $id)
        {
            foreach (glob(self::PHOTOEDIT_ORIGINALS . $id . '-*') ?: array() as $copy)
            {
                @unlink($copy);
            }
        }
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [NEG][BVA] Two photos share the name: refused, both named, neither file nor row changed. */
    public function testTwoPhotosWithTheNameAreNotReplaced(): void
    {
        $first = $this->photoNamedShared();
        $second = $this->photoNamedShared();
        $before = array($first => $this->state($first), $second => $this->state($second));
        $count = $this->photosInAlbum();

        $res = $this->replace();

        $this->assertSame('fail', $res['json']['stat'] ?? null, 'the replace was not refused: ' . $res['body']);
        $this->assertSame(sprintf(self::germanMessage(), "#$first, #$second", self::SHARED_NAME), $res['json']['message']);
        clearstatcache();
        $this->assertSame($before[$first], $this->state($first), "photo $first changed");
        $this->assertSame($before[$second], $this->state($second), "photo $second changed");
        $this->assertSame($count, $this->photosInAlbum(), 'a photo was added instead');
    }

    /** [HAPPY][BVA] One photo has the name: core still replaces it. */
    public function testOnePhotoWithTheNameIsReplaced(): void
    {
        $only = $this->photoNamedShared();
        $this->assertNotSame(md5_file($this->replacement), $this->state($only)['md5'], 'anti-vacuity: the replacement is the same file');

        $res = $this->replace();

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame($only, (int)$res['json']['result']['image_id']);
        $this->assertSame('update', $res['json']['result']['add_status']);
        clearstatcache();
        $this->assertSame(md5_file($this->replacement), $this->state($only)['md5'], 'the photo was not replaced');
    }

    /** [ECP] A photo of the name in another album does not make the replace ambiguous. */
    public function testThePhotoInAnotherAlbumDoesNotCount(): void
    {
        $only = $this->photoNamedShared();
        $elsewhere = $this->photoNamedShared($this->fixture->createTestAlbum('galleryrules-replace-other-' . bin2hex(random_bytes(4))));
        $untouched = $this->state($elsewhere);

        $res = $this->replace();

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame($only, (int)$res['json']['result']['image_id']);
        clearstatcache();
        $this->assertSame($untouched, $this->state($elsewhere));
    }

    /** The refusal as the plugin's German strings give it; the test accounts run in German. */
    private static function germanMessage(): string
    {
        $lang = array();
        include GALLERYRULES_PATH . 'language/de_DE/plugin.lang.php';
        $german = array_values(array_filter(
            $lang,
            fn ($key) => str_starts_with($key, 'Nothing was replaced:'),
            ARRAY_FILTER_USE_KEY
        ));
        if (count($german) !== 1)
        {
            throw new RuntimeException('expected one German refusal string, found ' . count($german));
        }
        return $german[0];
    }

    /** A fixture photo in the album (or another), its row's file name set to SHARED_NAME. */
    private function photoNamedShared(?int $albumId = null): int
    {
        $id = $this->fixture->createTestImage();
        $this->imageIds[] = $id;
        $this->fixture->attachImage($id, $albumId ?? $this->albumId);
        $this->db->query("UPDATE piwigo_images SET file = '" . $this->db->escape(self::SHARED_NAME) . "' WHERE id = $id");
        $this->assertSame(self::SHARED_NAME, $this->db->scalar("SELECT file FROM piwigo_images WHERE id = $id"));
        return $id;
    }

    /** @return array{md5: string, path: string, file: string} the photo's bytes and row */
    private function state(int $id): array
    {
        $row = $this->db->query("SELECT path, file FROM piwigo_images WHERE id = $id")->fetch_assoc();
        $this->assertNotNull($row, "no row for photo $id");
        $md5 = md5_file(PIWIGO_ROOT . substr($row['path'], 2));
        $this->assertIsString($md5, "no file for photo $id");
        return array('md5' => $md5, 'path' => $row['path'], 'file' => $row['file']);
    }

    private function photosInAlbum(): int
    {
        return (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_image_category WHERE category_id = ' . $this->albumId);
    }

    /** Fotos hinzufügen's request with the replace option on, as one chunk. */
    private function replace(): array
    {
        $client = new WsClient();
        list($user, $password) = Config::credentials(TestUsers::WEBMASTER);
        $client->login($user, $password);

        return $client->upload('pwg.images.upload', array(
            'name' => self::SHARED_NAME,
            'category' => array($this->albumId),
            'level' => 0,
            'update_mode' => 'true',
            'chunk' => 0,
            'chunks' => 1,
            'pwg_token' => $client->token(),
            ), 'file', $this->replacement);
    }
}
