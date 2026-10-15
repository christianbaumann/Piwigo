<?php
/**
 * Forces a known database state and asserts it took effect, so a test never
 * runs over a state it merely hoped for.
 *
 * Starts as the guard every galleryrules suite runs behind; the fixtures the
 * behaviour changes need are added with them.
 */
class FixtureBuilder
{
    private Db $db;

    /** The piwigo_config row that marks an install as expendable; see create-test-users.php. */
    public const THROWAWAY_PARAM = 'galleryrules_throwaway_install';

    public function __construct(Db $db)
    {
        $this->db = $db;
        self::assertThrowawayInstall($db);
    }

    /**
     * Refuses to build a fixture against an install that has not been declared
     * expendable. Fails closed, naming the script that sets the marker.
     */
    public static function assertThrowawayInstall(Db $db): void
    {
        $marker = $db->scalar(
            "SELECT value FROM piwigo_config WHERE param = '" . $db->escape(self::THROWAWAY_PARAM) . "'"
        );

        if ((string)$marker !== '1')
        {
            throw new RuntimeException(
                "This install is not marked as a throwaway, and the galleryrules suites change gallery content.\n" .
                "Mark an install whose gallery you can afford to lose with:\n" .
                "  ddev exec php plugins/galleryrules/tests/Support/create-test-users.php\n" .
                "Never mark a production install."
            );
        }
    }

    /** Where createTestImage() writes, relative to the Piwigo root. */
    public const TEST_IMAGE_DIR = 'upload/galleryrules-test/';

    /** Pixel size of a generated fixture photo. */
    private const TEST_IMAGE_SIZE = '120x80';

    private array $testImages = array();
    private array $testAlbums = array();

    /**
     * A photo of this suite's own: a generated single-colour PNG, never a copy of
     * a real scan, registered as an image row.
     *
     * @return int the photo's id
     */
    public function createTestImage(): int
    {
        $dir = PIWIGO_ROOT . self::TEST_IMAGE_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))
        {
            throw new RuntimeException("cannot create $dir");
        }

        $name = 'galleryrules-test-' . bin2hex(random_bytes(8)) . '.png';
        $output = array();
        $status = 1;
        exec('convert -size ' . self::TEST_IMAGE_SIZE . ' xc:#8899aa ' . escapeshellarg($dir . $name) . ' 2>&1', $output, $status);
        clearstatcache(true, $dir . $name);
        if ($status !== 0 or !is_file($dir . $name) or filesize($dir . $name) < 1)
        {
            throw new RuntimeException("ImageMagick convert did not produce $dir$name: " . implode("\n", $output));
        }

        list($width, $height) = explode('x', self::TEST_IMAGE_SIZE);
        $dbPath = './' . self::TEST_IMAGE_DIR . $name;
        $this->db->query(
            'INSERT INTO piwigo_images (file, path, date_available, filesize, width, height) VALUES (' .
            "'" . $this->db->escape($name) . "', '" . $this->db->escape($dbPath) . "', NOW(), 1, " . (int)$width . ', ' . (int)$height . ')'
        );
        $id = $this->db->insertId();
        if ($id <= 0)
        {
            throw new RuntimeException('fixture image row was not inserted');
        }
        $this->testImages[] = array('id' => $id, 'file' => $dir . $name);

        return $id;
    }

    /**
     * A public album of this suite's own, top-level or inside another of its
     * albums. uppercats and global_rank are computed as core does.
     *
     * @return int the album's id
     */
    public function createTestAlbum(string $name, ?int $parentId = null): int
    {
        $parent = null;
        if ($parentId !== null)
        {
            $parent = $this->db->query(
                'SELECT uppercats, global_rank FROM piwigo_categories WHERE id = ' . $parentId
            )->fetch_assoc();
            if ($parent === null)
            {
                throw new RuntimeException("no parent album $parentId");
            }
        }

        $this->db->query(
            'INSERT INTO piwigo_categories (name, id_uppercat, uppercats, `rank`, global_rank, status, visible) VALUES (' .
            "'" . $this->db->escape($name) . "', " . ($parentId === null ? 'NULL' : $parentId) . ", '', 1, '1', 'public', 'true')"
        );
        $id = $this->db->insertId();
        if ($id <= 0)
        {
            throw new RuntimeException('fixture album row was not inserted');
        }
        $this->testAlbums[] = $id;

        $uppercats = $parent === null ? (string)$id : $parent['uppercats'] . ',' . $id;
        $globalRank = $parent === null ? (string)$id : $parent['global_rank'] . '.1';
        $this->db->query(
            "UPDATE piwigo_categories SET uppercats = '$uppercats', global_rank = '$globalRank' WHERE id = $id"
        );
        if ((string)$this->db->scalar("SELECT uppercats FROM piwigo_categories WHERE id = $id") !== $uppercats)
        {
            throw new RuntimeException("fixture album $id did not take its uppercats");
        }

        return $id;
    }

    /** Puts one photo in one album and makes it the album's thumbnail, asserting both took effect. */
    public function attachImage(int $imageId, int $albumId): void
    {
        $this->db->query("INSERT INTO piwigo_image_category (image_id, category_id) VALUES ($imageId, $albumId)");
        // The album list skips an album with no representative photo.
        $this->db->query("UPDATE piwigo_categories SET representative_picture_id = $imageId WHERE id = $albumId");

        $linked = (int)$this->db->scalar(
            "SELECT COUNT(*) FROM piwigo_image_category ic JOIN piwigo_categories c ON c.id = ic.category_id " .
            "WHERE ic.image_id = $imageId AND ic.category_id = $albumId AND c.representative_picture_id = $imageId"
        );
        if ($linked !== 1)
        {
            throw new RuntimeException("photo $imageId was not linked to album $albumId");
        }
    }

    /**
     * Stores a photo's Titel, Autor and Info as given, straight into its row, as a
     * value that reached the database by any path would be.
     */
    public function storePhotoFields(int $imageId, string $name, string $author, string $comment): void
    {
        $this->db->query(
            "UPDATE piwigo_images SET name = '" . $this->db->escape($name) . "', author = '" . $this->db->escape($author) .
            "', comment = '" . $this->db->escape($comment) . "' WHERE id = $imageId"
        );
        $row = $this->db->query("SELECT name, author, comment FROM piwigo_images WHERE id = $imageId")->fetch_assoc();
        if ($row !== array('name' => $name, 'author' => $author, 'comment' => $comment))
        {
            throw new RuntimeException("photo $imageId did not take its fields");
        }
    }

    /** Stores an album's name and description as given, straight into its row. */
    public function storeAlbumFields(int $albumId, string $name, string $comment): void
    {
        $this->db->query(
            "UPDATE piwigo_categories SET name = '" . $this->db->escape($name) . "', comment = '" . $this->db->escape($comment) .
            "' WHERE id = $albumId"
        );
        $row = $this->db->query("SELECT name, comment FROM piwigo_categories WHERE id = $albumId")->fetch_assoc();
        if ($row !== array('name' => $name, 'comment' => $comment))
        {
            throw new RuntimeException("album $albumId did not take its fields");
        }
    }

    /**
     * Marks every user's cached permissions stale, so an album created or
     * removed by direct SQL is seen as it now is. Core rebuilds them on the
     * next request.
     */
    public function invalidateUserCache(): void
    {
        $this->db->query("UPDATE piwigo_user_cache SET need_update = 'true'");
    }

    /** Removes every photo this fixture created, with its file and its derivatives. */
    public function destroyTestImages(): void
    {
        foreach ($this->testImages as $image)
        {
            $this->db->query('DELETE FROM piwigo_image_category WHERE image_id = ' . (int)$image['id']);
            $this->db->query('DELETE FROM piwigo_images WHERE id = ' . (int)$image['id']);
            @unlink($image['file']);
            $derivatives = PIWIGO_ROOT . '_data/i/' . self::TEST_IMAGE_DIR . pathinfo($image['file'], PATHINFO_FILENAME) . '-*';
            foreach (glob($derivatives) ?: array() as $derivative)
            {
                @unlink($derivative);
            }
        }
        $this->testImages = array();
    }

    /**
     * Removes every album this fixture created, then puts the album id counter
     * back: core's piwigo_history.category_id is a signed smallint (decision 0067).
     */
    public function destroyTestAlbums(): void
    {
        foreach (array_reverse($this->testAlbums) as $id)
        {
            $this->db->query('DELETE FROM piwigo_image_category WHERE category_id = ' . (int)$id);
            $this->db->query('DELETE FROM piwigo_user_access WHERE cat_id = ' . (int)$id);
            $this->db->query('DELETE FROM piwigo_categories WHERE id = ' . (int)$id);
        }
        $this->testAlbums = array();
        $this->db->query('ALTER TABLE piwigo_categories AUTO_INCREMENT = 1');
        $this->invalidateUserCache();
    }

    /** What this fixture created, for the E2E seed's later --restore process. */
    public function exportTestObjects(): array
    {
        return array('images' => $this->testImages, 'albums' => $this->testAlbums);
    }

    public function importTestObjects(array $objects): void
    {
        $this->testImages = array_merge($this->testImages, $objects['images'] ?? array());
        $this->testAlbums = array_merge($this->testAlbums, $objects['albums'] ?? array());
    }

    /** Fails naming the plugin when it is not active. */
    public function assertPluginActive(string $plugin = 'galleryrules'): void
    {
        $state = $this->db->scalar("SELECT state FROM piwigo_plugins WHERE id = '" . $this->db->escape($plugin) . "'");
        if ($state !== 'active')
        {
            throw new RuntimeException(
                'The ' . $plugin . ' plugin is not active (state: ' . var_export($state, true) . ').' . "\n" .
                'Activate it on Administration > Plugins before running the suites.'
            );
        }
    }
}
