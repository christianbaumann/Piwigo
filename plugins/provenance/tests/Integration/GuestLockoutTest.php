<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guests are locked out (decision 0055): no gallery page, and no web-service
 * method but core's session methods.
 *
 * Both halves come from core's `guest_access` switch, set in the install's
 * git-ignored config: the pages through check_status(), the API through
 * ws_isInvokeAllowed() (include/ws_functions.inc.php). The requirement is
 * decision 0055; core does the work, and what this test owns is that the
 * install runs with the switch off. It is the one place a guest's page view is
 * asserted - the plugins' own suites no longer render a page for a guest.
 */
final class GuestLockoutTest extends TestCase
{
    /** Where core sends a visitor who lacks access (include/functions_user.inc.php). */
    private const LOGIN_PAGE = 'identification.php';

    private Db $db;
    private FixtureBuilder $fixture;
    private int $catId;
    private int $imageId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);

        // A public album of this suite's own, so the normal account's cases do
        // not depend on what the install's real albums allow.
        $this->imageId = (int)$this->fixture->createTestImage()['id'];
        $this->catId = $this->fixture->createTestAlbum('Guest lockout test ' . bin2hex(random_bytes(4)));
        $this->fixture->attachImage($this->imageId, $this->catId);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** @return array<string, array{0: string}> */
    public static function galleryPages(): array
    {
        return array(
            'album list' => array('/index.php'),
            'picture page' => array('/picture.php?/%d/category/%d'),
            'tag list' => array('/tags.php'),
        );
    }

    /** [NEG] A guest asking for a gallery page lands on the login page. */
    #[DataProvider('galleryPages')]
    public function testAGuestIsSentToTheLoginPage(string $path): void
    {
        $guest = new WsClient();
        $res = $guest->fetchPage(sprintf($path, $this->imageId, $this->catId), false);

        $this->assertSame(200, $res['http_code']);
        $this->assertStringContainsString(
            self::LOGIN_PAGE,
            $res['url'],
            "a guest got $path itself - is \$conf['guest_access'] = false; set in local/config/config.inc.php?"
        );
    }

    /**
     * [HAPPY] A logged-in normal user gets the same page, not the login page.
     * Without it the guest case would pass on an install that sends everybody away.
     */
    #[DataProvider('galleryPages')]
    public function testANormalUserGetsThePage(string $path): void
    {
        $normal = new WsClient();
        $normal->login(Config::normalUsername(), Config::normalPassword());
        $res = $normal->fetchPage(sprintf($path, $this->imageId, $this->catId));

        $this->assertSame(200, $res['http_code']);
        $this->assertStringNotContainsString(self::LOGIN_PAGE, $res['url']);
    }

    /** @return array<string, array{0: string}> */
    public static function dataMethods(): array
    {
        return array(
            'core, photos' => array('pwg.categories.getImages'),
            'core, tags' => array('pwg.tags.getList'),
            'persons, names' => array('pwg.persons.getList'),
            'core, version' => array('pwg.getVersion'),
        );
    }

    /**
     * [NEG] A guest gets an error, not data, from a method without admin_only.
     * The version too: the deploy preflight logs in before it asks for it.
     */
    #[DataProvider('dataMethods')]
    public function testAGuestGetsNoDataFromTheApi(string $method): void
    {
        $guest = new WsClient();
        $res = $guest->call($method, array(), false);

        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(401, $res['json']['err']);
        $this->assertArrayNotHasKey('result', $res['json']);
    }

    /** [HAPPY] A guest may read the session status, and it says guest. */
    public function testAGuestGetsTheSessionStatus(): void
    {
        $guest = new WsClient();
        $res = $guest->call('pwg.session.getStatus', array(), false);

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame('guest', $res['json']['result']['status']);
    }

    /**
     * [HAPPY] A guest may log in, and the session then gets data.
     * login() is a guest's call to pwg.session.login; it throws if refused.
     */
    public function testALoggedInNormalUserStillGetsPhotos(): void
    {
        $normal = new WsClient();
        $normal->login(Config::normalUsername(), Config::normalPassword());
        $res = $normal->call('pwg.categories.getImages', array('cat_id' => $this->catId));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertNotEmpty($res['json']['result']['images']);
    }
}
