<?php
use PHPUnit\Framework\TestCase;

/**
 * pwg.images.addSimple refuses a file identical to an existing photo and leaves
 * that photo untouched, and a photo it adds is in its album, with the album's
 * Herkunft, as soon as the call returns (design: "addSimple refuses a file
 * identical to an existing photo", "addSimple completes the upload").
 *
 * Fixture, all this suite's own: one public album carrying Herkunft, a second
 * public album, and generated PNGs uploaded through the API as the webmaster
 * (addSimple is admin-only). The lounge is forced on, as it is on every install
 * past core's lounge_activate_threshold of one photo.
 */
final class AddSimpleTest extends TestCase
{
    private const HERKUNFT = array(
        'provenance_physical_album' => 'Album 7 (AddSimpleTest)',
        'provenance_owner' => 'Familie Test',
        'provenance_scanned_on' => '2026-10-15',
        );

    /** The code a refused identical file answers with. */
    private const IDENTICAL_FILE_CODE = 409;

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $webmaster;
    private string $token;
    private int $albumId;
    private int $otherAlbumId;
    private string $lounge;
    private string $detectDuplicate;
    private array $uploadedIds = array();
    private array $files = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive();
        $this->fixture->assertPluginActive('provenance');

        $this->lounge = (string)$this->db->scalar("SELECT value FROM piwigo_config WHERE param = 'lounge_active'");
        $this->detectDuplicate = (string)$this->db->scalar("SELECT value FROM piwigo_config WHERE param = 'upload_detect_duplicate'");
        $this->setConfig('lounge_active', 'true');
        $this->setConfig('upload_detect_duplicate', 'true');

        $suffix = bin2hex(random_bytes(4));
        $this->albumId = $this->fixture->createTestAlbum('galleryrules-addsimple-' . $suffix);
        $this->otherAlbumId = $this->fixture->createTestAlbum('galleryrules-addsimple-other-' . $suffix);
        $set = array();
        foreach (self::HERKUNFT as $column => $value)
        {
            $set[] = "$column = '" . $this->db->escape($value) . "'";
        }
        $this->db->query('UPDATE piwigo_categories SET ' . implode(', ', $set) . ' WHERE id = ' . $this->albumId);
        $this->fixture->invalidateUserCache();

        $this->webmaster = new WsClient();
        list($user, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->webmaster->login($user, $password);
        $this->token = $this->webmaster->token();
    }

    protected function tearDown(): void
    {
        if (!empty($this->uploadedIds))
        {
            $ids = implode(',', $this->uploadedIds);
            $this->webmaster->call('pwg.images.delete', array('image_id' => $ids, 'pwg_token' => $this->token));
            $this->db->query("DELETE FROM piwigo_lounge WHERE image_id IN ($ids)");
            $this->db->query("DELETE FROM piwigo_provenance_history WHERE object = 'photo' AND object_id IN ($ids)");
        }
        foreach ($this->files as $file)
        {
            @unlink($file);
        }
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->setConfig('lounge_active', $this->lounge);
        $this->setConfig('upload_detect_duplicate', $this->detectDuplicate);
    }

    /** [HAPPY] the photo is in its album, out of the lounge, with the album's Herkunft, without uploadCompleted. */
    public function testAnAddedPhotoIsInItsAlbumWithItsHerkunftAtOnce(): void
    {
        $result = $this->addSimple($this->generateFile(), $this->albumId, 'galleryrules addSimple');
        $id = $this->uploadedId($result);

        $this->assertSame(1, (int)$this->db->scalar(
            "SELECT COUNT(*) FROM piwigo_image_category WHERE image_id = $id AND category_id = {$this->albumId}"
        ), 'the photo is not in its album');
        $this->assertSame(0, (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_lounge WHERE image_id = $id"),
            'the photo still waits in the lounge');

        $row = $this->db->query(
            'SELECT ' . implode(', ', array_keys(self::HERKUNFT)) . " FROM piwigo_images WHERE id = $id"
        )->fetch_assoc();
        $this->assertSame(self::HERKUNFT, $row);
    }

    /** [HAPPY] the URL addSimple returns answers a normal account at once. */
    public function testTheReturnedUrlAnswersANormalAccountAtOnce(): void
    {
        $result = $this->addSimple($this->generateFile(), $this->albumId, 'galleryrules addSimple url');
        $id = $this->uploadedId($result);

        $normal = new WsClient();
        list($user, $password) = Config::credentials(TestUsers::NORMAL);
        $normal->login($user, $password);

        $page = $normal->get(self::pathOf($result['json']['result']['url']));
        $this->assertSame(200, $page['status'], 'the photo page refused the normal account');
        $this->assertStringContainsString('id="theMainImage"', $page['body'], 'the page shows no photo');
        $this->assertStringContainsString('picture.php?/' . $id . '/', self::pathOf($result['json']['result']['url']));
    }

    /** [ST] a normal account whose counts were cached before the upload sees the new photo counted. */
    public function testANormalAccountsCachedCountIncludesThePhotoAtOnce(): void
    {
        // An empty album is not listed, so it starts with one photo of the fixture's own.
        $this->fixture->attachImage($this->fixture->createTestImage(), $this->albumId);
        $this->fixture->invalidateUserCache();
        $normal = new WsClient();
        list($user, $password) = Config::credentials(TestUsers::NORMAL);
        $normal->login($user, $password);
        $this->assertSame(1, $this->albumCountFor($normal), 'the album did not hold one photo before the upload');

        $this->uploadedId($this->addSimple($this->generateFile(), $this->albumId, 'galleryrules addSimple count'));

        $this->assertSame(2, $this->albumCountFor($normal));
    }

    /** [NEG] an identical file is refused, naming the photo, which keeps its name, file and albums. */
    public function testAnIdenticalFileIsRefusedAndTheExistingPhotoStaysUntouched(): void
    {
        $file = $this->generateFile();
        $id = $this->uploadedId($this->addSimple($file, $this->albumId, 'galleryrules original name'));
        $before = $this->photoState($id);
        $this->assertGreaterThan(0, strlen($before['md5_file']));

        $refused = $this->addSimple($file, $this->otherAlbumId, 'galleryrules other name');

        $this->assertSame('fail', $refused['json']['stat'] ?? null, $refused['body']);
        $this->assertSame(self::IDENTICAL_FILE_CODE, (int)$refused['json']['err']);
        $this->assertStringContainsString('#' . $id, $refused['json']['message']);
        $this->assertSame($before, $this->photoState($id), 'the existing photo changed');
        $this->assertSame(1, (int)$this->db->scalar(
            "SELECT COUNT(*) FROM piwigo_images WHERE md5sum = '" . md5_file($file) . "'"
        ), 'a second photo was created');
    }

    /** [ECP] with duplicate detection off core adds a second photo, and the first keeps its name. */
    public function testWithDuplicateDetectionOffTheFileBecomesASecondPhoto(): void
    {
        $file = $this->generateFile();
        $id = $this->uploadedId($this->addSimple($file, $this->albumId, 'galleryrules first'));
        $before = $this->photoState($id);
        $this->setConfig('upload_detect_duplicate', 'false');

        $second = $this->uploadedId($this->addSimple($file, $this->otherAlbumId, 'galleryrules second'));

        $this->assertNotSame($id, $second);
        $this->assertSame($before, $this->photoState($id));
    }

    /** [ECP] a replace (image_id given) of a photo with its own file is not refused. */
    public function testAReplaceWithTheSameFileIsNotRefused(): void
    {
        $file = $this->generateFile();
        $id = $this->uploadedId($this->addSimple($file, $this->albumId, 'galleryrules replaced'));

        $result = $this->addSimple($file, $this->albumId, 'galleryrules replaced', $id);

        $this->assertSame($id, $this->uploadedId($result));
    }

    private function addSimple(string $file, int $albumId, string $name, ?int $imageId = null): array
    {
        $params = array('category' => array($albumId), 'name' => $name);
        if ($imageId !== null)
        {
            $params['image_id'] = $imageId;
        }
        return $this->webmaster->upload('pwg.images.addSimple', $params, 'image', $file);
    }

    private function uploadedId(array $result): int
    {
        $this->assertSame('ok', $result['json']['stat'] ?? null, $result['body']);
        $id = (int)$result['json']['result']['image_id'];
        $this->assertGreaterThan(0, $id);
        $this->uploadedIds[$id] = $id;
        return $id;
    }

    /** A PNG no photo in the gallery has: ImageMagick stamps each with its creation time. */
    private function generateFile(): string
    {
        $file = sys_get_temp_dir() . '/galleryrules-addsimple-' . bin2hex(random_bytes(8)) . '.png';
        $color = sprintf('#%06x', random_int(0, 0xffffff));
        exec('convert -size 90x60 xc:' . escapeshellarg($color) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        if ($status !== 0 or !is_file($file))
        {
            throw new RuntimeException('convert produced no file: ' . implode("\n", $output));
        }
        $this->files[] = $file;
        $md5 = md5_file($file);
        if ((int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_images WHERE md5sum = '$md5'") !== 0)
        {
            throw new RuntimeException("generated file $file is already a photo");
        }
        return $file;
    }

    /** What the refusal must leave alone: the row's name and file, the file's bytes, the albums. */
    private function photoState(int $id): array
    {
        $row = $this->db->query("SELECT name, file, path, md5sum FROM piwigo_images WHERE id = $id")->fetch_assoc();
        $row['md5_file'] = (string)md5_file(PIWIGO_ROOT . $row['path']);
        $row['albums'] = (string)$this->db->scalar(
            "SELECT GROUP_CONCAT(category_id ORDER BY category_id) FROM piwigo_image_category WHERE image_id = $id"
        );
        $row['lounge'] = (string)$this->db->scalar(
            "SELECT GROUP_CONCAT(category_id ORDER BY category_id) FROM piwigo_lounge WHERE image_id = $id"
        );
        return $row;
    }

    /** The test album's photo count as pwg.categories.getList reports it to $client, read from its user cache. */
    private function albumCountFor(WsClient $client): int
    {
        $res = $client->call('pwg.categories.getList');
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        foreach ($res['json']['result']['categories'] as $album)
        {
            if ((int)$album['id'] === $this->albumId)
            {
                return (int)$album['nb_images'];
            }
        }
        $this->fail("album {$this->albumId} is not listed: " . $res['body']);
    }

    private function setConfig(string $param, string $value): void
    {
        $this->db->query(
            "INSERT INTO piwigo_config (param, value) VALUES ('" . $this->db->escape($param) . "', '" . $this->db->escape($value) . "')" .
            " ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        if ((string)$this->db->scalar("SELECT value FROM piwigo_config WHERE param = '" . $this->db->escape($param) . "'") !== $value)
        {
            throw new RuntimeException("config $param did not take $value");
        }
    }

    private static function pathOf(string $url): string
    {
        $path = preg_replace('#^https?://[^/]+#', '', $url);
        return '/' . ltrim(preg_replace('#^\./#', '', $path), '/');
    }
}
