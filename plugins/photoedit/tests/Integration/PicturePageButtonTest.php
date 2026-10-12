<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether the edit button follows the photo's album, read from the page source
 * the server emits. Which role gets it on a public album is RoleMatrixTest's.
 *
 * The button is server-rendered, so its absence in the source is the whole
 * [NEG] fact: an account that never receives the markup cannot reveal it.
 * The gate is "logged in and may see the photo" (decision 0059).
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

    private function rawPageAs(string $role): string
    {
        $client = new WsClient();
        list($username, $password) = Config::credentials($role);
        $client->login($username, $password);

        return $client->fetchPage($this->picturePath);
    }

    private function pageAs(string $role): string
    {
        $page = $this->rawPageAs($role);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($page), 'anti-vacuity: not a picture page');
        $this->assertStringContainsString('id="theMainImage"', $page, 'anti-vacuity: the photo is not on the page');

        return $page;
    }

    public static function nonAdminAccounts(): array
    {
        return array(
            'normal user' => array(TestUsers::NORMAL),
            'generic account' => array(TestUsers::GENERIC),
            );
    }

    /**
     * [NEG][BVA] An account without access to the photo's private album gets
     * no picture page and no button. The public side of the boundary, and every
     * role on it, is RoleMatrixTest's photoedit.turn-crop rows.
     */
    #[DataProvider('nonAdminAccounts')]
    public function testAPhotoInAPrivateAlbumGetsNoButton(string $role): void
    {
        $this->fixture->makeAlbumPrivate($this->album);

        $page = $this->rawPageAs($role);

        $this->assertNotSame('', $page, 'anti-vacuity: no answer at all');
        $this->assertStringNotContainsString('id="theMainImage"', $page);
        $this->assertStringNotContainsString(self::BUTTON, $page);
        $this->assertStringNotContainsString(self::CONTROLS, $page);
    }

    /** [ECP][BVA] Granted that album, the same account gets the button again: the gate is the album, not the role. */
    #[DataProvider('nonAdminAccounts')]
    public function testAGrantedPrivateAlbumGetsTheButton(string $role): void
    {
        $this->fixture->makeAlbumPrivate($this->album);
        $this->fixture->grantAlbumTo($this->album, Config::credentials($role)[0]);

        $page = $this->pageAs($role);

        $this->assertSame(1, substr_count($page, self::BUTTON));
    }
}
