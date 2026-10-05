<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who gets the edit button, read from the page source the server emits.
 *
 * The button is server-rendered, so its absence in the source is the whole
 * [NEG] fact: an account that never receives the markup cannot reveal it.
 */
final class PicturePageButtonTest extends TestCase
{
    /** A picture page shorter than this is an error page, on which "absent" says nothing. */
    private const MIN_PAGE_BYTES = 5000;

    private const BUTTON = 'id="photoedit-toggle"';
    private const CONTROLS = 'id="photoedit-controls"';

    private Db $db;
    private FixtureBuilder $fixture;
    private string $picturePath;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);

        $this->fixture->assertPluginActive();

        $image = $this->fixture->createTestImage();
        $album = $this->fixture->createTestAlbum('Photoedit test ' . bin2hex(random_bytes(4)));
        $this->fixture->attachImage((int)$image['id'], $album);
        $this->fixture->invalidateUserCache();

        $this->picturePath = '/picture.php?/' . $image['id'] . '/category/' . $album;
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    private function pageAs(?string $role): string
    {
        $client = new WsClient();
        if ($role !== null)
        {
            list($username, $password) = Config::credentials($role);
            $client->login($username, $password);
        }

        $page = $client->fetchPage($this->picturePath);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($page), 'anti-vacuity: not a picture page');
        $this->assertStringContainsString('id="theMainImage"', $page, 'anti-vacuity: the photo is not on the page');

        return $page;
    }

    /** [HAPPY] The webmaster gets the button and the edit controls once each. */
    public function testTheWebmasterGetsTheButton(): void
    {
        $page = $this->pageAs(TestUsers::WEBMASTER);

        $this->assertSame(1, substr_count($page, self::BUTTON));
        $this->assertSame(1, substr_count($page, self::CONTROLS));
    }

    public static function accountsWithoutTheButton(): array
    {
        return array(
            'administrator, not webmaster' => array(TestUsers::ADMIN),
            'normal user' => array(TestUsers::NORMAL),
            'guest' => array(null),
            );
    }

    /** [NEG] Nobody else gets the button or the controls. */
    #[DataProvider('accountsWithoutTheButton')]
    public function testNobodyElseGetsTheButton(?string $role): void
    {
        $page = $this->pageAs($role);

        $this->assertStringNotContainsString(self::BUTTON, $page);
        $this->assertStringNotContainsString(self::CONTROLS, $page);
    }
}
