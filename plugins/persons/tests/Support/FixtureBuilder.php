<?php
/**
 * Forces a known database state and asserts it took effect, so a test never
 * runs over a state it merely hoped for.
 *
 * Cleanup restores what was recorded, but no assertion depends on cleanup
 * having run: a suite that needed it to pass would destroy its own failure
 * evidence.
 */
class FixtureBuilder
{
    private Db $db;

    /** Where createTestImage() puts its copies, relative to the gallery root. */
    private const TEST_IMAGE_DIR = 'upload/persons-test/';

    /** photos this fixture created, to be removed again in teardown */
    private array $testImages = array();

    /** albums this fixture created, to be removed again in teardown */
    private array $testAlbums = array();

    /**
     * The piwigo_config row that marks an install as expendable.
     *
     * Written by create-test-users.php, which is already documented as never
     * safe to point at production. Nothing else writes it, so a real install
     * cannot acquire it by accident.
     */
    private const THROWAWAY_PARAM = 'persons_throwaway_install';

    public function __construct(Db $db)
    {
        $this->db = $db;
        self::assertThrowawayInstall($db);
    }

    /**
     * Refuses to build a fixture against an install that has not been declared
     * expendable.
     *
     * This suite creates and deletes albums and photos and rewrites the
     * metadata of image files in place. On 2026-08-29 an install holding real
     * scans lost every photo row and every original file during a plugin test
     * run, and the deleting code path was never identified - so the guard is
     * unconditional rather than narrowed to a suspected path: the suites run
     * only where losing the content costs nothing.
     *
     * Fails closed. An install without the marker gets a message naming the
     * script that sets it, never a run.
     */
    public static function assertThrowawayInstall(Db $db): void
    {
        $marker = $db->scalar(
            "SELECT value FROM piwigo_config WHERE param = '" . $db->escape(self::THROWAWAY_PARAM) . "'"
        );

        if ((string)$marker !== '1')
        {
            throw new RuntimeException(
                "This install is not marked as a throwaway, and the persons suites destroy content.\n" .
                "They create and delete albums and photos and rewrite image files in place.\n" .
                "Run them only against an install whose gallery you can afford to lose, and mark it with:\n" .
                "  ddev exec php plugins/persons/tests/Support/create-test-users.php\n" .
                "Never mark a production install."
            );
        }
    }

    public function columnExists(string $table, string $column): bool
    {
        $result = $this->db->query("SHOW COLUMNS FROM `$table` LIKE '" . $this->db->escape($column) . "'");
        return $result->num_rows > 0;
    }

    public function tableExists(string $table): bool
    {
        $result = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($table) . "'");
        return $result->num_rows > 0;
    }

    /**
     * A photo of this suite's own: a copy of a real gallery image, so an
     * exiftool write behaves as it does in production - placed under
     * upload/persons-test/ and registered as an image row. destroyTestImages()
     * removes the row, the file, and anything exiftool left beside it.
     *
     * @return array id, db_path (as stored) and file (absolute)
     */
    public function createTestImage(): array
    {
        $source = (string)$this->db->scalar(
            'SELECT path FROM piwigo_images WHERE path LIKE \'%.png\' ORDER BY id LIMIT 1'
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

        $name = 'persons-test-' . bin2hex(random_bytes(8)) . '.png';
        if (!copy($sourceFile, $dir . $name))
        {
            throw new RuntimeException("cannot copy $sourceFile to $dir$name");
        }

        clearstatcache(true, $dir . $name);
        $size = filesize($dir . $name);
        if ($size < 1)
        {
            throw new RuntimeException('fixture image is empty; every region assertion would be vacuous');
        }

        $dimensions = @getimagesize($dir . $name);
        if ($dimensions === false)
        {
            throw new RuntimeException('fixture image has no readable dimensions; region math needs them');
        }

        $dbPath = './' . self::TEST_IMAGE_DIR . $name;
        $this->db->query(
            'INSERT INTO piwigo_images (file, path, date_available, filesize, width, height) VALUES (' .
            "'" . $this->db->escape($name) . "', '" . $this->db->escape($dbPath) . "', NOW(), " .
            (int)ceil($size / 1024) . ', ' . (int)$dimensions[0] . ', ' . (int)$dimensions[1] . ')'
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
     * Takes ownership of a photo somebody else created, so teardown removes it.
     */
    public function adoptImage(int $imageId): void
    {
        $dbPath = $this->db->scalar('SELECT path FROM piwigo_images WHERE id = ' . $imageId);
        if ($dbPath === null)
        {
            throw new RuntimeException("no photo with id $imageId to adopt");
        }

        $this->testImages[] = array(
            'id' => $imageId,
            'db_path' => (string)$dbPath,
            'file' => PIWIGO_ROOT . ltrim((string)$dbPath, './'),
            );
    }

    /**
     * An album of this suite's own, holding nothing but fixture photos.
     *
     * A rescan operates on every photo of the album it is started from, so a
     * browser-level test of that button must not be pointed at an album holding
     * real scans. This is the album it is pointed at instead.
     *
     * @return int the new album's id
     */
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

        // A top-level album's uppercats and global_rank are its own id; Piwigo
        // computes them after the insert and so does this.
        $this->db->query("UPDATE `piwigo_categories` SET uppercats = '$id', global_rank = '$id' WHERE id = $id");

        $actual = (string)$this->db->scalar("SELECT uppercats FROM `piwigo_categories` WHERE id = $id");
        if ($actual !== (string)$id)
        {
            throw new RuntimeException("fixture album $id did not take its uppercats: '$actual'");
        }

        $this->testAlbums[] = $id;

        return $id;
    }

    /** Puts one photo in one album, asserting the link took effect. */
    public function attachImage(int $imageId, int $catId): void
    {
        $this->db->query(
            "INSERT INTO `piwigo_image_category` (image_id, category_id) VALUES ($imageId, $catId)"
        );

        $linked = (int)$this->db->scalar(
            "SELECT COUNT(*) FROM `piwigo_image_category` WHERE image_id = $imageId AND category_id = $catId"
        );
        if ($linked !== 1)
        {
            throw new RuntimeException("photo $imageId was not linked to album $catId");
        }
    }

    /**
     * What this fixture created, for a process that will not live long enough to
     * remove it itself.
     *
     * The E2E suite seeds from one short-lived CLI process and cleans up from
     * another, so what was created has to survive as data on disk between them.
     */
    public function exportTestObjects(): array
    {
        return array('images' => $this->testImages, 'albums' => $this->testAlbums);
    }

    public function importTestObjects(array $objects): void
    {
        $this->testImages = $objects['images'] ?? array();
        $this->testAlbums = $objects['albums'] ?? array();
    }

    /**
     * Writes MWG regions into a fixture photo with a plain exiftool call.
     *
     * Deliberately not the plugin's own writer: a test that seeded through the
     * code under test could only prove the writer agrees with itself. This is
     * the independent producer, the way WriteBackTest uses an independent
     * reader.
     *
     * @param array $image a row from createTestImage()
     * @param array $regions list of array(name, x, y, w, h, type)
     * @param int|null $appliedW null omits AppliedToDimensions entirely, which
     *   is what digiKam writes (KDE bug 429219)
     * @param int|null $appliedH
     */
    public function writeRegionsWithExiftool(array $image, array $regions, ?int $appliedW, ?int $appliedH): void
    {
        if (count($regions) === 0)
        {
            throw new RuntimeException('anti-vacuity: seeding no regions would make every assertion trivial');
        }

        $list = array();
        foreach ($regions as $region)
        {
            $list[] = array(
                'Area' => array(
                    'X' => $region['x'],
                    'Y' => $region['y'],
                    'W' => $region['w'],
                    'H' => $region['h'],
                    'Unit' => 'normalized',
                ),
                'Name' => $region['name'],
                'Type' => $region['type'] ?? 'Face',
            );
        }

        $names = array();
        foreach ($regions as $region)
        {
            if (($region['type'] ?? 'Face') === 'Face')
            {
                $names[$region['name']] = true;
            }
        }

        $info = array('RegionList' => $list);
        if ($appliedW !== null && $appliedH !== null)
        {
            $info = array('AppliedToDimensions' => array('W' => $appliedW, 'H' => $appliedH, 'Unit' => 'pixel'))
                + $info;
        }

        $payload = array(array(
            'RegionInfo' => $info,
            'PersonInImage' => array_keys($names),
        ));

        $jsonFile = $image['file'] . '.seed.json';
        file_put_contents($jsonFile, json_encode($payload, JSON_UNESCAPED_UNICODE));

        $output = array();
        $status = 1;
        exec(
            'exiftool -overwrite_original -charset filename=UTF8 -json=' . escapeshellarg($jsonFile)
            . ' ' . escapeshellarg($image['file']) . ' 2>&1',
            $output,
            $status
        );
        @unlink($jsonFile);

        if ($status !== 0)
        {
            throw new RuntimeException('seeding regions failed: ' . implode(' ', $output));
        }
    }

    /**
     * Removes the person rows a test created, and the tags mirrored from them.
     *
     * By name rather than by id: the indexer creates them, so a test cannot know
     * the ids in advance without reading them back from the code under test.
     *
     * @param array $names
     */
    public function destroyPersons(array $names): void
    {
        foreach ($names as $name)
        {
            $escaped = $this->db->escape($name);

            if ($this->tableExists('piwigo_persons'))
            {
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

            $this->db->query("DELETE FROM piwigo_tags WHERE name = '$escaped'");
        }
    }

    /** Removes every photo this fixture created, its file and exiftool's leftovers. */
    public function destroyTestImages(): void
    {
        foreach ($this->testImages as $image)
        {
            $id = (int)$image['id'];
            $this->db->query('DELETE FROM piwigo_images WHERE id = ' . $id);
            $this->db->query('DELETE FROM piwigo_image_category WHERE image_id = ' . $id);
            $this->db->query('DELETE FROM piwigo_image_tag WHERE image_id = ' . $id);

            if ($this->tableExists('piwigo_person_region'))
            {
                $this->db->query('DELETE FROM piwigo_person_region WHERE image_id = ' . $id);
            }

            foreach (glob($image['file'] . '*') as $leftover)
            {
                @unlink($leftover);
            }
            @unlink(persons_lock_path($image['db_path']));
        }
        $this->testImages = array();
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
