<?php
use PHPUnit\Framework\TestCase;

/**
 * Closing or reopening an album takes effect for a visitor at once, with no cache purge.
 *
 * Found in exploratory testing (SES-2026-10-15-05, FND-01 and FND-02): set_cat_visible()
 * changed categories.visible but left piwigo_user_cache alone, so a visitor's cached
 * forbidden_categories kept a just-closed album open and a just-reopened one closed until
 * something else cleared the cache. The fork's core fix clears it at the end of
 * set_cat_visible() (.claude/rules/fork-local-core-edits.md).
 *
 * Oracle: the requirement, not the code. Both callers of set_cat_visible() are driven, the
 * album properties page's pwg.categories.setInfo and the album options page
 * (admin/cat_options.php); a normal and a generic account each visit the album and its photo
 * first, so their cache is built and current before the change.
 */
final class CoreAlbumLockTakesEffectTest extends TestCase
{
    /** A gallery page shorter than this is an error page, not the album or the photo. */
    private const MIN_PAGE_BYTES = 2000;

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private int $albumId;
    private int $imageId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
        $this->fixture = new FixtureBuilder($this->db);

        $result = $this->ws->call('pwg.categories.add', array(
            'name' => 'album-lock-' . bin2hex(random_bytes(4)),
            'status' => 'public',
            ));
        $this->albumId = (int)($result['json']['result']['id'] ?? 0);
        $this->assertGreaterThan(0, $this->albumId, 'album was not created: ' . $result['body']);
        $this->fixture->adoptAlbum($this->albumId);

        $this->imageId = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($this->imageId, $this->albumId);
        $this->db->query(
            "UPDATE piwigo_categories SET representative_picture_id = {$this->imageId} WHERE id = {$this->albumId}"
        );
        $this->fixture->invalidateUserCache();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->invalidateUserCache();
        $this->ws->logout();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function pathsAndRoles(): array
    {
        return array(
            '[HAPPY] album properties, normal' => array('setInfo', 'normal'),
            '[ECP] album properties, generic' => array('setInfo', 'generic'),
            '[ECP] album options page, normal' => array('cat_options', 'normal'),
            '[ECP] album options page, generic' => array('cat_options', 'generic'),
            );
    }

    /** [ST] open -> closed: the visitor is refused the album and its photo on the next request. */
    #[\PHPUnit\Framework\Attributes\DataProvider('pathsAndRoles')]
    public function testClosingAnAlbumRefusesTheVisitorAtOnce(string $path, string $role): void
    {
        $visitor = $this->visitor($role);
        $this->assertVisitorSees($visitor, 200, 'before closing');

        $this->setLocked($path, true);

        $this->assertVisitorSees($visitor, 401, 'right after closing');
    }

    /** [ST] closed -> open: the visitor gets the album and its photo on the next request. */
    #[\PHPUnit\Framework\Attributes\DataProvider('pathsAndRoles')]
    public function testReopeningAnAlbumLetsTheVisitorInAtOnce(string $path, string $role): void
    {
        $this->db->query("UPDATE piwigo_categories SET visible = 'false' WHERE id = {$this->albumId}");
        $this->fixture->invalidateUserCache();
        $visitor = $this->visitor($role);
        $this->assertVisitorSees($visitor, 401, 'before reopening');

        $this->setLocked($path, false);

        $this->assertVisitorSees($visitor, 200, 'right after reopening');
    }

    private function assertVisitorSees(WsClient $visitor, int $expected, string $when): void
    {
        $pages = array(
            'album' => '/index.php?/category/' . $this->albumId,
            'photo' => '/picture.php?/' . $this->imageId . '/category/' . $this->albumId,
            );
        foreach ($pages as $what => $url)
        {
            $response = $visitor->fetchPage($url);
            $this->assertSame($expected, $response['http_code'], "$what $when");
            if ($expected === 200)
            {
                $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen((string)$response['body']), "$what $when is a real page");
            }
        }
    }

    private function setLocked(string $path, bool $locked): void
    {
        if ($path === 'setInfo')
        {
            $result = $this->ws->call('pwg.categories.setInfo', array(
                'category_id' => $this->albumId,
                'visible' => $locked ? 'false' : 'true',
                'pwg_token' => $this->ws->token(),
                ));
            $this->assertSame('ok', $result['json']['stat'] ?? null, 'setInfo failed: ' . $result['body']);
        }
        else
        {
            $params = array('pwg_token' => $this->ws->token());
            $params[$locked ? 'falsify' : 'trueify'] = '1';
            $params[$locked ? 'cat_true' : 'cat_false'] = array($this->albumId);
            $response = $this->ws->postPage('/admin.php?page=cat_options&section=visible', $params);
            $this->assertSame(200, $response['http_code'], 'the album options page did not answer');
        }

        $this->assertSame(
            $locked ? 'false' : 'true',
            (string)$this->db->scalar("SELECT visible FROM piwigo_categories WHERE id = {$this->albumId}"),
            'the lock itself was not saved'
            );
    }

    private function visitor(string $role): WsClient
    {
        $credentials = array(
            'normal' => array(Config::normalUsername(), Config::normalPassword()),
            'generic' => array(Config::genericUsername(), Config::genericPassword()),
            );
        $client = new WsClient();
        $client->login($credentials[$role][0], $credentials[$role][1]);

        return $client;
    }
}
