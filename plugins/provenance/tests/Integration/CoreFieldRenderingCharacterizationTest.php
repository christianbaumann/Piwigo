<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for how core renders a photo's Titel, Autor and Info and an
 * album's name and description, laid down before galleryrules started escaping
 * them (decision 0073).
 *
 * Every case is [ERR]: the oracle is the current implementation. Two things are
 * recorded, both of which the escaping must leave as they are:
 *
 * - a value without markup or special characters reaches the page verbatim, on
 *   the picture page, the album page, the album list and the menu;
 * - ws.php returns the stored values as core shapes them (a name stripped of
 *   tags, a description raw), because JSON is data, not HTML.
 *
 * Runs as a normal account: the pages are the ones every visitor gets.
 */
final class CoreFieldRenderingCharacterizationTest extends TestCase
{
    /** A page shorter than this is an error page or the login form. */
    private const MIN_PAGE_BYTES = 2000;

    private const PLAIN = array(
        'name' => 'Kirmes in Sefferweich 1954',
        'author' => 'Josef Bäumer',
        'comment' => 'Aufgenommen vor dem Pfarrhaus',
        'album' => 'Familienfeste Sefferweich',
        'album_comment' => 'Fotos von Kirmes und Hochzeiten',
        );

    private const MARKUP_NAME = 'Kirmes <b>1954</b>';
    private const MARKUP_COMMENT = 'Vor dem <i>Pfarrhaus</i>';

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private int $albumId;
    private int $imageId;
    private string $suffix;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->suffix = bin2hex(random_bytes(4));

        $this->albumId = $this->fixture->createTestAlbum(self::PLAIN['album'] . ' ' . $this->suffix);
        $this->imageId = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($this->imageId, $this->albumId);
        // The album list skips an album with no representative photo.
        $this->db->query("UPDATE piwigo_categories SET representative_picture_id = {$this->imageId} WHERE id = {$this->albumId}");
        $this->fixture->invalidateUserCache();

        $this->ws = new WsClient();
        $this->ws->login(Config::normalUsername(), Config::normalPassword());
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->invalidateUserCache();
    }

    /** [ERR] Titel, Autor and Info without markup appear verbatim on the picture page. */
    public function testPlainPhotoFieldsAppearVerbatimOnThePicturePage(): void
    {
        $this->storePhoto(self::PLAIN['name'], self::PLAIN['author'], self::PLAIN['comment']);

        $body = $this->page('/picture.php?/' . $this->imageId . '/category/' . $this->albumId);

        foreach (array('name', 'author', 'comment') as $field)
        {
            $this->assertStringContainsString(self::PLAIN[$field], $body, "$field on the picture page");
        }
        $this->assertStringContainsString($this->albumName(), $body, 'the album name in the breadcrumb');
    }

    /**
     * [ERR] An album's name and description without markup appear verbatim on its
     * page; its name in the album list and the menu. modus' album list shows no
     * description.
     */
    public function testPlainAlbumFieldsAppearVerbatimOnTheAlbumPageTheAlbumListAndTheMenu(): void
    {
        $this->db->query("UPDATE piwigo_categories SET comment = '" . $this->db->escape(self::PLAIN['album_comment']) .
            "' WHERE id = {$this->albumId}");
        $this->storePhoto(self::PLAIN['name'], '', '');

        $albumPage = $this->page('/index.php?/category/' . $this->albumId);
        $this->assertStringContainsString($this->albumName(), $albumPage, 'the album name on its page');
        $this->assertStringContainsString(self::PLAIN['album_comment'], $albumPage, 'the description on the album page');
        $this->assertStringContainsString(self::PLAIN['name'], $albumPage, 'the photo title under its thumbnail');

        $list = $this->page('/index.php');
        $this->assertStringContainsString('<h4>' . $this->albumName() . '</h4>', $list, 'the album in the album list');
        $this->assertMatchesRegularExpression(
            '~<dl id=mbCategories>.*title="[^"]*">' . preg_quote($this->albumName(), '~') . '</a>~s',
            $list,
            'the album in the menu'
            );
    }

    /** [ERR] pwg.images.getInfo returns the name stripped of tags and the description raw. */
    public function testTheApiReturnsAPhotosFieldsAsCoreShapesThem(): void
    {
        $this->storePhoto(self::MARKUP_NAME, self::PLAIN['author'], self::MARKUP_COMMENT);

        $res = $this->ws->call('pwg.images.getInfo', array('image_id' => $this->imageId));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $info = $res['json']['result'];

        $this->assertSame('Kirmes 1954', $info['name']);
        $this->assertSame(self::PLAIN['author'], $info['author']);
        $this->assertSame(self::MARKUP_COMMENT, $info['comment']);
    }

    /** [ERR] pwg.categories.getList returns the name stripped of tags and the description raw. */
    public function testTheApiReturnsAnAlbumsFieldsAsCoreShapesThem(): void
    {
        $this->db->query("UPDATE piwigo_categories SET name = '" . $this->db->escape(self::MARKUP_NAME) .
            "', comment = '" . $this->db->escape(self::MARKUP_COMMENT) . "' WHERE id = {$this->albumId}");

        $res = $this->ws->call('pwg.categories.getList', array('cat_id' => $this->albumId));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $albums = array_values(array_filter(
            $res['json']['result']['categories'],
            fn ($album) => (int)$album['id'] === $this->albumId
            ));
        $this->assertCount(1, $albums);

        $this->assertSame('Kirmes 1954', $albums[0]['name']);
        $this->assertSame(self::MARKUP_COMMENT, $albums[0]['comment']);
    }

    private function storePhoto(string $name, string $author, string $comment): void
    {
        $this->db->query("UPDATE piwigo_images SET name = '" . $this->db->escape($name) .
            "', author = '" . $this->db->escape($author) .
            "', comment = '" . $this->db->escape($comment) . "' WHERE id = {$this->imageId}");
    }

    private function albumName(): string
    {
        return self::PLAIN['album'] . ' ' . $this->suffix;
    }

    private function page(string $path): string
    {
        $response = $this->ws->fetchPage($path);
        $this->assertSame(200, $response['http_code'], $path);
        $body = (string)$response['body'];
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), $path);
        $this->assertStringNotContainsString('name="username"', $body, "$path served the login form");

        return $body;
    }
}
