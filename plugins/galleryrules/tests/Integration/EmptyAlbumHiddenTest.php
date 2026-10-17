<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Empty albums are hidden from visitors (design: "Empty albums are hidden from
 * visitors"). An administrator who opens an empty album through "In Galerie
 * öffnen" sees it with its description, so the page tells them visitors do not.
 *
 * Hiding is core's own (feature 1053 in get_computed_categories(): a non-admin's
 * cache forbids every album without a photo). Those cases are [ERR]: their
 * oracle is core as it is today, and they report a change; no requirement in
 * core confirms it, the design adopts it.
 *
 * Fixture, all this suite's own: a public parent album holding a full album
 * (one generated PNG) and an empty one, each with a description of its own.
 */
final class EmptyAlbumHiddenTest extends TestCase
{
    private const MIN_PAGE_BYTES = 2000;
    private const ACCESS_DENIED_STATUS = 401;

    private Db $db;
    private FixtureBuilder $fixture;
    private int $parentId;
    private int $fullId;
    private int $emptyId;
    private string $emptyName;
    private string $fullName;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive();

        $suffix = bin2hex(random_bytes(4));
        $this->parentId = $this->fixture->createTestAlbum('galleryrules-empty-parent-' . $suffix);
        $this->fullName = 'galleryrules-full-' . $suffix;
        $this->emptyName = 'galleryrules-empty-' . $suffix;
        $this->fullId = $this->fixture->createTestAlbum($this->fullName, $this->parentId);
        $this->emptyId = $this->fixture->createTestAlbum($this->emptyName, $this->parentId);
        $this->fixture->storeAlbumFields($this->fullId, $this->fullName, $this->description($this->fullId));
        $this->fixture->storeAlbumFields($this->emptyId, $this->emptyName, $this->description($this->emptyId));
        $this->fixture->attachImage($this->fixture->createTestImage(), $this->fullId);
        $this->fixture->invalidateUserCache();

        $this->assertSame(0, (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_image_category WHERE category_id IN (' . $this->parentId . ',' . $this->emptyId . ')'
        ), 'the parent and the empty album must hold no photo of their own');
        $this->assertSame(1, (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_image_category WHERE category_id = ' . $this->fullId
        ), 'the full album must hold one photo');
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    public static function visitors(): array
    {
        return array(
            'normal' => array(TestUsers::NORMAL),
            'generic' => array(TestUsers::GENERIC),
            );
    }

    public static function administrators(): array
    {
        return array(
            'admin' => array(TestUsers::ADMIN),
            'webmaster' => array(TestUsers::WEBMASTER),
            );
    }

    /** [ERR][NEG] The parent's page lists the full album and not the empty one. */
    #[DataProvider('visitors')]
    public function testTheEmptyAlbumIsNotListed(string $role): void
    {
        $body = $this->page($this->clientAs($role), $this->parentId);

        $this->assertStringContainsString($this->fullName, $body, 'the full album is not listed: the page proves nothing');
        $this->assertStringNotContainsString($this->emptyName, $body);
    }

    /** [ERR][NEG] pwg.categories.getList returns the full album and not the empty one. */
    #[DataProvider('visitors')]
    public function testTheApiDoesNotListTheEmptyAlbum(string $role): void
    {
        $res = $this->clientAs($role)->call('pwg.categories.getList', array('cat_id' => $this->parentId, 'recursive' => 'true'));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $ids = array_map('intval', array_column($res['json']['result']['categories'], 'id'));
        $this->assertContains($this->fullId, $ids, 'the full album is not returned: the list proves nothing');
        $this->assertNotContains($this->emptyId, $ids);
    }

    /** [ERR][NEG] The empty album's URL is refused and shows nothing of the album. */
    #[DataProvider('visitors')]
    public function testTheEmptyAlbumsUrlIsRefused(string $role): void
    {
        $response = $this->clientAs($role)->get('/index.php?/category/' . $this->emptyId);

        $this->assertSame(self::ACCESS_DENIED_STATUS, $response['status']);
        $this->assertStringNotContainsString($this->description($this->emptyId), $response['body']);
        $this->assertStringNotContainsString($this->emptyName, $response['body']);
    }

    /** [ERR][HAPPY] An album with a photo opens with its description, without the note. */
    #[DataProvider('visitors')]
    public function testTheFullAlbumOpens(string $role): void
    {
        $body = $this->page($this->clientAs($role), $this->fullId);

        $this->assertStringContainsString($this->description($this->fullId), $body);
        $this->assertStringNotContainsString(self::note(), $body);
    }

    /** [HAPPY] An administrator opening the empty album sees it and the note. */
    #[DataProvider('administrators')]
    public function testAnAdministratorSeesTheNoteOnTheEmptyAlbum(string $role): void
    {
        $body = $this->page($this->clientAs($role), $this->emptyId);

        $this->assertStringContainsString($this->description($this->emptyId), $body);
        $this->assertSame(1, substr_count($body, self::note()));
    }

    /** [NEG][BVA] No note on an album with one photo, nor on one whose only photo is in a sub-album. */
    #[DataProvider('administrators')]
    public function testAnAdministratorSeesNoNoteOnAnAlbumWithPhotos(string $role): void
    {
        $client = $this->clientAs($role);

        foreach (array($this->fullId, $this->parentId) as $albumId)
        {
            $body = $this->page($client, $albumId);
            $this->assertStringNotContainsString(self::note(), $body, "album $albumId");
        }
    }

    private function description(int $albumId): string
    {
        return 'galleryrules-empty-album-description-' . $albumId;
    }

    /** The note's German text, as the plugin's language file gives it. */
    private static function note(): string
    {
        include_once GALLERYRULES_PATH . 'include/events_empty_album.inc.php';
        $lang = array();
        include GALLERYRULES_PATH . 'language/de_DE/plugin.lang.php';
        if (empty($lang[GALLERYRULES_EMPTY_ALBUM_NOTE]))
        {
            throw new RuntimeException('no German text for the empty-album note');
        }
        return htmlspecialchars($lang[GALLERYRULES_EMPTY_ALBUM_NOTE], ENT_QUOTES, 'UTF-8');
    }

    private function clientAs(string $role): WsClient
    {
        $client = new WsClient();
        list($username, $password) = Config::credentials($role);
        $client->login($username, $password);

        return $client;
    }

    private function page(WsClient $client, int $albumId): string
    {
        $path = '/index.php?/category/' . $albumId;
        $response = $client->get($path);
        $body = $response['body'];

        $this->assertSame(200, $response['status'], $path);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), $path);
        $this->assertStringNotContainsString('name="username"', $body, $path . ' served the login form');

        return $body;
    }
}
