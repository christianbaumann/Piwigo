<?php
/**
 * Forces a known database state and asserts it took effect, so a test never
 * runs over a state it merely hoped for.
 *
 * Trimmed from plugins/persons/tests/Support/FixtureBuilder.php to what this
 * suite uses. Cleanup restores what was recorded, but no assertion depends on
 * cleanup having run.
 */
class FixtureBuilder
{
    private Db $db;

    /** Where createTestImage() puts its copies, relative to the gallery root. */
    private const TEST_IMAGE_DIR = 'upload/photoedit-test/';

    /** The piwigo_config row that marks an install as expendable; see create-test-users.php. */
    private const THROWAWAY_PARAM = 'photoedit_throwaway_install';

    /** The generated photo of createMarkedImage(): landscape, so a quarter turn shows. */
    public const MARKED_WIDTH = 300;
    public const MARKED_HEIGHT = 200;

    /** Side of the red square in its top-left corner, in pixels. */
    public const MARKER_SIZE = 30;
    public const MARKER_COLOUR = '#ff0000';
    public const BACKGROUND_COLOUR = '#808080';

    /** The XMP caption it carries, which an edit must not lose. */
    public const CAPTION = 'Photoedit test caption';

    /** Its centre of interest: l 0.2, t 0, r 0.4, b 0.36 in admin/picture_coi.php's a..z encoding. */
    public const COI = 'fakj';

    private array $testImages = array();
    private array $testAlbums = array();
    private array $savedConfig = array();

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
                "This install is not marked as a throwaway, and the photoedit suites rewrite image files.\n" .
                "Mark an install whose gallery you can afford to lose with:\n" .
                "  ddev exec php plugins/photoedit/tests/Support/create-test-users.php\n" .
                "Never mark a production install."
            );
        }
    }

    /** Fails naming the plugin when it is not active; every suite needs it on the page. */
    public function assertPluginActive(): void
    {
        $state = $this->db->scalar("SELECT state FROM piwigo_plugins WHERE id = 'photoedit'");
        if ($state !== 'active')
        {
            throw new RuntimeException(
                'The photoedit plugin is not active (state: ' . var_export($state, true) . ').' . "\n" .
                'Activate it on Administration > Plugins before running the suites.'
            );
        }
    }

    /**
     * A photo of this suite's own: a copy of the first PNG of the gallery under
     * upload/photoedit-test/, registered as an image row. Never a real scan:
     * the edits this suite drives rewrite the file in place.
     *
     * @return array id, db_path (as stored), file (absolute), width, height
     */
    public function createTestImage(): array
    {
        $source = (string)$this->db->scalar(
            "SELECT path FROM piwigo_images WHERE path LIKE '%.png' AND width IS NOT NULL ORDER BY id LIMIT 1"
        );
        $sourceFile = PIWIGO_ROOT . ltrim($source, './');
        if (!is_file($sourceFile))
        {
            throw new RuntimeException("no source photo to copy: $sourceFile");
        }

        $dir = PIWIGO_ROOT . self::TEST_IMAGE_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))
        {
            throw new RuntimeException("cannot create $dir");
        }

        $name = 'photoedit-test-' . bin2hex(random_bytes(8)) . '.png';
        if (!copy($sourceFile, $dir . $name))
        {
            throw new RuntimeException("cannot copy $sourceFile to $dir$name");
        }

        $dimensions = @getimagesize($dir . $name);
        if ($dimensions === false)
        {
            throw new RuntimeException('fixture image has no readable dimensions');
        }

        $dbPath = './' . self::TEST_IMAGE_DIR . $name;
        $this->db->query(
            'INSERT INTO piwigo_images (file, path, date_available, filesize, width, height) VALUES (' .
            "'" . $this->db->escape($name) . "', '" . $this->db->escape($dbPath) . "', NOW(), " .
            (int)ceil(filesize($dir . $name) / 1024) . ', ' . (int)$dimensions[0] . ', ' . (int)$dimensions[1] . ')'
        );
        $id = $this->db->insertId();
        if ($id <= 0)
        {
            throw new RuntimeException('fixture image row was not inserted');
        }

        $this->testImages[] = array(
            'id' => $id,
            'db_path' => $dbPath,
            'file' => $dir . $name,
            'width' => (int)$dimensions[0],
            'height' => (int)$dimensions[1],
            );

        return end($this->testImages);
    }

    /**
     * A photo generated for the write tests: MARKED_WIDTH x MARKED_HEIGHT grey,
     * a red MARKER_SIZE square in its top-left corner, an XMP caption, a centre
     * of interest and an md5sum in its row. A palette PNG on purpose: GD cannot
     * turn one by 180 degrees without the pipeline's truecolour conversion. Every precondition is read back
     * before the photo is handed out.
     *
     * @return array as createTestImage(), plus 'md5'
     */
    public function createMarkedImage(): array
    {
        $dir = PIWIGO_ROOT . self::TEST_IMAGE_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))
        {
            throw new RuntimeException("cannot create $dir");
        }

        $name = 'photoedit-test-' . bin2hex(random_bytes(8)) . '.png';
        $file = $dir . $name;
        $last = self::MARKER_SIZE - 1;

        self::run(sprintf(
            'convert -size %dx%d xc:%s -fill %s -draw %s %s',
            self::MARKED_WIDTH, self::MARKED_HEIGHT,
            escapeshellarg(self::BACKGROUND_COLOUR), escapeshellarg(self::MARKER_COLOUR),
            escapeshellarg("rectangle 0,0 $last,$last"), escapeshellarg('PNG8:' . $file)
        ));
        self::run(sprintf(
            'exiftool -overwrite_original -XMP-dc:Description=%s %s',
            escapeshellarg(self::CAPTION), escapeshellarg($file)
        ));

        $size = @getimagesize($file);
        if ($size === false || $size[0] !== self::MARKED_WIDTH || $size[1] !== self::MARKED_HEIGHT)
        {
            throw new RuntimeException('the generated photo does not have the size asked for');
        }
        if (strpos(self::run('convert ' . escapeshellarg($file) . ' xmp:-'), self::CAPTION) === false)
        {
            throw new RuntimeException('the generated photo does not carry its caption');
        }

        $md5 = md5_file($file);
        $dbPath = './' . self::TEST_IMAGE_DIR . $name;
        $this->db->query(
            'INSERT INTO piwigo_images (file, path, date_available, filesize, width, height, md5sum, coi) VALUES (' .
            "'" . $this->db->escape($name) . "', '" . $this->db->escape($dbPath) . "', NOW(), " .
            (int)floor(filesize($file) / 1024) . ', ' . self::MARKED_WIDTH . ', ' . self::MARKED_HEIGHT . ", '$md5', '" . self::COI . "')"
        );
        $id = $this->db->insertId();
        if ($id <= 0 || $this->db->scalar("SELECT coi FROM piwigo_images WHERE id = $id") !== self::COI)
        {
            throw new RuntimeException('fixture image row was not inserted');
        }

        $this->testImages[] = array(
            'id' => $id,
            'db_path' => $dbPath,
            'file' => $file,
            'width' => self::MARKED_WIDTH,
            'height' => self::MARKED_HEIGHT,
            'md5' => $md5,
            );

        return end($this->testImages);
    }

    /** One image row's columns, as stored. */
    public function imageRow(int $id): array
    {
        $row = $this->db->query("SELECT * FROM piwigo_images WHERE id = $id")->fetch_assoc();
        if ($row === null)
        {
            throw new RuntimeException("no image row $id");
        }
        return $row;
    }

    /**
     * Sets or clears one piwigo_config row, asserting it took effect. The
     * previous value is put back by restoreConfig().
     */
    public function setConfig(string $param, ?string $value): void
    {
        $escaped = $this->db->escape($param);
        if (!array_key_exists($param, $this->savedConfig))
        {
            $this->savedConfig[$param] = $this->db->scalar("SELECT value FROM piwigo_config WHERE param = '$escaped'");
        }

        if ($value === null)
        {
            $this->db->query("DELETE FROM piwigo_config WHERE param = '$escaped'");
        }
        else
        {
            $this->db->query(
                "INSERT INTO piwigo_config (param, value) VALUES ('$escaped', '" . $this->db->escape($value) . "')
                 ON DUPLICATE KEY UPDATE value = VALUES(value)"
            );
        }

        if ($this->db->scalar("SELECT value FROM piwigo_config WHERE param = '$escaped'") !== $value)
        {
            throw new RuntimeException("config $param was not set");
        }
    }

    public function restoreConfig(): void
    {
        foreach ($this->savedConfig as $param => $value)
        {
            $escaped = $this->db->escape($param);
            $this->db->query("DELETE FROM piwigo_config WHERE param = '$escaped'");
            if ($value !== null)
            {
                $this->db->query(
                    "INSERT INTO piwigo_config (param, value) VALUES ('$escaped', '" . $this->db->escape($value) . "')"
                );
            }
        }
        $this->savedConfig = array();
    }

    /**
     * Runs a shell command, failing loudly on a non-zero exit.
     *
     * @return string its output
     */
    public static function run(string $command): string
    {
        $output = array();
        $status = 1;
        exec($command . ' 2>&1', $output, $status);
        if ($status !== 0)
        {
            throw new RuntimeException("`$command` exited with $status: " . implode("\n", $output));
        }
        return implode("\n", $output);
    }

    /** A public top-level album of this suite's own. */
    public function createTestAlbum(string $name): int
    {
        $this->db->query(
            "INSERT INTO `piwigo_categories` (name, id_uppercat, uppercats, rank, global_rank, status, visible) " .
            "VALUES ('" . $this->db->escape($name) . "', NULL, '', 1, '1', 'public', 'true')"
        );
        $id = $this->db->insertId();
        if ($id <= 0)
        {
            throw new RuntimeException('fixture album row was not inserted');
        }

        $this->db->query("UPDATE `piwigo_categories` SET uppercats = '$id', global_rank = '$id' WHERE id = $id");
        $this->testAlbums[] = $id;

        return $id;
    }

    /** Puts one photo in one album, asserting the link took effect. */
    public function attachImage(int $imageId, int $catId): void
    {
        $this->db->query("INSERT INTO `piwigo_image_category` (image_id, category_id) VALUES ($imageId, $catId)");

        $linked = (int)$this->db->scalar(
            "SELECT COUNT(*) FROM `piwigo_image_category` WHERE image_id = $imageId AND category_id = $catId"
        );
        if ($linked !== 1)
        {
            throw new RuntimeException("photo $imageId was not linked to album $catId");
        }
    }

    /**
     * Discards every user's cached permission summary, so an album created after
     * it was computed is visible to the accounts that may see it.
     */
    public function invalidateUserCache(): void
    {
        $this->db->query("UPDATE `piwigo_user_cache` SET need_update = 'true'");
    }

    /** What this fixture created, for the E2E seed's separate restore process. */
    public function exportTestObjects(): array
    {
        return array('images' => $this->testImages, 'albums' => $this->testAlbums);
    }

    public function importTestObjects(array $objects): void
    {
        $this->testImages = $objects['images'] ?? array();
        $this->testAlbums = $objects['albums'] ?? array();
    }

    /** Removes every photo this fixture created, its file and anything left beside it. */
    public function destroyTestImages(): void
    {
        foreach ($this->testImages as $image)
        {
            $id = (int)$image['id'];
            $this->db->query('DELETE FROM piwigo_images WHERE id = ' . $id);
            $this->db->query('DELETE FROM piwigo_image_category WHERE image_id = ' . $id);
            $this->db->query('DELETE FROM piwigo_image_tag WHERE image_id = ' . $id);

            foreach (glob($image['file'] . '*') as $leftover)
            {
                @unlink($leftover);
            }

            @unlink(PIWIGO_ROOT . '_data/photoedit/locks/' . sha1($image['db_path']) . '.lock');

            // The backups an edit made before it wrote the file.
            foreach (glob(PIWIGO_ROOT . '_data/photoedit/originals/' . $id . '-*') as $backup)
            {
                @unlink($backup);
            }

            // The derivatives i.php generated while a spec looked at the photo.
            $derivatives = PIWIGO_ROOT . '_data/i/' . substr(ltrim($image['db_path'], './'), 0, -strlen('.png'));
            foreach (glob($derivatives . '-*') as $derivative)
            {
                @unlink($derivative);
            }
        }
        $this->forgetVersions(array_map(fn ($image) => (int)$image['id'], $this->testImages));
        $this->testImages = array();
    }

    /** Drops these photos from the photoedit_versions row an edit writes; the rows were deleted directly. */
    private function forgetVersions(array $ids): void
    {
        $versions = json_decode((string)$this->db->scalar(
            "SELECT value FROM piwigo_config WHERE param = 'photoedit_versions'"
        ), true);
        if (!is_array($versions))
        {
            return;
        }

        $kept = array_diff_key($versions, array_flip($ids));
        if (count($kept) != count($versions))
        {
            $this->db->query(
                "UPDATE piwigo_config SET value = '" . $this->db->escape(json_encode((object)$kept)) .
                "' WHERE param = 'photoedit_versions'"
            );
        }
    }

    /** Removes every album this fixture created. */
    public function destroyTestAlbums(): void
    {
        foreach ($this->testAlbums as $id)
        {
            $this->db->query('DELETE FROM `piwigo_image_category` WHERE category_id = ' . (int)$id);
            $this->db->query('DELETE FROM `piwigo_categories` WHERE id = ' . (int)$id);
        }
        $this->testAlbums = array();
    }
}
