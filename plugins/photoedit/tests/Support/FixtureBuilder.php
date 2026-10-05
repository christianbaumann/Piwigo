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

    /** The quality createMarkedJpeg() saves with: not core's or photoedit's default, so a kept quality shows. */
    public const JPEG_QUALITY = 85;

    /** seed.php --scenario=regions: a face in the left half, kept by cropping to it, and one in the right half, cut away. */
    public const REGION_KEPT = array('name' => 'Photoedit Kept', 'x' => 0.25, 'y' => 0.5, 'w' => 0.2, 'h' => 0.3);
    public const REGION_CUT = array('name' => 'Photoedit Cut', 'x' => 0.8, 'y' => 0.5, 'w' => 0.1, 'h' => 0.2);

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

    /** Fails naming the plugin when it is not active; every suite needs photoedit, some need persons too. */
    public function assertPluginActive(string $plugin = 'photoedit'): void
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

        return $this->registerMarkedImage($name, $file, 0);
    }

    /**
     * The marked photo as a JPEG at JPEG_QUALITY, carrying EXIF Orientation
     * $orientation. Its row gets the rotation code core itself derives from
     * that tag (pwg_image::get_rotation_angle()), as upload or i.php would.
     *
     * @param int $orientation EXIF Orientation, 1 to 8
     * @return array as createMarkedImage(), plus 'rotation'
     */
    public function createMarkedJpeg(int $orientation): array
    {
        $dir = PIWIGO_ROOT . self::TEST_IMAGE_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))
        {
            throw new RuntimeException("cannot create $dir");
        }

        $name = 'photoedit-test-' . bin2hex(random_bytes(8)) . '.jpg';
        $file = $dir . $name;
        $last = self::MARKER_SIZE - 1;

        self::run(sprintf(
            'convert -size %dx%d xc:%s -fill %s -draw %s -quality %d %s',
            self::MARKED_WIDTH, self::MARKED_HEIGHT,
            escapeshellarg(self::BACKGROUND_COLOUR), escapeshellarg(self::MARKER_COLOUR),
            escapeshellarg("rectangle 0,0 $last,$last"), self::JPEG_QUALITY, escapeshellarg($file)
        ));
        self::run(sprintf(
            'exiftool -overwrite_original -XMP-dc:Description=%s -Orientation#=%d %s',
            escapeshellarg(self::CAPTION), $orientation, escapeshellarg($file)
        ));
        if ((int)trim(self::run('identify -format "%Q" ' . escapeshellarg($file))) !== self::JPEG_QUALITY)
        {
            throw new RuntimeException('the generated JPEG does not have the quality asked for');
        }

        PiwigoRuntime::boot();
        include_once PIWIGO_ROOT . 'admin/include/image.class.php';
        $rotation = (int)pwg_image::get_rotation_code_from_angle(pwg_image::get_rotation_angle($file));
        if ($orientation !== 1 && $rotation === 0)
        {
            throw new RuntimeException("core reads no rotation from Orientation $orientation");
        }

        return $this->registerMarkedImage($name, $file, $rotation) + array('rotation' => $rotation);
    }

    /**
     * A small GIF, a type photoedit does not write.
     *
     * @return array as createMarkedImage()
     */
    public function createGif(): array
    {
        $dir = PIWIGO_ROOT . self::TEST_IMAGE_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))
        {
            throw new RuntimeException("cannot create $dir");
        }

        $name = 'photoedit-test-' . bin2hex(random_bytes(8)) . '.gif';
        $file = $dir . $name;
        self::run(sprintf('convert -size %dx%d xc:%s %s',
            self::MARKED_WIDTH, self::MARKED_HEIGHT, escapeshellarg(self::BACKGROUND_COLOUR), escapeshellarg($file)));

        return $this->registerMarkedImage($name, $file, 0);
    }

    /** Inserts the row for a generated MARKED_WIDTH x MARKED_HEIGHT photo and asserts it took. */
    private function registerMarkedImage(string $name, string $file, int $rotation): array
    {
        $md5 = md5_file($file);
        $dbPath = './' . self::TEST_IMAGE_DIR . $name;
        $this->db->query(
            'INSERT INTO piwigo_images (file, path, date_available, filesize, width, height, md5sum, coi, rotation) VALUES (' .
            "'" . $this->db->escape($name) . "', '" . $this->db->escape($dbPath) . "', NOW(), " .
            (int)floor(filesize($file) / 1024) . ', ' . self::MARKED_WIDTH . ', ' . self::MARKED_HEIGHT . ", '$md5', '" . self::COI . "', $rotation)"
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
            if ($this->db->scalar("SHOW TABLES LIKE 'piwigo_person_region'") !== null)
            {
                $this->db->query('DELETE FROM piwigo_person_region WHERE image_id = ' . $id);
            }

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
            $derivatives = PIWIGO_ROOT . '_data/i/' . substr(ltrim($image['db_path'], './'), 0, -strlen('.' . pathinfo($image['db_path'], PATHINFO_EXTENSION)));
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

    /**
     * Writes MWG person regions into a photo with a plain exiftool call - not
     * through plugins/persons, whose handling of them is what is under test.
     *
     * @param array $regions list of array(name, x, y, w, h), normalized, centre origin
     */
    public function writeRegions(array $image, array $regions): void
    {
        if (count($regions) === 0)
        {
            throw new RuntimeException('anti-vacuity: seeding no regions would make every assertion trivial');
        }

        $list = array();
        $names = array();
        foreach ($regions as $region)
        {
            $list[] = array(
                'Area' => array('X' => $region['x'], 'Y' => $region['y'], 'W' => $region['w'], 'H' => $region['h'], 'Unit' => 'normalized'),
                'Name' => $region['name'],
                'Type' => 'Face',
                );
            $names[$region['name']] = true;
        }

        $json = $image['file'] . '.seed.json';
        file_put_contents($json, json_encode(array(array(
            'RegionInfo' => array(
                'AppliedToDimensions' => array('W' => $image['width'], 'H' => $image['height'], 'Unit' => 'pixel'),
                'RegionList' => $list,
                ),
            'PersonInImage' => array_keys($names),
            ))));
        try
        {
            self::run('exiftool -q -overwrite_original -json=' . escapeshellarg($json) . ' ' . escapeshellarg($image['file']));
        }
        finally
        {
            @unlink($json);
        }
    }

    /** Removes the persons the persons index created for these names, and their mirrored tags. */
    public function destroyPersons(array $names): void
    {
        foreach ($names as $name)
        {
            $escaped = $this->db->escape($name);
            $tagId = $this->db->scalar("SELECT tag_id FROM piwigo_persons WHERE name = '$escaped'");
            if ($tagId !== null)
            {
                $this->db->query('DELETE FROM piwigo_image_tag WHERE tag_id = ' . (int)$tagId);
                $this->db->query('DELETE FROM piwigo_tags WHERE id = ' . (int)$tagId);
            }
            $personId = $this->db->scalar("SELECT id FROM piwigo_persons WHERE name = '$escaped'");
            if ($personId !== null)
            {
                $this->db->query('DELETE FROM piwigo_person_region WHERE person_id = ' . (int)$personId);
            }
            $this->db->query("DELETE FROM piwigo_persons WHERE name = '$escaped'");
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
