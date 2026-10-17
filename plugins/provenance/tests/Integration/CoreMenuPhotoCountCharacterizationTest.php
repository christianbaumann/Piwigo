<?php
use PHPUnit\Framework\TestCase;

/**
 * [ERR] Characterization of core's menu photo count ("N Fotos" under the album
 * menu), the user cache's nb_total_images (include/functions_user.inc.php,
 * read by include/menubar.inc.php).
 *
 * The oracle is the current implementation: each case records how much one
 * fixture photo moves the count for each role, as core does it today. No
 * requirement confirms these values; they guard the cases the menu count fix
 * (task 23, decision 0074) must not change. The two cases it does change, a
 * link to a deleted photo and a link to a deleted album, are requirements and
 * live in CoreMenuPhotoCountTest.
 *
 * Measured as a difference before and after the fixture, so the install's own
 * photos do not enter the expected values.
 */
final class CoreMenuPhotoCountCharacterizationTest extends TestCase
{
    private const ROLES = array('webmaster', 'admin', 'normal', 'generic');

    /** @var array<string, WsClient> */
    private static array $clients = array();

    private Db $db;
    private FixtureBuilder $fixture;
    private ?int $loungeImageId = null;

    public static function setUpBeforeClass(): void
    {
        $credentials = array(
            'webmaster' => array(Config::username(), Config::password()),
            'admin' => array(Config::adminUsername(), Config::adminPassword()),
            'normal' => array(Config::normalUsername(), Config::normalPassword()),
            'generic' => array(Config::genericUsername(), Config::genericPassword()),
            );
        foreach ($credentials as $role => $pair)
        {
            $client = new WsClient();
            $client->login($pair[0], $pair[1]);
            self::$clients[$role] = $client;
        }
    }

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->loungeImageId !== null)
        {
            $this->db->query('DELETE FROM piwigo_lounge WHERE image_id = ' . $this->loungeImageId);
        }
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->invalidateUserCache();
    }

    /**
     * @return array<string, array{0: string, 1: array<string, int>}>
     */
    public static function cases(): array
    {
        $all = array('webmaster' => 1, 'admin' => 1, 'normal' => 1, 'generic' => 1);
        $none = array('webmaster' => 0, 'admin' => 0, 'normal' => 0, 'generic' => 0);
        $adminsOnly = array('webmaster' => 1, 'admin' => 1, 'normal' => 0, 'generic' => 0);

        return array(
            '[ERR] a photo in a public album counts for every role' => array('in-album', $all),
            '[ERR] a photo in no album counts for nobody' => array('no-album', $none),
            '[ERR] a photo waiting in the lounge counts for nobody' => array('lounge', $none),
            // Privacy level 1: above a level-0 account, below the administrators' 8.
            '[ERR] a photo above the account\'s privacy level counts for administrators only' => array('level', $adminsOnly),
            // calculate_permissions() forbids an ungranted private album to administrators too.
            '[ERR] a photo in a private album without a grant counts for nobody' => array('private', $none),
            '[ERR] a photo in a locked album counts for administrators only' => array('locked', $adminsOnly),
            );
    }

    /**
     * @param array<string, int> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testOnePhotoMovesTheMenuCountAsCoreDoesToday(string $case, array $expected): void
    {
        $before = $this->menuCounts();

        $this->arrange($case);
        $this->fixture->invalidateUserCache();

        $after = $this->menuCounts();
        $moved = array();
        foreach (self::ROLES as $role)
        {
            $moved[$role] = $after[$role] - $before[$role];
        }
        $this->assertSame($expected, $moved, "menu count change per role for case $case");

        if ($case === 'lounge')
        {
            $this->assertSame(1, (int)$this->db->scalar(
                'SELECT COUNT(*) FROM piwigo_lounge WHERE image_id = ' . $this->loungeImageId
            ), 'the photo was still in the lounge while the counts were read');
        }
    }

    private function arrange(string $case): void
    {
        $album = $this->fixture->createTestAlbum('menu-count-char-' . bin2hex(random_bytes(4)));
        $image = (int)$this->fixture->createTestImage()['id'];

        switch ($case)
        {
            case 'in-album':
                $this->fixture->attachImage($image, $album);
                break;
            case 'no-album':
                break;
            case 'lounge':
                $this->db->query("INSERT INTO piwigo_lounge (image_id, category_id) VALUES ($image, $album)");
                $this->loungeImageId = $image;
                break;
            case 'level':
                $this->fixture->attachImage($image, $album);
                $this->db->query("UPDATE piwigo_images SET level = 1 WHERE id = $image");
                break;
            case 'private':
                $this->fixture->attachImage($image, $album);
                $this->fixture->makeAlbumPrivate($album);
                break;
            case 'locked':
                $this->fixture->attachImage($image, $album);
                $this->db->query("UPDATE piwigo_categories SET visible = 'false' WHERE id = $album");
                break;
            default:
                throw new LogicException("unknown case $case");
        }
    }

    /** @return array<string, int> the menu's photo count, per role */
    private function menuCounts(): array
    {
        $counts = array();
        foreach (self::ROLES as $role)
        {
            $counts[$role] = self::$clients[$role]->menuPhotoCount();
        }

        return $counts;
    }
}
