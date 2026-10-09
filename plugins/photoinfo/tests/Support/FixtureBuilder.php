<?php
/**
 * Forces a known database state and asserts it took effect, so a test never
 * runs over a state it merely hoped for.
 *
 * Adapted from plugins/photoedit/tests/Support/FixtureBuilder.php, trimmed to
 * what this suite uses. Cleanup removes what was recorded, but no assertion
 * depends on cleanup having run.
 */
class FixtureBuilder
{
    private Db $db;

    /** Where createTestImage() puts its copies, relative to the gallery root. */
    private const TEST_IMAGE_DIR = 'upload/photoinfo-test/';

    /** The piwigo_config row that marks an install as expendable; see create-test-users.php. */
    private const THROWAWAY_PARAM = 'photoinfo_throwaway_install';

    /** The provenance plugin's per-image lock, held while it writes a file. */
    private const PROVENANCE_LOCK_DIR = '_data/provenance/locks/';
    /** The seeded Freitext group's look, for an install the seed has not reached. */
    private const FREITEXT_COLOR = '#e4e6e3';
    private const FREITEXT_EMOJI = '270D FE0F';

    /** The photo columns setProvenance() may write. */
    public const PROVENANCE_COLUMNS = array(
        'provenance_physical_album',
        'provenance_owner',
        'provenance_scanned_on',
        'provenance_album_note',
        'provenance_note',
        );

    /** The tags readFileTags() asks for, mapped to the key exiftool's JSON names each one with. */
    public const FILE_TAGS = array(
        'XMP-dc:Description' => 'Description',
        'IPTC:Caption-Abstract' => 'Caption-Abstract',
        'EXIF:ImageDescription' => 'ImageDescription',
        'XMP-pwginfo:Info' => 'Info',
        );

    /** The tags readKeywords() asks for, mapped to the key exiftool's JSON names each one with. */
    public const KEYWORD_TAGS = array(
        'XMP-dc:Subject' => 'Subject',
        'IPTC:Keywords' => 'Keywords',
        'XMP-lr:HierarchicalSubject' => 'HierarchicalSubject',
        'XMP-pwginfo:TagsWritten' => 'TagsWritten',
        );

    private array $testImages = array();
    private array $testAlbums = array();
    private array $testTags = array();
    private array $testGroups = array();
    private array $testPersons = array();
    private ?array $hiddenFreitext = null;
    private array $physicalDirs = array();

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
                "This install is not marked as a throwaway, and the photoinfo suites rewrite image files.\n" .
                "Mark an install whose gallery you can afford to lose with:\n" .
                "  ddev exec php plugins/photoinfo/tests/Support/create-test-users.php\n" .
                "Never mark a production install."
            );
        }
    }

    /** Fails naming the plugin when it is not active; photoinfo needs provenance too. */
    public function assertPluginActive(string $plugin = 'photoinfo'): void
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
     * upload/photoinfo-test/, registered as an image row. Never a real scan:
     * a save writes the caption into the file in place. The gallery holds PNGs
     * only, so the copy is a PNG.
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

        $name = 'photoinfo-test-' . bin2hex(random_bytes(8)) . '.png';
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
     * A photo of this suite's own in another format than PNG: the copy of
     * createTestImage(), converted with ImageMagick, its row repointed at the
     * converted file. PHP reads EXIF from a JPEG but not from a PNG or a HEIC,
     * so a test of a metadata sync needs one of each.
     *
     * @param string $extension e.g. 'jpg', 'heic'
     * @return array id, db_path (as stored), file (absolute), width, height
     */
    public function createTestImageAs(string $extension): array
    {
        $png = $this->createTestImage();

        $file = substr($png['file'], 0, -strlen('png')) . $extension;
        self::run('convert ' . escapeshellarg($png['file']) . ' ' . escapeshellarg($file) . ' 2>&1');
        clearstatcache(true, $file);
        if (!is_file($file) or filesize($file) < 1)
        {
            throw new RuntimeException("ImageMagick convert did not produce $file");
        }
        @unlink($png['file']);

        $dbPath = substr($png['db_path'], 0, -strlen('png')) . $extension;
        $this->db->query("UPDATE piwigo_images SET file = '" . $this->db->escape(basename($file)) .
            "', path = '" . $this->db->escape($dbPath) . "' WHERE id = " . (int)$png['id']);

        array_pop($this->testImages);
        $this->testImages[] = array_merge($png, array('db_path' => $dbPath, 'file' => $file));

        return end($this->testImages);
    }

    /**
     * Writes camera metadata (Make, Model) and, unless null, a DateTimeOriginal
     * into a fixture file, or only the DateTimeOriginal when $camera is false,
     * as a scanner would. Asserts the tags are there.
     */
    public static function writeExifDate(string $file, ?string $dateTimeOriginal, bool $camera): void
    {
        $args = $camera ? array('-EXIF:Make=PiwigoTestCam', '-EXIF:Model=T1') : array();
        if ($dateTimeOriginal !== null)
        {
            $args[] = '-EXIF:DateTimeOriginal=' . $dateTimeOriginal;
        }
        if (count($args) === 0)
        {
            throw new RuntimeException('anti-vacuity: writing no tag at all leaves the fixture as it was');
        }
        self::run('exiftool -q -overwrite_original ' . implode(' ', array_map('escapeshellarg', $args)) . ' ' . escapeshellarg($file));

        $read = json_decode(self::run('exiftool -j -EXIF:Make -EXIF:DateTimeOriginal ' . escapeshellarg($file)), true);
        if (($read[0]['Make'] ?? null) !== ($camera ? 'PiwigoTestCam' : null)
            or ($read[0]['DateTimeOriginal'] ?? null) !== $dateTimeOriginal)
        {
            throw new RuntimeException("the EXIF date tags were not written into $file as intended");
        }
    }

    /**
     * Gives a fixture photo a representative file, as core's upload does for a
     * HEIC (upload_file_heic()): a JPEG under pwg_representative/ beside it,
     * named after it, with representative_ext set on the row.
     *
     * @return string the representative's absolute path
     */
    public function addRepresentative(array $image): string
    {
        $dir = dirname($image['file']) . '/pwg_representative/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))
        {
            throw new RuntimeException("cannot create $dir");
        }
        $file = $dir . pathinfo($image['file'], PATHINFO_FILENAME) . '.jpg';
        self::run('convert ' . escapeshellarg($image['file']) . ' ' . escapeshellarg($file) . ' 2>&1');

        $this->db->query("UPDATE piwigo_images SET representative_ext = 'jpg' WHERE id = " . (int)$image['id']);
        if ($this->imageRow($image['id'])['representative_ext'] !== 'jpg' or !is_file($file))
        {
            throw new RuntimeException("photo {$image['id']} did not get its representative");
        }

        return $file;
    }

    /**
     * A photo of this suite's own in a physical album - a directory under
     * galleries/ with its album row - which is what the filesystem sync
     * (admin/site_update.php) reads. The file is a copy, never a real scan.
     *
     * @param string $extension 'png', or a format createTestImageAs() converts to
     * @return array id, db_path, file, width, height, album (its id)
     */
    public function createPhysicalTestImage(string $extension): array
    {
        $image = $extension === 'png' ? $this->createTestImage() : $this->createTestImageAs($extension);

        $siteId = (int)$this->db->scalar("SELECT id FROM piwigo_sites WHERE galleries_url = './galleries/'");
        if ($siteId <= 0)
        {
            throw new RuntimeException('this install has no local site row to sync against');
        }

        $dirName = 'photoinfo-test-' . bin2hex(random_bytes(4));
        $dir = PIWIGO_ROOT . 'galleries/' . $dirName . '/';
        if (!mkdir($dir, 0755))
        {
            throw new RuntimeException("cannot create $dir");
        }
        $this->physicalDirs[] = $dir;

        $file = $dir . basename($image['file']);
        if (!rename($image['file'], $file))
        {
            throw new RuntimeException("cannot move the fixture photo into $dir");
        }

        $albumId = $this->createTestAlbum($dirName);
        $this->db->query("UPDATE piwigo_categories SET dir = '" . $this->db->escape($dirName) . "', site_id = $siteId WHERE id = $albumId");
        $this->attachImage($image['id'], $albumId);

        $dbPath = './galleries/' . $dirName . '/' . basename($file);
        $this->db->query("UPDATE piwigo_images SET path = '" . $this->db->escape($dbPath) .
            "', storage_category_id = $albumId WHERE id = " . (int)$image['id']);
        $row = $this->imageRow($image['id']);
        if ($row['path'] !== $dbPath or (int)$row['storage_category_id'] !== $albumId)
        {
            throw new RuntimeException("photo {$image['id']} was not moved into album $albumId");
        }

        array_pop($this->testImages);
        $this->testImages[] = array_merge($image, array('db_path' => $dbPath, 'file' => $file, 'album' => $albumId));

        return end($this->testImages);
    }

    /** Sets or clears (null) a photo's images.comment, asserting it took effect. */
    public function setComment(int $imageId, ?string $comment): void
    {
        $value = $comment === null ? 'NULL' : "'" . $this->db->escape($comment) . "'";
        $this->db->query("UPDATE piwigo_images SET comment = $value WHERE id = $imageId");

        if ($this->imageRow($imageId)['comment'] !== $comment)
        {
            throw new RuntimeException("comment of photo $imageId was not set");
        }
    }

    /**
     * Sets a photo's provenance columns, asserting each took effect. A key
     * outside PROVENANCE_COLUMNS is refused; null clears a column.
     *
     * @param array $values column => value
     */
    public function setProvenance(int $imageId, array $values): void
    {
        if (count($values) === 0)
        {
            throw new RuntimeException('anti-vacuity: setting no provenance column would make every assertion trivial');
        }

        $assignments = array();
        foreach ($values as $column => $value)
        {
            if (!in_array($column, self::PROVENANCE_COLUMNS, true))
            {
                throw new RuntimeException("not a provenance column: $column");
            }
            $assignments[] = "`$column` = " . ($value === null ? 'NULL' : "'" . $this->db->escape((string)$value) . "'");
        }
        $this->db->query('UPDATE piwigo_images SET ' . implode(', ', $assignments) . " WHERE id = $imageId");

        $row = $this->imageRow($imageId);
        foreach ($values as $column => $value)
        {
            if ($row[$column] !== ($value === null ? null : (string)$value))
            {
                throw new RuntimeException("$column of photo $imageId was not set (got: " . var_export($row[$column], true) . ')');
            }
        }
    }

    /** Sets or clears a photo's date columns directly, asserting they took effect. */
    public function setDate(int $imageId, ?string $dateCreation, ?string $precision): void
    {
        $quote = fn(?string $v) => $v === null ? 'NULL' : "'" . $this->db->escape($v) . "'";
        $this->db->query('UPDATE piwigo_images SET date_creation = ' . $quote($dateCreation) .
            ', photoinfo_date_precision = ' . $quote($precision) . " WHERE id = $imageId");

        $row = $this->imageRow($imageId);
        if ($row['date_creation'] !== $dateCreation or $row['photoinfo_date_precision'] !== $precision)
        {
            throw new RuntimeException("date of photo $imageId was not set");
        }
    }

    /**
     * Sets a photo's qualifier and range end beside the date setDate() set,
     * and asserts they took.
     */
    public function setQualifier(int $imageId, string $qualifier, ?string $end = null, ?string $endPrecision = null): void
    {
        $quote = fn(?string $v) => $v === null ? 'NULL' : "'" . $this->db->escape($v) . "'";
        $this->db->query('UPDATE piwigo_images SET photoinfo_date_qualifier = ' . $quote($qualifier) .
            ', photoinfo_date_end = ' . $quote($end) . ', photoinfo_date_end_precision = ' . $quote($endPrecision) .
            " WHERE id = $imageId");

        $row = $this->imageRow($imageId);
        if ($row['photoinfo_date_qualifier'] !== $qualifier or $row['photoinfo_date_end'] !== $end
            or $row['photoinfo_date_end_precision'] !== $endPrecision)
        {
            throw new RuntimeException("qualifier of photo $imageId was not set");
        }
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
     * Reads the caption slots and the info tag back from a file with a plain
     * exiftool call in its own process - not through either plugin, whose
     * writing is what is under test. XMP carries its namespace, so the custom
     * pwginfo tag reads back without a config.
     *
     * @return array FILE_TAGS key => value as a string, or null when absent
     */
    public static function readFileTags(string $file): array
    {
        if (!is_file($file))
        {
            throw new RuntimeException("no file to read tags from: $file");
        }

        $command = 'exiftool -j -struct -charset iptc=UTF8';
        foreach (array_keys(self::FILE_TAGS) as $tag)
        {
            $command .= ' ' . escapeshellarg('-' . $tag);
        }
        $decoded = json_decode(self::run($command . ' ' . escapeshellarg($file)), true);
        if (!is_array($decoded) or !isset($decoded[0]) or !is_array($decoded[0]))
        {
            throw new RuntimeException("exiftool returned no JSON object for $file");
        }

        $tags = array();
        foreach (self::FILE_TAGS as $tag => $key)
        {
            $tags[$tag] = isset($decoded[0][$key]) ? (string)$decoded[0][$key] : null;
        }
        return $tags;
    }

    /**
     * Reads the date tags back with a plain exiftool call, as the file holds
     * them: the two XMP tags out of the raw XMP packet, the others as exiftool
     * reads them.
     *
     * @return array tag => value as a string, or null when absent
     */
    public static function readDateTags(string $file): array
    {
        if (!is_file($file))
        {
            throw new RuntimeException("no file to read tags from: $file");
        }

        $decoded = json_decode(self::run('exiftool -j -G1 -IPTC:DateCreated -EXIF:DateTimeOriginal ' .
            escapeshellarg($file)), true);
        if (!is_array($decoded) or !isset($decoded[0]) or !is_array($decoded[0]))
        {
            throw new RuntimeException("exiftool returned no JSON object for $file");
        }

        $packet = self::run('exiftool -b -XMP ' . escapeshellarg($file));
        $value = fn(string $key) => isset($decoded[0][$key]) ? (string)$decoded[0][$key] : null;

        // Both XMP values come out of the raw packet: exiftool prints an XMP
        // date with colons, and without photoinfo's config it guesses the
        // unknown DateEDTF's type and reads "1965/1970" as a fraction
        // (exiftool 13.25, measured 2026-10-08).
        return array(
            'XMP-photoshop:DateCreated' => self::packetValue($packet, 'photoshop:DateCreated'),
            'IPTC:DateCreated' => $value('IPTC:DateCreated'),
            'XMP-pwginfo:DateEDTF' => self::packetValue($packet, 'pwginfo:DateEDTF'),
            'EXIF:DateTimeOriginal' => $value('ExifIFD:DateTimeOriginal'),
            );
    }

    /**
     * One simple XMP property out of a raw packet, written as an element or
     * as an attribute; null when absent.
     */
    private static function packetValue(string $packet, string $property): ?string
    {
        $name = preg_quote($property, '~');
        if (!preg_match('~<' . $name . '>([^<]*)</' . $name . '>|' . $name . '=[\'"]([^\'"]*)~', $packet, $m))
        {
            return null;
        }

        return html_entity_decode($m[1] !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES | ENT_XML1);
    }

    /** Puts a DateTimeOriginal into a fixture file, as a camera or a scanner would, and asserts it is there. */
    public static function writeDateTimeOriginal(string $file, string $value): void
    {
        self::run('exiftool -q -overwrite_original ' . escapeshellarg('-EXIF:DateTimeOriginal=' . $value) . ' ' . escapeshellarg($file));
        if (self::readDateTags($file)['EXIF:DateTimeOriginal'] !== $value)
        {
            throw new RuntimeException("DateTimeOriginal was not written into $file");
        }
    }

    /**
     * Runs a shell command, failing loudly on a non-zero exit. Its output is
     * stdout only, so a warning on stderr cannot break the JSON readFileTags()
     * decodes.
     *
     * @return string its output
     */
    public static function run(string $command): string
    {
        $output = array();
        $status = 1;
        exec($command, $output, $status);
        if ($status !== 0)
        {
            throw new RuntimeException("`$command` exited with $status: " . implode("\n", $output));
        }
        return implode("\n", $output);
    }

    /** A public top-level album of this suite's own, visible to guests too. */
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

        $status = $this->db->scalar("SELECT status FROM `piwigo_categories` WHERE id = $id AND visible = 'true'");
        if ($status !== 'public')
        {
            throw new RuntimeException("fixture album $id is not public and visible");
        }

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

    /** A typetags group of this suite's own. */
    public function createGroup(string $name): int
    {
        $this->db->query(
            "INSERT INTO piwigo_typetags (name, color) VALUES ('" . $this->db->escape($name) . "', '#123456')"
        );
        $id = $this->db->insertId();
        if ($id <= 0)
        {
            throw new RuntimeException("group $name was not inserted");
        }
        $this->testGroups[] = $id;
        return $id;
    }

    /**
     * The install's Freitext group, or one of this suite's own, shaped like
     * the seeded one, when the seed has not run.
     *
     * @return array id, color, emoji
     */
    public function freitextGroup(): array
    {
        $hidden = $this->db->scalar(
            "SELECT name FROM piwigo_typetags WHERE name LIKE '" . $this->db->escape(PHOTOINFO_FREITEXT_GROUP) . " hidden %'"
        );
        if ($hidden !== null)
        {
            throw new RuntimeException("a killed run left the Freitext group renamed to '$hidden'; rename it back to '"
                . PHOTOINFO_FREITEXT_GROUP . "' by hand");
        }

        $row = $this->db->query(
            "SELECT id, color, emoji FROM piwigo_typetags WHERE name = '" . $this->db->escape(PHOTOINFO_FREITEXT_GROUP) . "'"
        )->fetch_assoc();
        if ($row !== null)
        {
            return array('id' => (int)$row['id'], 'color' => $row['color'], 'emoji' => $row['emoji']);
        }

        $this->db->query(
            "INSERT INTO piwigo_typetags (name, color, emoji) VALUES ('" . $this->db->escape(PHOTOINFO_FREITEXT_GROUP) .
            "', '" . self::FREITEXT_COLOR . "', '" . self::FREITEXT_EMOJI . "')"
        );
        $id = $this->db->insertId();
        if ($id <= 0)
        {
            throw new RuntimeException('the Freitext group was not inserted');
        }
        $this->testGroups[] = $id;
        return array('id' => $id, 'color' => self::FREITEXT_COLOR, 'emoji' => self::FREITEXT_EMOJI);
    }

    /** Renames the install's Freitext group away until destroyTestTags() puts it back. */
    public function hideFreitextGroup(): void
    {
        $group = $this->freitextGroup();
        $this->hiddenFreitext = $group;
        $this->db->query(
            "UPDATE piwigo_typetags SET name = '" . $this->db->escape(PHOTOINFO_FREITEXT_GROUP . ' hidden ' . bin2hex(random_bytes(4))) .
            "' WHERE id = " . $group['id']
        );
        $left = (int)$this->db->scalar(
            "SELECT COUNT(*) FROM piwigo_typetags WHERE name = '" . $this->db->escape(PHOTOINFO_FREITEXT_GROUP) . "'"
        );
        if ($left !== 0)
        {
            throw new RuntimeException('the Freitext group is still there');
        }
    }

    /** The group a tag is in, null for none. */
    public function groupOf(int $tagId): ?int
    {
        $group = $this->db->scalar("SELECT id_typetags FROM piwigo_tags WHERE id = $tagId");
        return $group === null ? null : (int)$group;
    }

    /** A tag of this suite's own, in a group or none. */
    public function createTag(string $name, ?int $groupId = null): int
    {
        $this->db->query(
            "INSERT INTO piwigo_tags (name, url_name, id_typetags) VALUES ('" . $this->db->escape($name) . "', '" .
            $this->db->escape(bin2hex(random_bytes(8))) . "', " . ($groupId === null ? 'NULL' : $groupId) . ')'
        );
        $id = $this->db->insertId();
        if ($id <= 0)
        {
            throw new RuntimeException("tag $name was not inserted");
        }
        $this->testTags[] = $id;
        return $id;
    }

    /** A tag the server created while a test ran (a copy, a typed name), so teardown removes it. */
    public function tagIdNamed(string $name): int
    {
        $id = (int)$this->db->scalar("SELECT id FROM piwigo_tags WHERE name = '" . $this->db->escape($name) . "'");
        if ($id <= 0)
        {
            throw new RuntimeException("no tag named $name");
        }
        $this->testTags[] = $id;
        return $id;
    }

    /** Links one tag to one photo directly, asserting it took effect. */
    public function linkTag(int $imageId, int $tagId): void
    {
        $this->db->query("INSERT IGNORE INTO piwigo_image_tag (image_id, tag_id) VALUES ($imageId, $tagId)");
        if (!in_array($tagId, $this->tagIdsOf($imageId), true))
        {
            throw new RuntimeException("tag $tagId was not linked to photo $imageId");
        }
    }

    /** @return int[] the photo's tag ids, ascending */
    public function tagIdsOf(int $imageId): array
    {
        $ids = array();
        $result = $this->db->query("SELECT tag_id FROM piwigo_image_tag WHERE image_id = $imageId ORDER BY tag_id");
        while ($row = $result->fetch_assoc())
        {
            $ids[] = (int)$row['tag_id'];
        }
        return $ids;
    }

    /** A person a test created through plugins/persons, so teardown removes its rows and tag. */
    public function trackPerson(string $name): void
    {
        $this->testPersons[] = $name;
    }

    /**
     * Reads the keyword fields and the marker back with a plain exiftool call in
     * its own process, not through photoinfo.
     *
     * @return array the three keyword fields as lists of strings (empty when
     *   absent), and the marker as a string or null
     */
    public static function readKeywords(string $file): array
    {
        if (!is_file($file))
        {
            throw new RuntimeException("no file to read keywords from: $file");
        }

        $command = 'exiftool -j -charset iptc=UTF8';
        foreach (array_keys(self::KEYWORD_TAGS) as $tag)
        {
            $command .= ' ' . escapeshellarg('-' . $tag);
        }
        $decoded = json_decode(self::run($command . ' ' . escapeshellarg($file)), true);
        if (!is_array($decoded) or !isset($decoded[0]) or !is_array($decoded[0]))
        {
            throw new RuntimeException("exiftool returned no JSON object for $file");
        }

        $tags = array();
        foreach (self::KEYWORD_TAGS as $tag => $key)
        {
            $value = $decoded[0][$key] ?? null;
            if ($tag == 'XMP-pwginfo:TagsWritten')
            {
                $tags[$tag] = $value === null ? null : (string)$value;
                continue;
            }
            $list = $value === null ? array() : array_map('strval', (array)$value);
            sort($list, SORT_STRING);
            $tags[$tag] = $list;
        }
        return $tags;
    }

    /**
     * Removes what a copied gallery photo brings along of persons and of tag
     * writes - its regions, keywords and marker - so a case sees only what it
     * put there itself.
     */
    public static function stripRegionsAndKeywords(string $file): void
    {
        $fields = array('XMP-mwg-rs:RegionInfo', 'XMP-iptcExt:PersonInImage', 'XMP-dc:Subject', 'IPTC:Keywords',
            'XMP-lr:HierarchicalSubject', 'XMP-pwginfo:TagsWritten');
        // -config is honoured only as the first argument.
        $command = 'exiftool -config ' . escapeshellarg(PHOTOINFO_PATH . 'exiftool/pwginfo.config') . ' -q -overwrite_original';
        foreach ($fields as $field)
        {
            $command .= ' ' . escapeshellarg('-' . $field . '=');
        }
        self::run($command . ' ' . escapeshellarg($file) . ' 2>&1');

        $left = self::run('exiftool -s3 ' . implode(' ', array_map(fn ($field) => escapeshellarg('-' . $field), $fields)) .
            ' ' . escapeshellarg($file));
        if (trim($left) !== '')
        {
            throw new RuntimeException("regions or keywords left in $file: $left");
        }
    }

    /** Removes the tags, groups and persons this fixture created or tracked, and their links. */
    public function destroyTestTags(): void
    {
        foreach ($this->testPersons as $name)
        {
            $escaped = $this->db->escape($name);
            $person = $this->db->query("SELECT id, tag_id FROM piwigo_persons WHERE name = '$escaped'")->fetch_assoc();
            if ($person === null)
            {
                continue;
            }
            $this->db->query('DELETE FROM piwigo_person_region WHERE person_id = ' . (int)$person['id']);
            $this->db->query('DELETE FROM piwigo_persons WHERE id = ' . (int)$person['id']);
            if ($person['tag_id'] !== null)
            {
                $this->testTags[] = (int)$person['tag_id'];
            }
        }
        $this->testPersons = array();

        foreach (array_unique($this->testTags) as $id)
        {
            $this->db->query('DELETE FROM piwigo_image_tag WHERE tag_id = ' . (int)$id);
            $this->db->query('DELETE FROM piwigo_tags WHERE id = ' . (int)$id);
        }
        $this->testTags = array();

        foreach ($this->testGroups as $id)
        {
            $this->db->query('UPDATE piwigo_tags SET id_typetags = NULL WHERE id_typetags = ' . (int)$id);
            $this->db->query('DELETE FROM piwigo_typetags WHERE id = ' . (int)$id);
        }
        $this->testGroups = array();

        if ($this->hiddenFreitext !== null)
        {
            $this->db->query(
                "UPDATE piwigo_typetags SET name = '" . $this->db->escape(PHOTOINFO_FREITEXT_GROUP) .
                "' WHERE id = " . $this->hiddenFreitext['id']
            );
            $this->hiddenFreitext = null;
        }

        $this->db->query("UPDATE piwigo_user_cache SET nb_available_tags = NULL");
    }

    /** What this fixture created, for the E2E seed's separate restore process. */
    public function exportTestObjects(): array
    {
        return array(
            'images' => $this->testImages,
            'albums' => $this->testAlbums,
            'tags' => $this->testTags,
            'groups' => $this->testGroups,
            );
    }

    public function importTestObjects(array $objects): void
    {
        $this->testImages = $objects['images'] ?? array();
        $this->testAlbums = $objects['albums'] ?? array();
        $this->testTags = $objects['tags'] ?? array();
        $this->testGroups = $objects['groups'] ?? array();
    }

    /**
     * Removes every photo this fixture created: its rows, its file with
     * exiftool's _original sidecar, provenance's lock and history rows for it,
     * and its derivatives.
     */
    public function destroyTestImages(): void
    {
        foreach ($this->testImages as $image)
        {
            $id = (int)$image['id'];
            $this->db->query('DELETE FROM piwigo_images WHERE id = ' . $id);
            $this->db->query('DELETE FROM piwigo_image_category WHERE image_id = ' . $id);
            $this->db->query('DELETE FROM piwigo_image_tag WHERE image_id = ' . $id);
            if ($this->db->scalar("SHOW TABLES LIKE 'piwigo_provenance_history'") !== null)
            {
                $this->db->query("DELETE FROM piwigo_provenance_history WHERE object = 'photo' AND object_id = " . $id);
            }

            foreach (glob($image['file'] . '*') as $leftover)
            {
                @unlink($leftover);
            }
            foreach (glob(dirname($image['file']) . '/pwg_representative/' . pathinfo($image['file'], PATHINFO_FILENAME) . '.*') as $leftover)
            {
                @unlink($leftover);
            }
            @rmdir(dirname($image['file']) . '/pwg_representative');

            @unlink(PIWIGO_ROOT . self::PROVENANCE_LOCK_DIR . sha1($image['db_path']) . '.lock');
            if ($this->db->scalar("SHOW TABLES LIKE 'piwigo_person_region'") !== null)
            {
                $this->db->query('DELETE FROM piwigo_person_region WHERE image_id = ' . $id);
            }
            // The backups a photoedit edit made before it wrote the file.
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
        $this->forgetPhotoeditVersions(array_map(fn ($image) => (int)$image['id'], $this->testImages));
        $this->testImages = array();

        foreach ($this->physicalDirs as $dir)
        {
            @rmdir($dir);
        }
        $this->physicalDirs = array();
    }

    /** Drops these photos from the photoedit_versions row an edit writes; the rows were deleted directly. */
    private function forgetPhotoeditVersions(array $ids): void
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
            if ($this->db->scalar("SHOW TABLES LIKE 'piwigo_provenance_history'") !== null)
            {
                $this->db->query("DELETE FROM piwigo_provenance_history WHERE object = 'album' AND object_id = " . (int)$id);
            }
        }
        $this->testAlbums = array();
    }
}
