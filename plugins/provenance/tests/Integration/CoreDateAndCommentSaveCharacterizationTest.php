<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for core's two other writers of images.date_creation and
 * images.comment, besides the photo properties screen
 * (CorePhotoTextCharacterizationTest):
 *
 * - pwg.images.setInfo (include/ws_functions/pwg.images.php), which the Batch
 *   Manager's unit mode calls once per photo on every save, posting every field
 *   whether it changed or not (admin/themes/default/js/batchManagerUnit.js)
 * - the Batch Manager's global "Set creation date" action
 *   (admin/batch_manager_global.php, action date_creation)
 *
 * Every case is [ERR]: the oracle is the current implementation, not a
 * requirement. They record what plugins/photoinfo hooks into, and report a
 * change; they do not prove the behaviour right. Each was watched go red by
 * breaking the behaviour it claims to watch.
 */
final class CoreDateAndCommentSaveCharacterizationTest extends TestCase
{
    /** A rendered admin page shorter than this is an error page or a redirect. */
    private const MIN_PAGE_BYTES = 2000;

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private int $albumId;
    private int $imageId;
    private int $otherId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->fixture = new FixtureBuilder($this->db);
        $this->albumId = $this->fixture->createTestAlbum('provenance-char-datecomment-' . bin2hex(random_bytes(4)));
        $this->imageId = $this->fixture->createTestImage()['id'];
        $this->otherId = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($this->imageId, $this->albumId);
        $this->fixture->attachImage($this->otherId, $this->albumId);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->ws->logout();
    }

    // ── pwg.images.setInfo ────────────────────────────────────────────────

    /** [ERR] [HAPPY] "replace" stores both fields; a date-only value is widened to midnight. */
    public function testSetInfoReplacesDateAndComment(): void
    {
        $this->setInfo(array('date_creation' => '1954-06-12', 'comment' => 'Vor dem Pfarrhaus'));

        $row = $this->photo($this->imageId);
        $this->assertSame('1954-06-12 00:00:00', $row['date_creation']);
        $this->assertSame('Vor dem Pfarrhaus', $row['comment']);
    }

    /** [ERR] [ECP] The default "fill_if_empty" leaves a field that holds a value alone. */
    public function testSetInfoFillIfEmptyKeepsStoredValues(): void
    {
        $this->setInfo(array('date_creation' => '1954-06-12', 'comment' => 'Vorher'));

        $this->setInfo(array('date_creation' => '1960-01-01', 'comment' => 'Nachher'), 'fill_if_empty');

        $row = $this->photo($this->imageId);
        $this->assertSame('1954-06-12 00:00:00', $row['date_creation']);
        $this->assertSame('Vorher', $row['comment']);
    }

    /**
     * [ERR] [ECP] The stored values posted back unchanged, as the Batch Manager's
     * unit mode does for every field of every photo it saves, leave the row as it
     * was: the date with its time, the text unchanged.
     */
    public function testSetInfoWithTheStoredValuesChangesNothing(): void
    {
        $this->setInfo(array('date_creation' => '1954-06-12 10:20:30', 'comment' => 'Gleich'));
        $before = $this->photo($this->imageId);
        $this->assertSame('1954-06-12 10:20:30', $before['date_creation'], 'anti-vacuity: the time must be stored');

        $this->setInfo(array('date_creation' => $before['date_creation'], 'comment' => $before['comment'], 'name' => 'Neu'));

        $after = $this->photo($this->imageId);
        $this->assertSame($before['date_creation'], $after['date_creation']);
        $this->assertSame($before['comment'], $after['comment']);
    }

    /**
     * [ERR] [BVA] An empty value clears the field: the Batch Manager's unit mode
     * removes a date or a description by posting it empty.
     */
    public function testSetInfoWithEmptyValuesClearsBothFields(): void
    {
        $this->setInfo(array('date_creation' => '1954-06-12', 'comment' => 'Weg'));
        $start = $this->photo($this->imageId);
        $this->assertNotNull($start['date_creation'], 'anti-vacuity: the date must start set');
        $this->assertNotNull($start['comment'], 'anti-vacuity: the text must start set');

        $this->setInfo(array('date_creation' => '', 'comment' => ''));

        $row = $this->photo($this->imageId);
        $this->assertNull($row['date_creation']);
        $this->assertNull($row['comment']);
    }

    /** [ERR] [DT] Without a pwg_token, markup other than b/strong/em/i is stripped from the text. */
    public function testSetInfoWithoutATokenStripsMostMarkup(): void
    {
        $res = $this->ws->call('pwg.images.setInfo', array(
            'image_id' => $this->imageId,
            'comment' => '<b>fett</b> <a href="x">Link</a>',
            'single_value_mode' => 'replace',
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $this->assertSame('<b>fett</b> Link', $this->photo($this->imageId)['comment']);
    }

    // ── Batch Manager, global mode, "Set creation date" ───────────────────

    /** [ERR] [HAPPY] The date reaches every selected photo, and no other. */
    public function testGlobalActionSetsTheDateOfEverySelectedPhoto(): void
    {
        $third = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($third, $this->albumId);

        $this->globalDate(array($this->imageId, $this->otherId), array('date_creation' => '1954-06-12'));

        $this->assertSame('1954-06-12 00:00:00', $this->photo($this->imageId)['date_creation']);
        $this->assertSame('1954-06-12 00:00:00', $this->photo($this->otherId)['date_creation']);
        $this->assertNull($this->photo($third)['date_creation'], 'a photo outside the selection was changed');
    }

    /** [ERR] [ST] "remove creation date" clears the date of every selected photo. */
    public function testGlobalActionRemovesTheDate(): void
    {
        $this->globalDate(array($this->imageId, $this->otherId), array('date_creation' => '1954-06-12'));
        $this->assertNotNull($this->photo($this->imageId)['date_creation'], 'anti-vacuity: the date must start set');

        $this->globalDate(array($this->imageId, $this->otherId), array(
            'date_creation' => '1954-06-12',
            'remove_date_creation' => 'on',
            ));

        $this->assertNull($this->photo($this->imageId)['date_creation']);
        $this->assertNull($this->photo($this->otherId)['date_creation']);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function setInfo(array $fields, string $mode = 'replace'): void
    {
        $res = $this->ws->call('pwg.images.setInfo', array_merge(array(
            'image_id' => $this->imageId,
            'single_value_mode' => $mode,
            'pwg_token' => $this->ws->token(),
            ), $fields));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    private function globalDate(array $ids, array $fields): void
    {
        $res = $this->ws->postPage('/admin.php?page=batch_manager&mode=global', array_merge(array(
            'pwg_token' => $this->ws->token(),
            'selection' => $ids,
            'selectAction' => 'date_creation',
            'submit' => 1,
            ), $fields));

        $this->assertSame(200, $res['http_code'], 'the Batch Manager did not answer');
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen((string)$res['body']),
            'the answer is too short to be the rendered Batch Manager');
    }

    private function photo(int $id): array
    {
        $row = $this->db->query(
            'SELECT date_creation, comment FROM `piwigo_images` WHERE id = ' . $id
        )->fetch_assoc();
        if ($row === null)
        {
            throw new RuntimeException('the fixture photo disappeared');
        }
        return $row;
    }
}
