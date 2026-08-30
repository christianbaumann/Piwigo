<?php
use PHPUnit\Framework\TestCase;

/**
 * The write-back across its real boundaries: a real image on disk, a real
 * exiftool, a real lock and the real MariaDB.
 *
 * Every assertion about what landed in the file is made by an INDEPENDENT
 * exiftool process, not by persons_read_regions(): a suite that read back with
 * the code under test could only prove the plugin agrees with itself, while
 * what has to be true is that a stranger's reader finds the region.
 *
 * Every photo is one this suite created (FixtureBuilder::createTestImage),
 * never a scan of the collection.
 */
final class WriteRegionsTest extends TestCase
{
    private const JANE = 'Persons Write Jane';
    private const JOHN = 'Persons Write John';
    private const REX = 'Persons Write Rex';

    /** Writers the concurrency case starts at once. */
    private const CONCURRENT_WRITERS = 8;

    /** Seconds the workers wait, so every one of them has booted before any writes. */
    private const CONCURRENT_START_DELAY_SECONDS = 3;

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);

        PiwigoRuntime::loadPlugin();
        PiwigoRuntime::resetRequestCaches();

        if (!$this->fixture->tableExists('piwigo_person_region'))
        {
            $this->markTestSkipped('the persons plugin is not installed; activate it first');
        }

        $this->image = $this->fixture->createTestImage();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyPersons(array(self::JANE, self::JOHN, self::REX));
        $this->fixture->destroyPersons($this->concurrentNames());
    }

    /**
     * [HAPPY] A written region is found by a reader that is not this plugin.
     *
     * The Phase 3 success criterion in one test: the bytes on disk carry the
     * face, in the MWG place, with the coordinates asked for.
     */
    public function testAnIndependentReaderFindsTheWrittenRegion(): void
    {
        $result = persons_apply_change(
            $this->image['id'],
            array(array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2)),
            array()
        );

        $this->assertTrue($result['ok'], 'the write failed: ' . $result['message']);

        $list = $this->regionListFromFile();
        $this->assertCount(1, $list, 'anti-vacuity: the file carries no region at all');
        $this->assertSame(self::JANE, $list[0]['Name']);
        $this->assertSame('Face', $list[0]['Type']);
        $this->assertSame('normalized', $list[0]['Area']['Unit']);
        $this->assertEqualsWithDelta(0.5, (float)$list[0]['Area']['X'], 1e-6);
        $this->assertEqualsWithDelta(0.4, (float)$list[0]['Area']['Y'], 1e-6);
        $this->assertEqualsWithDelta(0.1, (float)$list[0]['Area']['W'], 1e-6);
        $this->assertEqualsWithDelta(0.2, (float)$list[0]['Area']['H'], 1e-6);

        $this->assertContains(self::JANE, $this->personInImageFromFile());
    }

    /**
     * [HAPPY] A library that has never heard of MWG finds the region in the
     * standard XMP packet.
     *
     * exiftool reading back what exiftool wrote cannot tell a region stored in
     * the standard place apart from one only exiftool knows how to find. So the
     * packet is extracted by ImageMagick - which has no MWG support whatsoever
     * and simply hands over the XMP bytes it found in the file - and the region
     * is then read out of that raw XML. What this proves, and the exiftool
     * round trip does not, is that the namespace URI, the element names and the
     * coordinates are on disk in the form every other reader looks for.
     *
     * ImageMagick comes from the DDEV web image rather than from
     * webimage_extra_packages; if a future image drops it this test fails
     * loudly naming it, the same arrangement as the provenance suite's
     * WriteBackTest::testAnIndependentReaderFindsTheCaption.
     */
    public function testAnIndependentLibraryFindsTheRegionInTheStandardXmpPacket(): void
    {
        persons_apply_change(
            $this->image['id'],
            array(array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2)),
            array()
        );

        $xmp = $this->xmpPacketViaImageMagick();

        $this->assertStringContainsString(
            'http://www.metadataworkinggroup.com/schemas/regions/',
            $xmp,
            'the MWG regions namespace is not declared in the XMP packet'
        );
        $this->assertStringContainsString('<mwg-rs:Name>' . self::JANE . '</mwg-rs:Name>', $xmp);
        $this->assertStringContainsString('<mwg-rs:Type>Face</mwg-rs:Type>', $xmp);
        $this->assertStringContainsString('<stArea:unit>normalized</stArea:unit>', $xmp);
        $this->assertStringContainsString('<stArea:x>0.5</stArea:x>', $xmp);
        $this->assertStringContainsString('<stArea:y>0.4</stArea:y>', $xmp);
        $this->assertStringContainsString('<stArea:w>0.1</stArea:w>', $xmp);
        $this->assertStringContainsString('<stArea:h>0.2</stArea:h>', $xmp);
        $this->assertStringContainsString('<rdf:li>' . self::JANE . '</rdf:li>', $xmp);
    }

    /**
     * [HAPPY] AppliedToDimensions is written, and it is the file's own size.
     *
     * Without it no later reader can tell a resized photo from an untouched one,
     * which is the whole basis of the stale-region indicator.
     */
    public function testTheWrittenRegionRecordsTheImageDimensions(): void
    {
        persons_apply_change(
            $this->image['id'],
            array(array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2)),
            array()
        );

        $applied = $this->regionInfoFromFile()['AppliedToDimensions'] ?? null;

        $this->assertNotNull($applied, 'the file carries no AppliedToDimensions');
        $this->assertSame($this->image['width'], (int)$applied['W']);
        $this->assertSame($this->image['height'], (int)$applied['H']);
    }

    /**
     * [HAPPY] Writing B into a file that already holds A leaves both.
     *
     * The merge failure this whole phase exists to prevent: exiftool replaces
     * RegionInfo wholesale, so a writer that composed the structure from its own
     * change alone would delete A on the way past.
     */
    public function testWritingASecondPersonKeepsTheFirst(): void
    {
        persons_apply_change($this->image['id'],
            array(array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2)), array());
        $this->assertCount(1, $this->regionListFromFile(), 'anti-vacuity: the precondition did not take');

        persons_apply_change($this->image['id'],
            array(array('name' => self::JOHN, 'x' => 0.2, 'y' => 0.3, 'w' => 0.1, 'h' => 0.1)), array());

        $this->assertSame(array(self::JANE, self::JOHN), $this->namesInFile());
        $this->assertSame(array(self::JANE, self::JOHN), $this->personInImageFromFile());
    }

    /**
     * [ECP] A region this plugin never wrote survives a write.
     *
     * Seeded with a plain exiftool call, so it really is a stranger's data and
     * not something the plugin produced and recognises.
     */
    public function testAForeignRegionSurvivesAWrite(): void
    {
        $this->fixture->writeRegionsWithExiftool(
            $this->image,
            array(array('name' => self::REX, 'x' => 0.7, 'y' => 0.7, 'w' => 0.2, 'h' => 0.2, 'type' => 'Pet')),
            $this->image['width'],
            $this->image['height']
        );
        $this->assertCount(1, $this->regionListFromFile(), 'anti-vacuity: the foreign region was not seeded');

        persons_apply_change($this->image['id'],
            array(array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2)), array());

        $list = $this->regionListFromFile();
        $this->assertCount(2, $list, 'the foreign region was dropped by the write');
        $this->assertSame(array(self::REX, self::JANE), $this->namesInFile());
        $this->assertSame('Pet', $list[0]['Type']);
        $this->assertSame(array(self::JANE), $this->personInImageFromFile(),
            'a pet is not a person and never reaches PersonInImage');
    }

    /** [ST] After a write the index says exactly what the file says. */
    public function testTheIndexMatchesTheFileAfterAWrite(): void
    {
        $result = persons_apply_change($this->image['id'],
            array(array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2)), array());

        $this->assertSame(1, $result['regions'], 'anti-vacuity: the write indexed nothing');
        $this->assertSame(1, $this->regionCount());
        $this->assertTrue($this->photoCarriesTag(self::JANE), 'the person was not mirrored as a tag');
    }

    /** [ST] A removal takes the region out of the file and out of the index. */
    public function testRemovingARegionTakesItOutOfTheFile(): void
    {
        persons_apply_change($this->image['id'], array(
            array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2),
            array('name' => self::JOHN, 'x' => 0.2, 'y' => 0.3, 'w' => 0.1, 'h' => 0.1),
        ), array());
        $this->assertCount(2, $this->regionListFromFile(), 'anti-vacuity: the precondition did not take');

        persons_apply_change($this->image['id'], array(), array(array('name' => self::JOHN)));

        $this->assertSame(array(self::JANE), $this->namesInFile());
        $this->assertSame(array(self::JANE), $this->personInImageFromFile());
        $this->assertSame(1, $this->regionCount());
        $this->assertFalse($this->photoCarriesTag(self::JOHN));
    }

    /**
     * [BVA] Removing the last region leaves the file carrying neither tag.
     *
     * An empty RegionList would be a file claiming it was examined and nobody
     * was found - a different statement from a file that was never tagged, and
     * one every other reader would have to special-case.
     */
    public function testRemovingTheLastRegionLeavesNoTagsBehind(): void
    {
        persons_apply_change($this->image['id'],
            array(array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2)), array());
        $this->assertCount(1, $this->regionListFromFile(), 'anti-vacuity: the precondition did not take');

        persons_apply_change($this->image['id'], array(), array(array('name' => self::JANE)));

        $raw = $this->readWithExiftool();
        $this->assertArrayNotHasKey('RegionInfo', $raw, 'the file still claims a region list');
        $this->assertArrayNotHasKey('PersonInImage', $raw, 'the file still names a person');
        $this->assertSame(0, $this->regionCount());
    }

    /**
     * [HAPPY] The pre-write bytes survive beside the image.
     *
     * exiftool's default mode is kept deliberately: the sidecar is the only copy
     * of what the file held before, and -overwrite_original would remove it.
     */
    public function testTheOriginalBytesAreKeptAsASidecar(): void
    {
        $pristine = filesize($this->image['file']);
        $this->assertGreaterThan(0, $pristine, 'anti-vacuity: the fixture image is empty');

        persons_apply_change($this->image['id'],
            array(array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2)), array());

        clearstatcache();
        $sidecar = $this->image['file'] . '_original';
        $this->assertFileExists($sidecar, 'no _original sidecar was left');
        $this->assertSame($pristine, filesize($sidecar), 'the sidecar is not a copy of the pre-write bytes');
    }

    /**
     * [NEG] A file that cannot be written is reported, left alone, and leaves
     * no half-written state behind.
     */
    public function testAReadOnlyFileIsReportedAndLeftUntouched(): void
    {
        $before = md5_file($this->image['file']);
        chmod($this->image['file'], 0444);

        try
        {
            $result = persons_apply_change($this->image['id'],
                array(array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2)), array());
        }
        finally
        {
            chmod($this->image['file'], 0644);
        }

        $this->assertFalse($result['ok']);
        $this->assertNotSame('', $result['message']);

        clearstatcache();
        $this->assertSame($before, md5_file($this->image['file']), 'the unwritable file was modified');
        $this->assertFileDoesNotExist($this->image['file'] . '_original', 'a sidecar was left for a write that failed');
        $this->assertSame(0, $this->regionCount(), 'a failed write must not touch the index');
    }

    /** [NEG] The operation directory is removed even after a failed write. */
    public function testNoOperationDirectoryIsLeftBehind(): void
    {
        persons_apply_change($this->image['id'],
            array(array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2)), array());

        $left = is_dir(PERSONS_ARGS_DIR) ? glob(PERSONS_ARGS_DIR . '*') : array();
        $this->assertSame(array(), $left, 'an operation directory survived the write');
    }

    // ── concurrency: the mode that silently loses a face ───────────────────

    /**
     * [NEG] Eight writers against one file all succeed and the file holds every
     * face.
     *
     * Two failures hide here, and only separate processes expose either: two
     * exiftool invocations writing one file destroy it outright (measured while
     * building the provenance plugin), and - specific to this plugin - two
     * writers that each read the file before either wrote it would both produce
     * a complete, valid region list missing the other's face. The second is why
     * the lock spans the whole read-merge-write rather than the exec.
     *
     * Both mutants were run, 2026-08-30, and killed differently:
     *
     *   no lock at all - the writers collide inside exiftool, which refuses
     *   with "Temporary file already exists" and the worker exits non-zero.
     *
     *   the lock moved to after the read, so it guards only the write - every
     *   writer reports success and the file comes back holding one face out of
     *   eight. That mutant is why the lock is taken where it is: the loud
     *   failure is not the one worth designing against.
     */
    public function testConcurrentWritersEachLandTheirOwnFace(): void
    {
        $names = $this->concurrentNames();
        $startAt = microtime(true) + self::CONCURRENT_START_DELAY_SECONDS;

        $processes = array();
        $pipes = array();
        foreach ($names as $i => $name)
        {
            $processes[$i] = proc_open(
                'php ' . escapeshellarg(PERSONS_PATH . 'tests/Support/write-regions-worker.php') . ' ' .
                escapeshellarg((string)$this->image['id']) . ' ' .
                escapeshellarg($name) . ' ' .
                escapeshellarg((string)(0.1 + $i * 0.1)) . ' ' .
                escapeshellarg((string)$startAt),
                array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
                $pipes[$i]
            );
        }

        $outcomes = array();
        foreach ($processes as $i => $process)
        {
            $outcomes[$i] = trim(stream_get_contents($pipes[$i][1]) . ' ' . stream_get_contents($pipes[$i][2]));
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $outcomes[$i] = proc_close($process) . ': ' . $outcomes[$i];
        }

        $this->assertCount(self::CONCURRENT_WRITERS, $outcomes, 'anti-vacuity: no writer ran');
        foreach ($outcomes as $i => $outcome)
        {
            $this->assertStringStartsWith('0: ', $outcome, "writer $i did not write: $outcome");
        }

        clearstatcache();
        $this->assertFileExists($this->image['file'], 'concurrent writers destroyed the image');

        $inFile = $this->namesInFile();
        sort($inFile);
        $expected = $names;
        sort($expected);
        $this->assertSame($expected, $inFile, 'a face was lost between two writers');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return array the eight distinct names the concurrency case writes */
    private function concurrentNames(): array
    {
        $names = array();
        for ($i = 0; $i < self::CONCURRENT_WRITERS; $i++)
        {
            $names[] = 'Persons Write Racer ' . $i;
        }

        return $names;
    }

    /**
     * The file's tags, read by an exiftool process this plugin's reader had no
     * hand in starting.
     */
    private function readWithExiftool(): array
    {
        $output = array();
        $status = 1;
        exec(
            'exiftool -json -struct -charset filename=UTF8'
            . ' -XMP-mwg-rs:RegionInfo -XMP-iptcExt:PersonInImage '
            . escapeshellarg($this->image['file']) . ' 2>/dev/null',
            $output,
            $status
        );

        if ($status !== 0)
        {
            throw new RuntimeException('the independent reader could not read the file');
        }

        $decoded = json_decode(implode("\n", $output), true);
        if (!is_array($decoded) || !isset($decoded[0]))
        {
            throw new RuntimeException('the independent reader returned no JSON object');
        }

        return $decoded[0];
    }

    /**
     * The file's raw XMP packet, extracted by ImageMagick.
     *
     * A second implementation end to end: ImageMagick parses the container,
     * finds the XMP profile and hands over its bytes, knowing nothing about
     * what is inside them.
     */
    private function xmpPacketViaImageMagick(): string
    {
        $packet = shell_exec('convert ' . escapeshellarg($this->image['file']) . ' xmp:- 2>/dev/null');

        if (!is_string($packet) || $packet === '')
        {
            throw new RuntimeException(
                'ImageMagick returned no XMP packet. Either the write left none, or `convert` is '
                . 'missing from the DDEV web image - it comes from the image itself, not from '
                . 'webimage_extra_packages in .ddev/config.yaml.'
            );
        }

        // Anti-vacuity: every assertion below is a substring search, and they
        // all pass trivially against a packet that is really an error message.
        $this->assertGreaterThan(500, strlen($packet), 'the XMP packet is too small to hold a region');

        return $packet;
    }

    private function regionInfoFromFile(): array
    {
        return $this->readWithExiftool()['RegionInfo'] ?? array();
    }

    private function regionListFromFile(): array
    {
        $list = $this->regionInfoFromFile()['RegionList'] ?? array();

        // One region comes back as a bare object rather than a one-element list.
        return isset($list['Area']) ? array($list) : $list;
    }

    /** @return array the Name of every region in the file, in file order */
    private function namesInFile(): array
    {
        $names = array();
        foreach ($this->regionListFromFile() as $entry)
        {
            $names[] = $entry['Name'];
        }

        return $names;
    }

    private function personInImageFromFile(): array
    {
        $names = $this->readWithExiftool()['PersonInImage'] ?? array();

        return is_array($names) ? $names : array($names);
    }

    private function regionCount(): int
    {
        return (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . (int)$this->image['id']
        );
    }

    private function photoCarriesTag(string $name): bool
    {
        $escaped = $this->db->escape($name);

        return (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_image_tag AS it' .
            ' JOIN piwigo_tags AS t ON t.id = it.tag_id' .
            ' WHERE it.image_id = ' . (int)$this->image['id'] . " AND t.name = '$escaped'"
        ) > 0;
    }
}
