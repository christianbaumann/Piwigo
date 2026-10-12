<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who gets the edit button, read from the page source the server emits.
 *
 * The button is server-rendered, so its absence in the source is the whole
 * [NEG] fact: an account that never receives the markup cannot reveal it.
 * The gate is "logged in and may see the photo" (decision 0059); a role
 * decides nothing.
 */
final class PicturePageButtonTest extends TestCase
{
    /** A picture page shorter than this is an error page, on which "absent" says nothing. */
    private const MIN_PAGE_BYTES = 5000;

    private const BUTTON = 'id="photoedit-toggle"';
    private const CONTROLS = 'id="photoedit-controls"';

    private Db $db;
    private FixtureBuilder $fixture;
    private int $album;
    private string $picturePath;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);

        $this->fixture->assertPluginActive();

        $image = $this->fixture->createTestImage();
        $this->album = $this->fixture->createTestAlbum('Photoedit test ' . bin2hex(random_bytes(4)));
        $this->fixture->attachImage((int)$image['id'], $this->album);
        $this->fixture->invalidateUserCache();

        $this->picturePath = '/picture.php?/' . $image['id'] . '/category/' . $this->album;
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    private function rawPageAs(?string $role): string
    {
        $client = new WsClient();
        if ($role !== null)
        {
            list($username, $password) = Config::credentials($role);
            $client->login($username, $password);
        }

        return $client->fetchPage($this->picturePath);
    }

    private function pageAs(string $role): string
    {
        $page = $this->rawPageAs($role);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($page), 'anti-vacuity: not a picture page');
        $this->assertStringContainsString('id="theMainImage"', $page, 'anti-vacuity: the photo is not on the page');

        return $page;
    }

    public static function loggedInAccounts(): array
    {
        return array(
            'webmaster' => array(TestUsers::WEBMASTER),
            'administrator, not webmaster' => array(TestUsers::ADMIN),
            'normal user' => array(TestUsers::NORMAL),
            );
    }

    /** [HAPPY][ECP] Every logged-in account that sees the photo gets the button and the controls once each (decision 0059). */
    #[DataProvider('loggedInAccounts')]
    public function testEveryLoggedInAccountGetsTheButton(string $role): void
    {
        $page = $this->pageAs($role);

        $this->assertSame(1, substr_count($page, self::BUTTON));
        $this->assertSame(1, substr_count($page, self::CONTROLS));
    }

    /** [NEG] A guest gets the login page, and no button on it. */
    public function testAGuestGetsNoButton(): void
    {
        $page = $this->rawPageAs(null);

        $this->assertStringContainsString('name="password"', $page, 'anti-vacuity: not the login page');
        $this->assertStringNotContainsString(self::BUTTON, $page);
        $this->assertStringNotContainsString(self::CONTROLS, $page);
    }

    /** [NEG] A normal user without access to the photo's private album gets no picture page and no button. */
    public function testAPhotoInAPrivateAlbumGetsNoButton(): void
    {
        $this->fixture->makeAlbumPrivate($this->album);

        $page = $this->rawPageAs(TestUsers::NORMAL);

        $this->assertNotSame('', $page, 'anti-vacuity: no answer at all');
        $this->assertStringNotContainsString('id="theMainImage"', $page);
        $this->assertStringNotContainsString(self::BUTTON, $page);
        $this->assertStringNotContainsString(self::CONTROLS, $page);
    }

    /** [ECP] Granted that album, the same account gets the button again: the gate is the album, not the role. */
    public function testAGrantedPrivateAlbumGetsTheButton(): void
    {
        $this->fixture->makeAlbumPrivate($this->album);
        $this->fixture->grantAlbumTo($this->album, Config::credentials(TestUsers::NORMAL)[0]);

        $page = $this->pageAs(TestUsers::NORMAL);

        $this->assertSame(1, substr_count($page, self::BUTTON));
    }
}
