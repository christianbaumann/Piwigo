<?php
use PHPUnit\Framework\TestCase;

/**
 * The menu's photo count ("N Fotos" under the album menu) equals the photos the
 * viewer can open: the photos pwg.categories.getImages lists for that account
 * over every album, which is what the album pages offer.
 *
 * Reported as SES-2026-10-13-01: the menu said 106 while the API and the album
 * counts gave 105. Cause (docs/agents/research/2026-10-17-menu-photo-count.md):
 * nb_total_images counted image_category rows without asking whether the photo
 * or the album still exists, so a link left behind by a deleted photo or a
 * deleted album counted. Decision 0074; a fork-local core fix in
 * include/functions_user.inc.php.
 *
 * Fixture, beside the install's own photos: one photo in a public album (opens),
 * a link to a photo whose row is gone, a photo linked only to an album whose row
 * is gone, a photo in no album and a photo waiting in the lounge (none opens).
 * Guests are locked out (decision 0055) and see no menu, so they have no row.
 */
final class CoreMenuPhotoCountTest extends TestCase
{
    private const PAGE_SIZE = 500;

    private Db $db;
    private FixtureBuilder $fixture;
    private int $album;
    private int $openable;
    private int $loungeImage;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);

        $this->album = $this->fixture->createTestAlbum('menu-count-' . bin2hex(random_bytes(4)));

        $this->openable = (int)$this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($this->openable, $this->album);

        // A deleted photo whose link stayed: destroyTestImages() removes the link by image id.
        $deleted = (int)$this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($deleted, $this->album);
        $this->db->query("DELETE FROM piwigo_images WHERE id = $deleted");

        // A photo whose only album was deleted, the link left behind.
        $goneAlbum = $this->fixture->createTestAlbum('menu-count-gone-' . bin2hex(random_bytes(4)));
        $orphaned = (int)$this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($orphaned, $goneAlbum);
        $this->db->query("DELETE FROM piwigo_categories WHERE id = $goneAlbum");

        $this->fixture->createTestImage();

        $this->loungeImage = (int)$this->fixture->createTestImage()['id'];
        $this->db->query("INSERT INTO piwigo_lounge (image_id, category_id) VALUES ({$this->loungeImage}, {$this->album})");

        $this->fixture->invalidateUserCache();
    }

    protected function tearDown(): void
    {
        $this->db->query('DELETE FROM piwigo_lounge WHERE image_id = ' . $this->loungeImage);
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->invalidateUserCache();
    }

    /** @return array<string, array{0: string}> */
    public static function roles(): array
    {
        return array(
            '[HAPPY] webmaster' => array('webmaster'),
            '[ECP] admin' => array('admin'),
            '[ECP] normal' => array('normal'),
            '[ECP] generic' => array('generic'),
            );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function testTheMenuCountsThePhotosTheRoleCanOpen(string $role): void
    {
        $client = $this->clientAs($role);

        $openable = $this->photosListedFor($client);
        $this->assertContains($this->openable, $openable, 'the fixture photo in a public album is listed');
        $this->assertNotContains($this->loungeImage, $openable, 'the lounge photo is not listed yet');
        $page = $client->fetchPage('/picture.php?/' . $this->openable . '/category/' . $this->album);
        $this->assertSame(200, $page['http_code'], 'the listed fixture photo opens');

        $this->assertSame(1, (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_lounge WHERE image_id = ' . $this->loungeImage
        ), 'the photo was still in the lounge');

        $this->assertSame(
            count($openable),
            $client->menuPhotoCount(),
            'menu photo count vs. photos the role can open'
        );
    }

    /** @return int[] distinct ids pwg.categories.getImages lists over every album */
    private function photosListedFor(WsClient $client): array
    {
        $ids = array();
        for ($page = 0; ; $page++)
        {
            $result = $client->call('pwg.categories.getImages', array(
                'recursive' => 'true',
                'per_page' => self::PAGE_SIZE,
                'page' => $page,
                ));
            $this->assertSame('ok', $result['json']['stat'] ?? null, json_encode($result['json']));
            $images = $result['json']['result']['images'];
            foreach ($images as $image)
            {
                $ids[(int)$image['id']] = true;
            }
            if (count($images) < self::PAGE_SIZE)
            {
                break;
            }
        }

        return array_keys($ids);
    }

    private function clientAs(string $role): WsClient
    {
        $credentials = array(
            'webmaster' => array(Config::username(), Config::password()),
            'admin' => array(Config::adminUsername(), Config::adminPassword()),
            'normal' => array(Config::normalUsername(), Config::normalPassword()),
            'generic' => array(Config::genericUsername(), Config::genericPassword()),
            );
        $client = new WsClient();
        $client->login($credentials[$role][0], $credentials[$role][1]);

        return $client;
    }
}
