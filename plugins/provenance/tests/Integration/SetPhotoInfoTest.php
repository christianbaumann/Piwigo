<?php
use PHPUnit\Framework\TestCase;

/**
 * The photo's own note across its real boundaries: pwg.provenance.setPhotoInfo
 * over ws.php into MariaDB, and the prefilter's injection in the rendered photo
 * properties screen.
 *
 * The note this method writes is the one half of a photo's provenance that no
 * album operation may ever touch, so the tests below watch both directions: the
 * method writes it, and it writes nothing else.
 */
final class SetPhotoInfoTest extends TestCase
{
    private const METHOD = 'pwg.provenance.setPhotoInfo';
    private const HISTORY_TABLE = 'piwigo_provenance_history';

    /** A rendered admin page shorter than this is an error page, not the photo screen. */
    private const MIN_PAGE_BYTES = 2000;

    /** The album-sourced values the photo carries, which this method must leave alone. */
    private const INHERITED = array(
        'provenance_physical_album' => 'Oma Müllers Fotoalbum',
        'provenance_owner' => 'Anna Müller',
        'provenance_scanned_on' => '2026-04-19',
        'provenance_album_note' => 'geliehen von Anna',
        );

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private int $imageId;
    private int $baselineHistoryId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->recordAllProvenance();

        $catId = $this->fixture->anyAlbumId();
        $this->imageId = $this->fixture->photoIdsInAlbum($catId)[0];

        // Force the precondition: a photo carrying its album's values and no note
        // of its own.
        $this->fixture->imageProvenance($this->imageId, self::INHERITED);

        $this->baselineHistoryId = (int)$this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM ' . self::HISTORY_TABLE);
    }

    protected function tearDown(): void
    {
        $this->fixture->clearImageProvenance(array($this->imageId));
        $this->fixture->restore();
        $this->db->query('DELETE FROM ' . self::HISTORY_TABLE . ' WHERE id > ' . $this->baselineHistoryId);
        $this->ws->logout();
    }

    // ── the save ──────────────────────────────────────────────────────────

    /** [HAPPY] The note is written and read back verbatim, line breaks included. */
    public function testTheNoteIsWritten(): void
    {
        $note = "auf der Rückseite: Sommer 1972\nzweite Zeile";

        $res = $this->save($note);

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame($note, $this->photo()['provenance_note']);
    }

    /**
     * [NEG] Nothing but the note moves.
     *
     * The four album-sourced columns are album-authoritative: a photo-level save
     * that touched them would let one photo drift away from its album with
     * nothing saying so.
     */
    public function testNoAlbumSourcedColumnIsTouched(): void
    {
        $this->save('meine Notiz');

        $photo = $this->photo();
        foreach (self::INHERITED as $column => $expected)
        {
            $this->assertSame($expected, $photo[$column], "$column was changed by a photo-level save");
        }
    }

    /** [BVA] An empty note clears the column to NULL, not to ''. */
    public function testAnEmptyNoteClearsTheColumn(): void
    {
        $this->save('etwas');
        $this->assertNotNull($this->photo()['provenance_note'], 'anti-vacuity: nothing was set, so nothing can be cleared');

        $this->save('');

        $this->assertNull($this->photo()['provenance_note']);
    }

    /** [ECP] Markup is stripped; this text is bound for an EXIF packet. */
    public function testMarkupIsStripped(): void
    {
        $this->save('<i>Notiz</i>');

        $this->assertSame('Notiz', $this->photo()['provenance_note']);
    }

    /** [DT] A change is recorded as photo_edit; a re-save of the same text is not. */
    public function testHistoryRecordsARealChangeOnly(): void
    {
        $this->save('erste Notiz');

        $this->assertSame(1, $this->historyCount());
        $this->assertSame('photo_edit', $this->historyValue('source'));
        $this->assertSame('photo', $this->historyValue('object'));
        $this->assertSame('provenance_note', $this->historyValue('field'));

        $this->save('erste Notiz');

        $this->assertSame(1, $this->historyCount(), 'a re-save of unchanged text must record nothing');
    }

    // ── the guards ────────────────────────────────────────────────────────

    /** [NEG] A guest cannot save. */
    public function testGuestIsRefused(): void
    {
        $guest = new WsClient();
        $res = $guest->call(self::METHOD, array(
            'image_id' => $this->imageId,
            'note' => 'sneaked in',
            'pwg_token' => 'irrelevant',
        ));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(401, $res['json']['err']);
        $this->assertNull($this->photo()['provenance_note']);
    }

    /** [NEG] An authenticated non-admin is refused too - the only proof the admin gate exists. */
    public function testAuthenticatedNonAdminIsRefused(): void
    {
        $normal = new WsClient();
        $normal->login(Config::normalUsername(), Config::normalPassword());

        $res = $normal->call(self::METHOD, array(
            'image_id' => $this->imageId,
            'note' => 'sneaked in',
            'pwg_token' => $normal->token(),
        ));
        $normal->logout();

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertNull($this->photo()['provenance_note']);
    }

    /** [NEG] A wrong CSRF token is refused with 403. */
    public function testBadTokenIsRefused(): void
    {
        $res = $this->ws->call(self::METHOD, array(
            'image_id' => $this->imageId,
            'note' => 'sneaked in',
            'pwg_token' => 'not-the-token',
        ));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(403, $res['json']['err']);
        $this->assertNull($this->photo()['provenance_note']);
    }

    /** [NEG] A photo that exists in no row is refused with 404. */
    public function testUnknownPhotoIsRefused(): void
    {
        $unknown = (int)$this->db->scalar('SELECT MAX(id) + 1000 FROM piwigo_images');

        $res = $this->save('nowhere', $unknown);

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(404, $res['json']['err']);
    }

    // ── the injection ─────────────────────────────────────────────────────

    /** [HAPPY] The photo properties screen carries the injected block, pre-filled. */
    public function testPhotoPageCarriesTheInjectedBlock(): void
    {
        $this->save('auf der Rückseite: Sommer 1972');

        $res = $this->ws->fetchPage('/admin.php?page=photo-' . $this->imageId . '-properties');

        $this->assertSame(200, $res['http_code']);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($res['body']), 'the photo screen did not render');
        $this->assertStringContainsString('id="provenance-photo-note"', $res['body']);
        $this->assertStringContainsString('auf der Rückseite: Sommer 1972', $res['body']);
        $this->assertStringContainsString(self::INHERITED['provenance_physical_album'], $res['body']);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function save(string $note, ?int $imageId = null): array
    {
        return $this->ws->call(self::METHOD, array(
            'image_id' => $imageId ?? $this->imageId,
            'note' => $note,
            'pwg_token' => $this->ws->token(),
        ));
    }

    private function photo(): array
    {
        return $this->fixture->readImageProvenance($this->imageId);
    }

    private function historyCount(): int
    {
        return (int)$this->db->scalar(
            'SELECT COUNT(*) FROM ' . self::HISTORY_TABLE . ' WHERE id > ' . $this->baselineHistoryId
        );
    }

    /** One column of the single history row this test expects. */
    private function historyValue(string $column): ?string
    {
        return $this->db->scalar(
            'SELECT `' . $column . '` FROM ' . self::HISTORY_TABLE .
            ' WHERE id > ' . $this->baselineHistoryId . ' ORDER BY id LIMIT 1'
        );
    }
}
