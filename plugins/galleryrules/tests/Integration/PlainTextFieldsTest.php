<?php
use PHPUnit\Framework\TestCase;

/**
 * Titel, Autor, Info, an album's name and its description are plain text
 * (decision 0073): markup stored in any of them reaches every gallery page as
 * text, escaped once, never as markup.
 *
 * Fixture, all this suite's own:
 *
 *   parent album P (plain name)
 *   └ album C       <- name and description carry a payload
 *     └ photo       <- Titel, Autor and Info carry a payload
 *
 * Each payload is an <img onerror> naming its field, so a page that let one
 * through says which. Runs as normal and generic: the accounts every visitor
 * page is served to. The page-source oracle is enough here, since core renders
 * all five fields server-side; the browser half is plain-text.spec.js.
 */
final class PlainTextFieldsTest extends TestCase
{
    /** A page shorter than this is an error page or the login form. */
    private const MIN_PAGE_BYTES = 2000;

    private Db $db;
    private FixtureBuilder $fixture;
    private int $parentId;
    private int $albumId;
    private int $imageId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive();

        $suffix = bin2hex(random_bytes(4));
        $this->parentId = $this->fixture->createTestAlbum('galleryrules-parent-' . $suffix);
        $this->albumId = $this->fixture->createTestAlbum('galleryrules-album-' . $suffix, $this->parentId);
        $this->imageId = $this->fixture->createTestImage();
        $this->fixture->attachImage($this->imageId, $this->albumId);
        $this->fixture->attachImage($this->imageId, $this->parentId);

        $this->fixture->storePhotoFields($this->imageId, self::payload('name'), self::payload('author'), self::payload('comment'));
        $this->fixture->storeAlbumFields($this->albumId, self::payload('album-name'), self::payload('album-description'));
        $this->fixture->invalidateUserCache();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /**
     * Every page each field is shown on, for both visitor roles.
     *
     * @return array<string, array{0: string, 1: string, 2: string}> role, field, page
     */
    public static function fieldsOnPages(): array
    {
        $places = array(
            // [HAPPY] the five fields where the findings saw them run
            array('name', 'picture'),
            array('author', 'picture'),
            array('comment', 'picture'),
            array('album-name', 'album'),
            array('album-description', 'album'),
            // [ECP] the other pages showing the same field: thumbnail, breadcrumb, menu, album list
            array('name', 'album'),
            array('comment', 'album'),
            array('album-name', 'picture'),
            array('album-name', 'album-list'),
            // [ECP] provenance's read-only pages take the name from the same events
            array('name', 'provenance-photo'),
            array('album-name', 'provenance-album'),
            );

        $cases = array();
        foreach (array(TestUsers::NORMAL, TestUsers::GENERIC) as $role)
        {
            foreach ($places as list($field, $page))
            {
                $cases["$field on $page as " . TestUsers::ROLES[$role]] = array($role, $field, $page);
            }
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fieldsOnPages')]
    public function testAStoredPayloadComesBackAsText(string $role, string $field, string $page): void
    {
        $body = $this->page($this->clientAs($role), $page);
        $payload = self::payload($field);

        $this->assertStringNotContainsString($payload, $body, "$field ran as markup on $page");
        $this->assertStringNotContainsString(self::html(self::html($payload)), $body, "$field was escaped twice on $page");
        if ($field === 'comment' and $page === 'album')
        {
            // modus shows no description under a thumbnail; core's tooltip strips the
            // tags out of it. What is left must not be markup.
            $this->assertStringNotContainsString('<img src=x alt=galleryrules-comment', $body);
            return;
        }
        $this->assertStringContainsString(self::html($payload), $body, "$field is not shown as text on $page");
    }

    /** [HAPPY] A three-line album description shows three lines. */
    public function testAThreeLineDescriptionShowsThreeLines(): void
    {
        $this->fixture->storeAlbumFields($this->albumId, 'galleryrules-lines', "eins\nzwei\ndrei");

        $body = $this->page($this->clientAs(TestUsers::NORMAL), 'album');

        $this->assertStringContainsString("eins<br />\nzwei<br />\ndrei", $body);
    }

    /**
     * [BVA] Quotes and an ampersand in a Titel are escaped exactly once in the
     * thumbnail's alt text and tooltip, which core escapes a second time itself.
     */
    public function testATitlesQuotesAreEscapedOnceInTheThumbnail(): void
    {
        $title = 'Oma\'s "Kirmes" & mehr';
        $this->fixture->storePhotoFields($this->imageId, $title, '', '');

        $client = $this->clientAs(TestUsers::NORMAL);

        // modus' own grid, the default here, renders the alt text only.
        $grid = $this->page($client, 'album');
        $this->assertStringContainsString('alt="' . self::html($title) . '"', $grid);
        $this->assertStringNotContainsString(self::html(self::html($title)), $grid);

        // Square thumbnails take core's template, which renders the tooltip too.
        $squares = $this->page($client, 'album-square');
        $this->assertStringContainsString('alt="' . self::html($title) . '"', $squares);
        $this->assertStringContainsString('title="' . self::html($title), $squares);
        $this->assertStringNotContainsString(self::html(self::html($title)), $squares);
    }

    private static function payload(string $field): string
    {
        return '<img src=x alt=galleryrules-' . $field . ' onerror=document.body.dataset.galleryrulesXss=this.alt>';
    }

    /** What a browser decodes back to the text: the oracle for "shown as text". */
    private static function html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    private function clientAs(string $role): WsClient
    {
        $client = new WsClient();
        list($username, $password) = Config::credentials($role);
        $client->login($username, $password);

        return $client;
    }

    private function page(WsClient $client, string $page): string
    {
        $paths = array(
            'picture' => '/picture.php?/' . $this->imageId . '/category/' . $this->albumId,
            'album' => '/index.php?/category/' . $this->albumId,
            'album-list' => '/index.php?/category/' . $this->parentId,
            'provenance-photo' => '/index.php?/provenance/photo/' . $this->imageId,
            'provenance-album' => '/index.php?/provenance/album/' . $this->albumId,
            'album-square' => '/index.php?/category/' . $this->albumId . '&display=square',
            );
        $response = $client->get($paths[$page]);
        $body = $response['body'];

        $this->assertSame(200, $response['status'], $paths[$page]);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), $paths[$page]);
        $this->assertStringNotContainsString('name="username"', $body, $paths[$page] . ' served the login form');

        return $body;
    }
}
