<?php
use PHPUnit\Framework\TestCase;

/**
 * The file write-back across its real boundaries: pwg.provenance.writeBack over
 * ws.php, a real exiftool, a real PNG on disk.
 *
 * Every test here works on a photo this suite created for itself
 * (FixtureBuilder::createTestImage) - never on a scan of the collection, because
 * this is the one operation in the plugin that can destroy a file.
 *
 * Metadata is read back with exiftool, never with a byte grep: on PNG the XMP
 * packet lands in a compressed zTXt chunk, so a substring search reports a false
 * negative on a file that is perfectly tagged. exif_read_data() is no use either
 * - it returns false for the PNG eXIf chunk.
 */
final class WriteBackTest extends TestCase
{
    private const METHOD = 'pwg.provenance.writeBack';
    private const HISTORY_TABLE = 'piwigo_provenance_history';

    /** Lower bounds, so a read that got nothing fails instead of passing. */
    private const MIN_PNG_BYTES = 10000;
    private const MIN_IDAT_BYTES = 1000;

    /** A written file may not exceed this multiple of its own pristine size. */
    private const MAX_GROWTH_FACTOR = 2.2;

    /** Lower bound on ImageMagick's verbose output, so a truncated read cannot pass. */
    private const MIN_IDENTIFY_BYTES = 500;

    /** Enough contention to reproduce the measured data-loss mode without locking. */
    private const CONCURRENT_WRITERS = 12;

    /** How long the writers are given to boot before their shared start time. */
    private const CONCURRENT_START_DELAY_SECONDS = 2.0;

    /** The provenance the fixture photo carries unless a test says otherwise. */
    private const VALUES = array(
        'provenance_physical_album' => 'Oma Müllers Fotoalbum',
        'provenance_owner' => 'Anna Müller',
        'provenance_scanned_on' => '2026-04-19',
        'provenance_album_note' => 'Rückseiten beschriftet',
        'provenance_note' => 'Ecke abgerissen',
        );

    /**
     * exiftool's JSON keys use family-1 group names, which match the tags the
     * writer asks for everywhere except EXIF, whose family-1 group is the IFD
     * the tag sits in. Only that one needs translating.
     */
    private const READ_KEY = array('EXIF:ImageDescription' => 'IFD0:ImageDescription');

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    /** @var array id, db_path, file */
    private array $image;
    private int $baselineHistoryId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->recordAllProvenance();

        $this->image = $this->fixture->createTestImage();
        $this->fixture->imageProvenance($this->image['id'], self::VALUES);

        $this->baselineHistoryId = (int)$this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM ' . self::HISTORY_TABLE);
    }

    protected function tearDown(): void
    {
        $this->clearExiftoolPathOverride();
        $this->fixture->destroyTestImages();
        $this->fixture->restore();
        $this->db->query('DELETE FROM ' . self::HISTORY_TABLE . ' WHERE id > ' . $this->baselineHistoryId);
        $this->ws->logout();
    }

    // ── what lands in the file ────────────────────────────────────────────

    /**
     * [HAPPY] All five caption slots and all five custom tags read back.
     *
     * The five caption slots are the MWG mirror set: a normal photo tool reads
     * whichever one it knows about, so they carry the same text. The pwgprov
     * tags carry the values unjoined, which is what makes a later reader able to
     * tell the owner from the note.
     */
    public function testEveryCaptionSlotAndCustomTagReadsBack(): void
    {
        $res = $this->writeBack(array($this->image['id']));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(1, $res['json']['result']['written']);
        $this->assertSame(array(), $res['json']['result']['failed']);

        $tags = $this->readTags();
        $caption = (string)$this->tagValue($tags, 'EXIF:ImageDescription');

        $this->assertCarriesProvenance($caption, self::VALUES);

        foreach (provenance_caption_tags() as $tag)
        {
            $this->assertSame($caption, $this->tagValue($tags, $tag), "$tag does not carry the same caption as the others");
        }

        foreach (provenance_xmp_tag_map() as $field => $tag)
        {
            $this->assertSame(
                self::VALUES[$field],
                $this->tagValue($tags, 'XMP-' . PROVENANCE_XMP_PREFIX . ':' . $tag),
                "$tag does not carry $field"
            );
        }
    }

    /**
     * [ERR] The pixels are untouched.
     *
     * Characterizes exiftool's default mode: it rewrites the container, so file
     * size and mtime both change - the image data must not. Compared as the
     * decompressed IDAT stream rather than the file's bytes, because the
     * compressed chunk boundaries legitimately move when metadata chunks do.
     */
    public function testPixelsAreUnchanged(): void
    {
        $before = $this->idatDigest($this->image['file']);

        $this->writeBack(array($this->image['id']));

        $this->assertSame($before, $this->idatDigest($this->image['file']));
    }

    /**
     * [ERR] The _original sidecar is written once and then left alone.
     *
     * Measured research finding 6, and the reason the default mode is kept: the
     * sidecar keeps the file as it arrived, not as the previous write left it,
     * so a third write does not quietly turn "the original" into a copy that
     * already carries provenance.
     */
    public function testTheOriginalSidecarIsCreatedOnceAndNeverRewritten(): void
    {
        $sidecar = $this->image['file'] . '_original';
        $this->assertFileDoesNotExist($sidecar);

        $pristine = hash_file('sha256', $this->image['file']);

        $this->writeBack(array($this->image['id']));
        $this->assertFileExists($sidecar);
        $this->assertSame($pristine, hash_file('sha256', $sidecar), 'the sidecar is not the file as it arrived');

        foreach (array('zweiter Lauf', 'dritter Lauf') as $note)
        {
            $this->fixture->imageProvenance($this->image['id'], array_merge(self::VALUES, array('provenance_note' => $note)));
            $this->writeBack(array($this->image['id']));
            $this->assertSame($pristine, hash_file('sha256', $sidecar), 'a later write rewrote the sidecar');
        }

        $this->assertSame('dritter Lauf', $this->tagValue($this->readTags(), '-XMP-pwgprov:PhotoNote'));
    }

    /** [HAPPY] The staged argfiles are gone once the operation returns. */
    public function testTheOperationDirectoryIsRemoved(): void
    {
        $this->writeBack(array($this->image['id']));

        $this->assertSame(array(), glob(PROVENANCE_ARGS_DIR . '*'), 'an operation directory was left behind');
    }

    /**
     * [ERR] A reader that is not exiftool finds the caption.
     *
     * Automates the manual step "open a written file in an external viewer and
     * confirm the caption is where a normal photo tool shows it". ImageMagick is
     * a wholly separate implementation from the writer, so a caption it can read
     * is a caption written to the standard slots rather than to somewhere only
     * exiftool knows about - which reading back with exiftool could never tell
     * apart.
     *
     * What stays manual is whether a GUI viewer *displays* it pleasantly; that
     * has no oracle. Whether a third-party library can find it does.
     */
    public function testAnIndependentReaderFindsTheCaption(): void
    {
        $this->writeBack(array($this->image['id']));
        $caption = (string)$this->tagValue($this->readTags(), 'EXIF:ImageDescription');
        $this->assertGreaterThan(40, strlen($caption), 'anti-vacuity: nothing was written to look for');

        $output = array();
        $status = 1;
        exec('identify -verbose ' . escapeshellarg($this->image['file']) . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, 'ImageMagick could not read the file: ' . implode(' ', $output));

        $verbose = implode("\n", $output);
        $this->assertGreaterThan(self::MIN_IDENTIFY_BYTES, strlen($verbose), 'anti-vacuity: identify said almost nothing');

        // Three slots, three standards: IPTC-IIM 2:120, the XMP photoshop:Headline
        // a photo tool reads, and EXIF. One of them alone could be a quirk of
        // ImageMagick's parser.
        foreach (array('Caption[2,120]: ', 'photoshop:Headline: ') as $slot)
        {
            $this->assertStringContainsString($slot . $caption, $verbose, "$slot does not carry the caption");
        }

        // ImageMagick's EXIF reader replaces every non-ASCII *byte* with a dot -
        // 'Müller' comes back as 'M..ller' - while its IPTC and XMP readers keep
        // the UTF-8 intact. Recorded rather than worked around: the bytes in the
        // file are correct, and exiftool reads all three back identically
        // (testEveryCaptionSlotAndCustomTagReadsBack).
        $this->assertStringContainsString(
            'exif:ImageDescription: ' . preg_replace('/[\x80-\xFF]/', '.', $caption),
            $verbose,
            'the EXIF slot does not carry the caption'
        );
    }

    /**
     * [BVA] A written file costs one extra copy of itself, and no more.
     *
     * Automates the manual step "confirm disk growth after a write-back is
     * roughly one extra copy". Asserted per file as a ratio against the file's
     * own pristine size - a causal fact - rather than as a measured total for
     * one album, which would be a figure that rots.
     */
    public function testAWriteCostsOneExtraCopyOnDisk(): void
    {
        clearstatcache(true, $this->image['file']);
        $pristine = filesize($this->image['file']);
        $this->assertGreaterThan(self::MIN_PNG_BYTES, $pristine, 'anti-vacuity: an empty file would satisfy any ratio');

        $this->writeBack(array($this->image['id']));

        $sidecar = $this->image['file'] . '_original';
        clearstatcache();
        $this->assertSame($pristine, filesize($sidecar), 'the sidecar is not a byte-for-byte copy of the original');

        // The written file is the original plus its metadata packets: more than
        // the original, but nowhere near a second copy of the pixels.
        $total = filesize($this->image['file']) + filesize($sidecar);
        $this->assertGreaterThanOrEqual(2 * $pristine, $total);
        $this->assertLessThan(self::MAX_GROWTH_FACTOR * $pristine, $total, 'a write cost more than one extra copy');
    }

    // ── concurrency: the one measured data-loss mode ──────────────────────

    /**
     * [NEG] Twelve writers against one file leave it intact.
     *
     * Two exiftool processes writing the same file destroy it outright - the
     * measured mode this phase's locking exists for. Written and watched red
     * with locking disabled before flock was added; watched green after.
     *
     * The writers are separate processes rather than parallel ws.php requests:
     * PHP serialises requests that share a session, which would make this pass
     * with no locking at all.
     */
    public function testConcurrentWritersNeverDestroyTheFile(): void
    {
        $pristineIdat = $this->idatDigest($this->image['file']);

        // A shared start time, far enough out for every process to have booted:
        // without it PHP startup staggers the writers and the contention the
        // test exists to reproduce never happens.
        $startAt = microtime(true) + self::CONCURRENT_START_DELAY_SECONDS;

        $processes = array();
        $pipes = array();
        for ($i = 0; $i < self::CONCURRENT_WRITERS; $i++)
        {
            $processes[] = proc_open(
                'php ' . escapeshellarg(PROVENANCE_PATH . 'tests/Support/write-back-worker.php') . ' ' .
                escapeshellarg((string)$this->image['id']) . ' ' .
                escapeshellarg($this->image['db_path']) . ' ' .
                escapeshellarg('Anna ' . $i) . ' ' .
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
            $this->assertStringStartsWith('0: ', $outcome, "writer $i did not write the file: $outcome");
        }

        clearstatcache();
        $this->assertFileExists($this->image['file'], 'concurrent writers destroyed the image');
        $this->assertSame($pristineIdat, $this->idatDigest($this->image['file']), 'the image is no longer readable');

        $owner = $this->tagValue($this->readTags(), '-XMP-pwgprov:Owner');
        $this->assertMatchesRegularExpression('/^Anna \d+$/', (string)$owner, 'the file carries no writer\'s value');
    }

    // ── the IPTC byte budget ──────────────────────────────────────────────

    /**
     * [BVA] Over the cap, XMP and EXIF keep the full text and IPTC a valid
     * truncated copy - and the truncation is recorded rather than silent.
     */
    public function testTextOverTheIptcCapIsTruncatedInIptcOnly(): void
    {
        $long = 'Rückseiten: ' . str_repeat('sehr ausführliche Notiz. ', 120);
        $this->assertGreaterThan(PROVENANCE_IPTC_MAX_BYTES, strlen($long), 'anti-vacuity: the fixture must exceed the cap');
        $this->fixture->imageProvenance($this->image['id'], array_merge(self::VALUES, array('provenance_album_note' => $long)));

        $this->writeBack(array($this->image['id']));

        $tags = $this->readTags();
        $caption = (string)$this->tagValue($tags, 'EXIF:ImageDescription');

        $this->assertCarriesProvenance($caption, array_merge(self::VALUES, array('provenance_album_note' => $long)));
        $this->assertGreaterThan(PROVENANCE_IPTC_MAX_BYTES, strlen($caption), 'EXIF must hold the untruncated caption');
        $this->assertSame($caption, $this->tagValue($tags, 'XMP-dc:Description'));

        $iptc = (string)$this->tagValue($tags, PROVENANCE_IPTC_CAPTION_TAG);
        $this->assertLessThanOrEqual(PROVENANCE_IPTC_MAX_BYTES, strlen($iptc));
        $this->assertGreaterThan(PROVENANCE_IPTC_MAX_BYTES / 2, strlen($iptc), 'anti-vacuity: an empty IPTC slot would pass the cap check');
        $this->assertTrue(mb_check_encoding($iptc, 'UTF-8'), 'the truncated caption is not valid UTF-8');

        $this->assertSame(
            1,
            $this->historyCount("source = 'truncation' AND field = '" . $this->db->escape(PROVENANCE_IPTC_CAPTION_TAG) . "'"),
            'the truncation was not recorded'
        );
    }

    /**
     * [ERR] Non-latin-1 text survives the round trip.
     *
     * -charset iptc=UTF8 is what makes this true; without it the IPTC packet is
     * written as latin-1 and every character outside it is mangled silently.
     */
    public function testNonLatin1TextRoundTrips(): void
    {
        $text = 'Łódź Ω 日本 Müller';
        $this->fixture->imageProvenance($this->image['id'], array_merge(self::VALUES, array('provenance_owner' => $text)));

        $this->writeBack(array($this->image['id']));

        $tags = $this->readTags();
        $this->assertSame($text, $this->tagValue($tags, '-XMP-pwgprov:Owner'));
        $this->assertStringContainsString($text, (string)$this->tagValue($tags, PROVENANCE_IPTC_CAPTION_TAG));
        $this->assertStringContainsString($text, (string)$this->tagValue($tags, 'EXIF:ImageDescription'));
    }

    // ── failure paths ─────────────────────────────────────────────────────

    /**
     * [NEG] With no usable exiftool the method refuses and touches nothing.
     *
     * The probe is driven false by pointing $conf['provenance_exiftool_path'] at
     * a directory with no binary in it. disable_functions cannot be toggled for
     * one request, so the exec() half of the probe is covered by the next test
     * instead.
     */
    public function testWriteBackRefusesWithoutExiftoolAndTouchesNoFile(): void
    {
        $before = $this->fileState();
        $this->setExiftoolPathOverride('/nonexistent-provenance-path/');

        $res = $this->writeBack(array($this->image['id']));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(501, $res['json']['err']);
        $this->assertSame($before, $this->fileState(), 'the file was touched anyway');
        $this->assertFileDoesNotExist($this->image['file'] . '_original');
        $this->assertSame(0, $this->historyCount());
    }

    /**
     * [NEG] With exec() disabled the probe answers false instead of dying.
     *
     * function_exists('exec') is checked before exec() is called for exactly
     * this reason: on a host with disable_functions=exec, calling it is a fatal
     * error, not a false return.
     */
    public function testTheProbeIsFalseWhenExecIsDisabled(): void
    {
        $script =
            'define("PROVENANCE_PATH", ' . var_export(PROVENANCE_PATH, true) . ');' .
            'require PROVENANCE_PATH . "include/functions.inc.php";' .
            'require PROVENANCE_PATH . "include/exiftool.inc.php";' .
            '$conf = array();' .
            'var_export(provenance_exiftool_available());';

        exec('php -d disable_functions=exec -r ' . escapeshellarg($script) . ' 2>&1', $out, $status);

        $this->assertSame(0, $status, 'the probe died instead of degrading: ' . implode(' ', $out));
        $this->assertSame('false', trim(implode('', $out)));

        exec('php -r ' . escapeshellarg($script) . ' 2>&1', $enabled, $enabledStatus);
        $this->assertSame(0, $enabledStatus, implode(' ', $enabled));
        $this->assertSame('true', trim(implode('', $enabled)), 'anti-vacuity: the probe must answer true with exec available');
    }

    /**
     * [NEG] A file exiftool cannot write is reported, recorded, and cleaned up
     * after - the failure row is written before the operation directory goes,
     * so cleanup never erases the evidence.
     */
    public function testAFailedWriteIsRecordedAndLeavesNoOperationDirectory(): void
    {
        file_put_contents($this->image['file'], 'this is not a PNG');

        $res = $this->writeBack(array($this->image['id']));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(0, $res['json']['result']['written']);
        $this->assertArrayHasKey((string)$this->image['id'], $res['json']['result']['failed']);
        $this->assertNotSame('', $res['json']['result']['failed'][(string)$this->image['id']]);

        $this->assertSame(
            1,
            $this->historyCount("source = 'writeback' AND field = '" . PROVENANCE_HISTORY_FIELD_FILE_ERROR . "'"),
            'the failure was not recorded'
        );
        $this->assertSame(array(), glob(PROVENANCE_ARGS_DIR . '*'), 'the operation directory survived the failure');
    }

    /** [HAPPY] A successful write leaves a history row naming the text it wrote. */
    public function testASuccessfulWriteIsRecorded(): void
    {
        $this->writeBack(array($this->image['id']));

        $rows = $this->historyRows("source = 'writeback' AND field = '" . PROVENANCE_HISTORY_FIELD_FILE . "'");

        $this->assertCount(1, $rows);
        $this->assertSame((string)$this->image['id'], $rows[0]['object_id']);
        // What was recorded is what the file actually carries, not a second
        // composition of the same values.
        $this->assertSame(
            $this->tagValue($this->readTags(), 'EXIF:ImageDescription'),
            $rows[0]['new_value']
        );
    }

    /** [BVA] A photo with no provenance at all is skipped, not rewritten. */
    public function testAPhotoWithNoProvenanceIsNotWritten(): void
    {
        $this->fixture->clearImageProvenance(array($this->image['id']));
        $before = $this->fileState();

        $res = $this->writeBack(array($this->image['id']));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(0, $res['json']['result']['written']);
        $this->assertSame(array(), $res['json']['result']['failed']);
        $this->assertSame($before, $this->fileState());
        $this->assertFileDoesNotExist($this->image['file'] . '_original');
    }

    // ── the gate ──────────────────────────────────────────────────────────

    /** [NEG] A guest is refused. */
    public function testAGuestIsRefused(): void
    {
        $this->ws->logout();
        $res = $this->ws->call(self::METHOD, array(
            'image_ids' => (string)$this->image['id'],
            'pwg_token' => 'whatever',
        ));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(401, $res['json']['err']);
    }

    /** [NEG] An authenticated non-admin is refused by the method's own gate. */
    public function testANonAdminIsRefused(): void
    {
        $this->ws->logout();
        $this->ws->login(Config::normalUsername(), Config::normalPassword());

        $res = $this->ws->call(self::METHOD, array(
            'image_ids' => (string)$this->image['id'],
            'pwg_token' => $this->ws->token(),
        ));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
    }

    /** [NEG] A wrong CSRF token is refused. */
    public function testABadTokenIsRefused(): void
    {
        $res = $this->ws->call(self::METHOD, array(
            'image_ids' => (string)$this->image['id'],
            'pwg_token' => 'not-the-token',
        ));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(403, $res['json']['err']);
    }

    /** [NEG] An unknown photo id is a 404 rather than a partial run. */
    public function testAnUnknownPhotoIsRefused(): void
    {
        $unknown = (int)$this->db->scalar('SELECT MAX(id) FROM piwigo_images') + 1000;

        $res = $this->writeBack(array($this->image['id'], $unknown));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(404, $res['json']['err']);
    }

    /** [BVA] A chunk one past the write-back ceiling is refused, not split. */
    public function testAChunkPastTheCeilingIsRefused(): void
    {
        $res = $this->writeBackRaw(implode(',', range(1, PROVENANCE_WRITEBACK_MAX_CHUNK + 1)));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(400, $res['json']['err']);
    }

    // ── known gaps ────────────────────────────────────────────────────────

    /**
     * A third party edits the file's metadata behind Piwigo's back; the plugin
     * notices that file and database have diverged.
     *
     * Skipped - nothing detects this today, by decision. No provenance column is
     * a key in use_iptc_mapping / use_exif_mapping (decisions/0015), so no
     * synchronisation ever reads these tags back, and a caption rewritten in
     * Lightroom or by a bare exiftool call is invisible to the gallery for good.
     * The plan puts divergence detection out of scope for v1 (decision 4a) and
     * docs/backlog.md carries it as a low-priority item naming
     * images.date_metadata_update as the candidate signal. Un-skipping this test
     * is that item's first step; the body below is the divergence it must catch.
     *
     * [NEG]
     */
    public function testAThirdPartyEditIsDetectedAsFileDatabaseDivergence(): void
    {
        $this->markTestSkipped(
            'no divergence detection exists in v1 - see decisions/0015 and the ' .
            '"detect file-vs-DB divergence" item in docs/backlog.md'
        );

        $this->fixture->imageProvenance($this->image['id'], self::VALUES);
        $this->writeBack(array($this->image['id']));
        $this->assertNotSame('', (string)$this->tagValue($this->readTags(), 'EXIF:ImageDescription'));

        // The third-party edit: exiftool alone, no Piwigo involved.
        exec(
            'exiftool -overwrite_original -EXIF:ImageDescription=' . escapeshellarg('edited elsewhere') .
            ' ' . escapeshellarg($this->image['file']) . ' 2>/dev/null',
            $out,
            $status
        );
        $this->assertSame(0, $status, 'the fixture edit did not happen, so there is no divergence to detect');
        $this->assertSame('edited elsewhere', (string)$this->tagValue($this->readTags(), 'EXIF:ImageDescription'));

        // What the fix owes: the gallery reports this photo as diverged rather
        // than continuing to present its database values as what the file says.
        $res = $this->ws->call('pwg.provenance.checkDivergence', array(
            'image_ids' => (string)$this->image['id'],
            'pwg_token' => $this->ws->token(),
        ));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(array($this->image['id']), $res['json']['result']['diverged']);
    }


    // ── helpers ───────────────────────────────────────────────────────────

    private function writeBack(array $ids): array
    {
        return $this->writeBackRaw(implode(',', $ids));
    }

    private function writeBackRaw(string $imageIds): array
    {
        return $this->ws->call(self::METHOD, array(
            'image_ids' => $imageIds,
            'pwg_token' => $this->ws->token(),
        ));
    }

    /**
     * Asserts a caption carries the provenance, without depending on the
     * install's language.
     *
     * The labels come from l10n() inside the request and this install runs in
     * German, so comparing against an English literal would assert the locale
     * rather than the composition. What is asserted instead is the structure
     * the composer guarantees: one part per populated field, in field order,
     * joined by the separator, each ending in its value.
     */
    private function assertCarriesProvenance(string $caption, array $values): void
    {
        $this->assertGreaterThan(40, strlen($caption), 'anti-vacuity: an empty caption would satisfy nothing below');

        $expected = array();
        foreach (provenance_field_order() as $field)
        {
            if (trim((string)($values[$field] ?? '')) !== '')
            {
                $expected[] = trim((string)$values[$field]);
            }
        }
        $this->assertGreaterThan(1, count($expected), 'anti-vacuity: the fixture must populate more than one field');

        $parts = explode(PROVENANCE_CAPTION_SEPARATOR, $caption);
        $this->assertCount(count($expected), $parts, 'the caption does not hold one part per populated field');

        foreach ($expected as $i => $value)
        {
            $this->assertStringEndsWith($value, $parts[$i], "part $i does not carry its value, so the order is wrong");
        }
    }

    /**
     * Reads the written tags back with exiftool.
     *
     * @return array exiftool's family-1 keys => value
     */
    private function readTags(): array
    {
        $command =
            'exiftool -config ' . escapeshellarg(PROVENANCE_PATH . 'exiftool/pwgprov.config') .
            ' -json -G1 -charset iptc=UTF8 -EXIF:ImageDescription -IPTC:Caption-Abstract -XMP:all ' .
            escapeshellarg($this->image['file']) . ' 2>/dev/null';

        exec($command, $out, $status);
        $this->assertSame(0, $status, "exiftool could not read the file back: $command");

        $json = json_decode(implode('', $out), true);
        $this->assertIsArray($json, 'exiftool returned no JSON');
        $this->assertArrayHasKey(0, $json);

        return $json[0];
    }

    /** The value exiftool holds for one written tag name. */
    private function tagValue(array $tags, string $tag)
    {
        $key = self::READ_KEY[$tag] ?? ltrim($tag, '-');

        return $tags[$key] ?? null;
    }

    /** sha256 of the decompressed image data, so a metadata write is invisible to it. */
    private function idatDigest(string $file): string
    {
        clearstatcache(true, $file);
        $data = (string)file_get_contents($file);
        $this->assertGreaterThan(self::MIN_PNG_BYTES, strlen($data), 'anti-vacuity: nothing was read from the file');

        $idat = '';
        $offset = 8;
        while ($offset + 8 <= strlen($data))
        {
            $length = unpack('N', substr($data, $offset, 4))[1];
            if (substr($data, $offset + 4, 4) === 'IDAT')
            {
                $idat .= substr($data, $offset + 8, $length);
            }
            $offset += 12 + $length;
        }

        $this->assertGreaterThan(self::MIN_IDAT_BYTES, strlen($idat), 'anti-vacuity: no IDAT chunk was found');

        $pixels = @gzuncompress($idat);
        $this->assertNotFalse($pixels, 'the IDAT stream did not decompress');
        $this->assertGreaterThan(self::MIN_IDAT_BYTES, strlen((string)$pixels));

        return hash('sha256', (string)$pixels);
    }

    /** Everything about the file a write would change. */
    private function fileState(): array
    {
        clearstatcache(true, $this->image['file']);

        return array(filemtime($this->image['file']), filesize($this->image['file']), hash_file('sha256', $this->image['file']));
    }

    private function setExiftoolPathOverride(string $path): void
    {
        $this->clearExiftoolPathOverride();
        $this->db->query(
            "INSERT INTO piwigo_config (param, value) VALUES ('provenance_exiftool_path', '" . $this->db->escape($path) . "')"
        );
    }

    private function clearExiftoolPathOverride(): void
    {
        $this->db->query("DELETE FROM piwigo_config WHERE param = 'provenance_exiftool_path'");
    }

    private function historyCount(string $condition = '1=1'): int
    {
        return (int)$this->db->scalar(
            'SELECT COUNT(*) FROM ' . self::HISTORY_TABLE . ' WHERE id > ' . $this->baselineHistoryId . ' AND ' . $condition
        );
    }

    private function historyRows(string $condition = '1=1'): array
    {
        $result = $this->db->query(
            'SELECT * FROM ' . self::HISTORY_TABLE . ' WHERE id > ' . $this->baselineHistoryId .
            ' AND ' . $condition . ' ORDER BY id'
        );

        $rows = array();
        while ($row = $result->fetch_assoc())
        {
            $rows[] = $row;
        }

        return $rows;
    }
}
