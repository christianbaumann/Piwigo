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
    /**
     * The schema, read from the production definition rather than typed again:
     * a second copy here would rot the day a column is added on one side only.
     */
    public static function albumColumns(): array
    {
        return array_keys(provenance_album_columns());
    }

    public static function imageColumns(): array
    {
        return array_keys(provenance_image_columns());
    }

    private Db $db;

    /** Where createTestImage() puts its copies, relative to the gallery root. */
    private const TEST_IMAGE_DIR = 'upload/provenance-test/';

    /** table => (row id => (column => value)) recorded before this fixture wrote */
    private array $original = array();

    /** photos this fixture created, to be removed again in teardown */
    private array $testImages = array();

    /** albums this fixture created, to be removed again in teardown */
    private array $testAlbums = array();

    public function __construct(Db $db)
    {
        $this->db = $db;
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
     * Snapshots every provenance value in the install.
     *
     * Uninstalling the plugin drops the columns, taking the data with them, so a
     * test that exercises the lifecycle has to be able to put the values back.
     */
    public function recordAllProvenance(): void
    {
        $this->original = array(
            'piwigo_categories' => $this->readAll('piwigo_categories', self::albumColumns()),
            'piwigo_images'     => $this->readAll('piwigo_images', self::imageColumns()),
            );
    }

    /**
     * The recorded original state, for a process that will not live long enough
     * to restore it itself.
     *
     * The E2E suite seeds from a short-lived CLI process and restores from
     * another one, so what recordAllProvenance() remembered has to survive as
     * data on disk between the two.
     */
    public function exportState(): array
    {
        return $this->original;
    }

    public function importState(array $state): void
    {
        $this->original = $state;
    }

    /** An album that exists, so a fixture refers to something real. */
    public function anyAlbumId(): int
    {
        $id = $this->db->scalar('SELECT MIN(id) FROM piwigo_categories');
        if ($id === null)
        {
            throw new RuntimeException('this install has no album to attach provenance to');
        }
        return (int)$id;
    }

    /**
     * Forces one album's four provenance columns and asserts the write took
     * effect, so a spec never runs over a state it merely hoped for.
     *
     * @param array $values album column => value, '' or null to clear
     * @return array the values now in the database
     */
    public function albumProvenance(int $catId, array $values): array
    {
        $assignments = array();
        foreach (self::albumColumns() as $column)
        {
            $value = $values[$column] ?? null;
            $assignments[] = "`$column` = " .
                ($value === null || $value === '' ? 'NULL' : "'" . $this->db->escape((string)$value) . "'");
        }

        $this->db->query(
            'UPDATE `piwigo_categories` SET ' . implode(', ', $assignments) . ' WHERE id = ' . $catId
        );

        $actual = $this->readAlbumProvenance($catId);
        foreach (self::albumColumns() as $column)
        {
            $wanted = ($values[$column] ?? null) === '' ? null : ($values[$column] ?? null);
            if ($actual[$column] !== $wanted)
            {
                throw new RuntimeException(
                    "fixture did not take effect: album $catId has $column = " .
                    var_export($actual[$column], true) . ', wanted ' . var_export($wanted, true)
                );
            }
        }

        return $actual;
    }

    /**
     * The photos of one album, asserting each is in exactly one album.
     *
     * The whole copy-down design rests on a photo belonging to a single album
     * (the plan's 1:1 assumption). A fixture that merely hoped for it would make
     * every apply assertion below meaningless the day a photo gains a second
     * album, so the assumption is forced open here instead.
     *
     * @return array photo ids, ascending
     */
    public function photoIdsInAlbum(int $catId): array
    {
        $result = $this->db->query(
            'SELECT ic.image_id, (SELECT COUNT(*) FROM `piwigo_image_category` a WHERE a.image_id = ic.image_id) AS albums
               FROM `piwigo_image_category` ic
              WHERE ic.category_id = ' . $catId . '
              ORDER BY ic.image_id'
        );

        $ids = array();
        while ($row = $result->fetch_assoc())
        {
            if ((int)$row['albums'] !== 1)
            {
                throw new RuntimeException(
                    'photo ' . $row['image_id'] . " is in {$row['albums']} albums; the copy-down fixture assumes exactly one"
                );
            }
            $ids[] = (int)$row['image_id'];
        }

        if (count($ids) === 0)
        {
            throw new RuntimeException("album $catId holds no photo to apply provenance to");
        }

        return $ids;
    }

    /**
     * Forces one photo's five provenance columns and asserts the write landed.
     *
     * @param array $values image column => value, '' or null to clear
     */
    public function imageProvenance(int $imageId, array $values): array
    {
        $assignments = array();
        foreach (self::imageColumns() as $column)
        {
            $value = $values[$column] ?? null;
            $assignments[] = "`$column` = " .
                ($value === null || $value === '' ? 'NULL' : "'" . $this->db->escape((string)$value) . "'");
        }

        $this->db->query(
            'UPDATE `piwigo_images` SET ' . implode(', ', $assignments) . ' WHERE id = ' . $imageId
        );

        $actual = $this->readImageProvenance($imageId);
        foreach (self::imageColumns() as $column)
        {
            $wanted = ($values[$column] ?? null) === '' ? null : ($values[$column] ?? null);
            if ($actual[$column] !== $wanted)
            {
                throw new RuntimeException(
                    "fixture did not take effect: photo $imageId has $column = " .
                    var_export($actual[$column], true) . ', wanted ' . var_export($wanted, true)
                );
            }
        }

        return $actual;
    }

    public function readImageProvenance(int $imageId): array
    {
        $result = $this->db->query(
            'SELECT `' . implode('`, `', self::imageColumns()) . '` FROM `piwigo_images` WHERE id = ' . $imageId
        );
        $row = $result->fetch_assoc();
        if ($row === null)
        {
            throw new RuntimeException("no photo with id $imageId");
        }
        return $row;
    }

    /** Clears every provenance column of the given photos. */
    public function clearImageProvenance(array $imageIds): void
    {
        if (count($imageIds) === 0)
        {
            return;
        }
        $nulls = array_map(fn($c) => "`$c` = NULL", self::imageColumns());
        $this->db->query(
            'UPDATE `piwigo_images` SET ' . implode(', ', $nulls) .
            ' WHERE id IN (' . implode(',', array_map('intval', $imageIds)) . ')'
        );
    }

    public function readAlbumProvenance(int $catId): array
    {
        $result = $this->db->query(
            'SELECT `' . implode('`, `', self::albumColumns()) . '` FROM `piwigo_categories` WHERE id = ' . $catId
        );
        $row = $result->fetch_assoc();
        if ($row === null)
        {
            throw new RuntimeException("no album with id $catId");
        }
        return $row;
    }

    /**
     * A photo of this suite's own, so the write-back never touches a real scan.
     *
     * The file is a copy of an existing photo - a real PNG with real pixels, so
     * an exiftool write behaves as it does in production - placed under
     * upload/provenance-test/ and registered as an image row. destroyTestImages()
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

        $name = 'provenance-test-' . bin2hex(random_bytes(8)) . '.png';
        if (!copy($sourceFile, $dir . $name))
        {
            throw new RuntimeException("cannot copy $sourceFile to $dir$name");
        }

        clearstatcache(true, $dir . $name);
        $size = filesize($dir . $name);
        if ($size < 1)
        {
            throw new RuntimeException('fixture image is empty; every write-back assertion would be vacuous');
        }

        $dbPath = './' . self::TEST_IMAGE_DIR . $name;
        $this->db->query(
            'INSERT INTO piwigo_images (file, path, date_available, filesize) VALUES (' .
            "'" . $this->db->escape($name) . "', '" . $this->db->escape($dbPath) . "', NOW(), " . (int)ceil($size / 1024) . ')'
        );
        $id = $this->db->insertId();
        if ($id <= 0)
        {
            throw new RuntimeException('fixture image row was not inserted');
        }

        $this->testImages[] = array('id' => $id, 'db_path' => $dbPath, 'file' => $dir . $name);

        return end($this->testImages);
    }

    /**
     * An album of this suite's own, holding nothing but fixture photos.
     *
     * The write-back operates on every photo of the album it is started from, so
     * a browser-level test of that button must not be pointed at an album
     * holding real scans. This is the album it is pointed at instead.
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

    /**
     * What this fixture created, for a process that will not live long enough to
     * remove it itself.
     *
     * The E2E suite seeds from one short-lived CLI process and cleans up from
     * another, exactly as it does for the recorded provenance state.
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

    /** Removes every photo this fixture created, its file and exiftool's leftovers. */
    public function destroyTestImages(): void
    {
        foreach ($this->testImages as $image)
        {
            $this->db->query('DELETE FROM piwigo_images WHERE id = ' . (int)$image['id']);
            $this->db->query('DELETE FROM piwigo_image_category WHERE image_id = ' . (int)$image['id']);

            foreach (glob($image['file'] . '*') as $leftover)
            {
                @unlink($leftover);
            }
            @unlink(provenance_lock_path($image['db_path']));
        }
        $this->testImages = array();
    }

    public function restore(): void
    {
        foreach ($this->original as $table => $rows)
        {
            foreach ($rows as $id => $values)
            {
                $assignments = array();
                foreach ($values as $column => $value)
                {
                    if (!$this->columnExists($table, $column))
                    {
                        continue;
                    }
                    $assignments[] = "`$column` = " .
                        ($value === null ? 'NULL' : "'" . $this->db->escape((string)$value) . "'");
                }
                if (count($assignments) > 0)
                {
                    $this->db->query("UPDATE `$table` SET " . implode(', ', $assignments) . ' WHERE id = ' . (int)$id);
                }
            }
        }
        $this->original = array();
    }

    /** Only rows that carry at least one non-NULL provenance value are worth restoring. */
    private function readAll(string $table, array $columns): array
    {
        $present = array();
        foreach ($columns as $column)
        {
            if ($this->columnExists($table, $column))
            {
                $present[] = $column;
            }
        }
        if (count($present) === 0)
        {
            return array();
        }

        $notNull = implode(' OR ', array_map(fn($c) => "`$c` IS NOT NULL", $present));
        $result = $this->db->query(
            'SELECT id, `' . implode('`, `', $present) . "` FROM `$table` WHERE $notNull"
        );

        $rows = array();
        while ($row = $result->fetch_assoc())
        {
            $id = (int)$row['id'];
            unset($row['id']);
            $rows[$id] = $row;
        }
        return $rows;
    }
}
