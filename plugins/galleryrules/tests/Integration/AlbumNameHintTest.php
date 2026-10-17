<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A blank album name shows a hint (design: "A blank album name shows a hint").
 * Alben > Verwaltung's create dialog carries "Name fehlt" next to the name
 * field, hidden until a blank name is saved; the browser half is
 * tests/e2e/album-name-hint.spec.js.
 *
 * The refusal itself is core's: create_virtual_category() refuses a name of
 * blank spaces only, so no album is created. Those cases are [ERR], their oracle
 * is core as it is today.
 */
final class AlbumNameHintTest extends TestCase
{
    private const MIN_PAGE_BYTES = 2000;
    private const HINT_CLASS = 'galleryrules-album-name-missing';

    private Db $db;
    private int $maxAlbumId;

    protected function setUp(): void
    {
        $this->db = new Db();
        (new FixtureBuilder($this->db))->assertPluginActive();
        $this->maxAlbumId = (int)$this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM piwigo_categories');
    }

    protected function tearDown(): void
    {
        // Only reached when core created an album the case expected refused.
        $this->db->query("DELETE FROM piwigo_categories WHERE id > {$this->maxAlbumId} AND TRIM(name) = ''");
    }

    public static function administrators(): array
    {
        return array(
            'admin' => array(TestUsers::ADMIN),
            'webmaster' => array(TestUsers::WEBMASTER),
            );
    }

    public static function blankNames(): array
    {
        return array(
            'empty' => array(''),
            'spaces only' => array('   '),
            );
    }

    /** [HAPPY] The create dialog holds the hint once, hidden, after the name field and before the rename dialog. */
    #[DataProvider('administrators')]
    public function testTheCreateDialogHoldsTheHiddenHint(string $role): void
    {
        $body = $this->albumsPage($this->clientAs($role));

        $hint = '<span class="' . self::HINT_CLASS . '" hidden>' . self::hintText() . '</span>';
        $this->assertSame(1, substr_count($body, $hint), 'the hint, hidden, in German');
        $at = strpos($body, $hint);
        $this->assertGreaterThan(strpos($body, 'id="AddAlbum"'), $at);
        $this->assertLessThan(strpos($body, 'id="RenameAlbum"'), $at);
    }

    /** [ERR][NEG] Core refuses a blank name through the method the dialog calls: no album is created. */
    #[DataProvider('blankNames')]
    public function testCoreCreatesNoAlbumForABlankName(string $name): void
    {
        $client = $this->clientAs(TestUsers::WEBMASTER);
        $before = (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_categories');
        $this->assertGreaterThan(0, $before);

        $res = $client->call('pwg.categories.add', array('name' => $name, 'parent' => 0, 'position' => 'last'));

        $this->assertNotSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame($before, (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_categories'));
    }

    /** The hint's German text, as the plugin's language file gives it. */
    private static function hintText(): string
    {
        include_once GALLERYRULES_PATH . 'include/events_album_name.inc.php';
        $lang = array();
        include GALLERYRULES_PATH . 'language/de_DE/plugin.lang.php';
        if (empty($lang[GALLERYRULES_ALBUM_NAME_MISSING]))
        {
            throw new RuntimeException('no German text for the album name hint');
        }
        return htmlspecialchars($lang[GALLERYRULES_ALBUM_NAME_MISSING], ENT_QUOTES, 'UTF-8');
    }

    private function clientAs(string $role): WsClient
    {
        $client = new WsClient();
        list($username, $password) = Config::credentials($role);
        $client->login($username, $password);

        return $client;
    }

    private function albumsPage(WsClient $client): string
    {
        $path = '/admin.php?page=albums';
        $response = $client->get($path);
        $body = $response['body'];

        $this->assertSame(200, $response['status'], $path);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), $path);
        $this->assertStringContainsString('id="AddAlbum"', $body, $path . ' is not the albums page');

        return $body;
    }
}
