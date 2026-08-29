<?php
use PHPUnit\Framework\TestCase;

/**
 * The album save across its real boundaries: pwg.provenance.setAlbumInfo over
 * ws.php into MariaDB, and the prefilter's injection in the rendered admin page.
 *
 * The fixture forces a known album state and asserts it took effect before any
 * test body runs; the original provenance values of the whole install are
 * snapshotted and put back afterwards, so a real install is left as it was.
 */
final class SetAlbumInfoTest extends TestCase
{
    private const METHOD = 'pwg.provenance.setAlbumInfo';
    private const HISTORY_TABLE = 'piwigo_provenance_history';

    /** A rendered admin page shorter than this is an error page, not the album screen. */
    private const MIN_PAGE_BYTES = 2000;

    /** The injected template must carry at least this many element ids for the page check to mean anything. */
    private const MIN_INJECTED_IDS = 5;

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private int $catId;
    private int $baselineHistoryId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->recordAllProvenance();

        $catId = $this->db->scalar('SELECT MIN(id) FROM piwigo_categories');
        if ($catId === null)
        {
            throw new RuntimeException('this install has no album to save provenance against');
        }
        $this->catId = (int)$catId;

        // Force the precondition rather than hope for it: every test below starts
        // from an album with no provenance at all.
        $this->clearAlbum();
        $this->assertSame(
            array(null, null, null, null),
            array_values($this->album()),
            'the fixture did not take effect: the album still carries provenance'
        );

        $this->baselineHistoryId = (int)$this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM ' . self::HISTORY_TABLE);
    }

    protected function tearDown(): void
    {
        $this->clearAlbum();
        $this->fixture->restore();
        $this->db->query('DELETE FROM ' . self::HISTORY_TABLE . ' WHERE id > ' . $this->baselineHistoryId);
        $this->ws->logout();
    }

    // ── the save ──────────────────────────────────────────────────────────

    /** [HAPPY] All four columns are written and read back verbatim. */
    public function testSaveWritesAllFourColumns(): void
    {
        $note = "geliehen von Anna\nzweite Zeile";

        $res = $this->save(array(
            'physical_album' => 'Oma Müllers Fotoalbum',
            'owner' => 'Anna Müller',
            'scanned_on' => '2026-08-29',
            'note' => $note,
        ));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(
            array(
                'provenance_physical_album' => 'Oma Müllers Fotoalbum',
                'provenance_owner' => 'Anna Müller',
                'provenance_scanned_on' => '2026-08-29',
                'provenance_note' => $note,
            ),
            $this->album()
        );
    }

    /** [BVA] Empty input clears a previously set field to NULL, not to ''. */
    public function testEmptyInputClearsAFieldToNull(): void
    {
        $this->save(array('physical_album' => 'Album 3', 'owner' => 'Anna', 'scanned_on' => '2026-08-29', 'note' => 'x'));
        $this->assertNotNull($this->album()['provenance_owner'], 'anti-vacuity: nothing was set, so nothing can be cleared');

        $res = $this->save(array('physical_album' => '', 'owner' => '', 'scanned_on' => '', 'note' => ''));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(array(null, null, null, null), array_values($this->album()));
    }

    /** [ECP] Markup is stripped before storage; this text is bound for an EXIF packet. */
    public function testMarkupIsStripped(): void
    {
        $this->save(array('physical_album' => '<b>Album 3</b>', 'owner' => '', 'scanned_on' => '', 'note' => '<i>Notiz</i>'));

        $album = $this->album();
        $this->assertSame('Album 3', $album['provenance_physical_album']);
        $this->assertSame('Notiz', $album['provenance_note']);
    }

    /** [HAPPY] Every changed field leaves an album_edit history row; an unchanged one leaves none. */
    public function testHistoryRecordsTheChangedFieldsOnly(): void
    {
        $this->save(array('physical_album' => 'Album 3', 'owner' => 'Anna', 'scanned_on' => '', 'note' => ''));

        $first = $this->historyFields();
        $this->assertSame(array('provenance_owner', 'provenance_physical_album'), $first);

        $this->save(array('physical_album' => 'Album 3', 'owner' => 'Berta', 'scanned_on' => '', 'note' => ''));

        $this->assertSame(
            array('provenance_owner', 'provenance_owner', 'provenance_physical_album'),
            $this->historyFields(),
            'a re-save must record only the field that really changed'
        );
        $this->assertSame(
            array('album_edit'),
            array_values(array_unique($this->historyColumn('source'))),
            'every row this method writes is an album_edit'
        );
    }

    // ── the guards ────────────────────────────────────────────────────────

    /** [NEG] A guest cannot save. */
    public function testGuestIsRefused(): void
    {
        $guest = new WsClient();
        $res = $guest->call(self::METHOD, array(
            'cat_id' => $this->catId,
            'physical_album' => 'sneaked in',
            'pwg_token' => 'irrelevant',
        ));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(401, $res['json']['err']);
        $this->assertNull($this->album()['provenance_physical_album']);
    }

    /**
     * [NEG] An authenticated non-admin cannot save either.
     *
     * The admin gate is only proven by an account that is logged in and still
     * refused; the guest case alone would pass on a method with no gate at all.
     */
    public function testAuthenticatedNonAdminIsRefused(): void
    {
        $normal = new WsClient();
        $normal->login(Config::normalUsername(), Config::normalPassword());

        $res = $normal->call(self::METHOD, array(
            'cat_id' => $this->catId,
            'physical_album' => 'sneaked in',
            'pwg_token' => $normal->token(),
        ));
        $normal->logout();

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertNull($this->album()['provenance_physical_album']);
    }

    /** [NEG] A wrong CSRF token is refused with 403. */
    public function testBadTokenIsRefused(): void
    {
        $res = $this->ws->call(self::METHOD, array(
            'cat_id' => $this->catId,
            'physical_album' => 'sneaked in',
            'pwg_token' => 'not-the-token',
        ));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(403, $res['json']['err']);
        $this->assertNull($this->album()['provenance_physical_album']);
    }

    /** [NEG] An album id that exists in no row is refused with 404. */
    public function testUnknownAlbumIsRefused(): void
    {
        $unknown = (int)$this->db->scalar('SELECT MAX(id) + 1000 FROM piwigo_categories');

        $res = $this->save(array('physical_album' => 'nowhere'), $unknown);

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(404, $res['json']['err']);
    }

    /** [NEG] A date that does not exist is refused with 400 and nothing is written. */
    public function testMalformedDateIsRefused(): void
    {
        $res = $this->save(array('scanned_on' => '2026-02-29'));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(400, $res['json']['err']);
        $this->assertSame(array(null, null, null, null), array_values($this->album()));
    }

    /** [BVA] Text one character past the column width is refused, never truncated. */
    public function testOverLongTextIsRefused(): void
    {
        $res = $this->save(array('physical_album' => str_repeat('a', PROVENANCE_SHORT_TEXT_MAX_CHARS + 1)));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(400, $res['json']['err']);
        $this->assertNull($this->album()['provenance_physical_album']);
    }

    /** [BVA] Text exactly at the column width is accepted. */
    public function testTextAtTheCapIsAccepted(): void
    {
        $value = str_repeat('a', PROVENANCE_SHORT_TEXT_MAX_CHARS);

        $res = $this->save(array('physical_album' => $value));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame($value, $this->album()['provenance_physical_album']);
    }

    // ── the injection ─────────────────────────────────────────────────────

    /**
     * [HAPPY] The album properties page really carries the injected block.
     *
     * The element ids are read out of the injected template rather than typed
     * again here, so this cannot drift away from what the prefilter injects.
     */
    public function testAlbumPageCarriesTheInjectedBlock(): void
    {
        $tpl = (string)file_get_contents(PROVENANCE_PATH . 'template/album_provenance.tpl');
        preg_match_all('/id="([a-z-]+)"/', $tpl, $matches);
        $ids = array_unique($matches[1]);

        $this->assertGreaterThanOrEqual(
            self::MIN_INJECTED_IDS,
            count($ids),
            'anti-vacuity: too few ids were read out of the template for this check to say anything'
        );

        $res = $this->ws->fetchPage('/admin.php?page=album-' . $this->catId . '-properties');

        $this->assertSame(200, $res['http_code']);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($res['body']), 'the album screen did not render');

        foreach ($ids as $id)
        {
            $this->assertStringContainsString('id="' . $id . '"', $res['body'], "the injected element #$id is missing");
        }
    }

    /** [HAPPY] Saved values come back pre-filled in the page the next time it renders. */
    public function testSavedValuesAreRenderedBackIntoTheForm(): void
    {
        $this->save(array('physical_album' => 'Oma Müllers Fotoalbum', 'owner' => 'Anna Müller', 'scanned_on' => '2026-08-29', 'note' => 'geliehen'));

        $res = $this->ws->fetchPage('/admin.php?page=album-' . $this->catId . '-properties');

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($res['body']), 'the album screen did not render');
        $this->assertStringContainsString('Oma Müllers Fotoalbum', $res['body']);
        $this->assertStringContainsString('value="2026-08-29"', $res['body']);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function save(array $values, ?int $catId = null): array
    {
        return $this->ws->call(self::METHOD, array_merge(
            array('cat_id' => $catId ?? $this->catId, 'pwg_token' => $this->ws->token()),
            $values
        ));
    }

    /** The album's four provenance columns, in the schema's own order. */
    private function album(): array
    {
        $columns = FixtureBuilder::albumColumns();
        $result = $this->db->query(
            'SELECT `' . implode('`, `', $columns) . '` FROM piwigo_categories WHERE id = ' . $this->catId
        );
        return $result->fetch_assoc();
    }

    private function clearAlbum(): void
    {
        $nulls = array_map(fn($c) => "`$c` = NULL", FixtureBuilder::albumColumns());
        $this->db->query('UPDATE piwigo_categories SET ' . implode(', ', $nulls) . ' WHERE id = ' . $this->catId);
    }

    /** The fields this test's own history rows are about, sorted so order is not asserted. */
    private function historyFields(): array
    {
        $fields = $this->historyColumn('field');
        sort($fields);
        return $fields;
    }

    /** One column of the history rows this test wrote, oldest first. */
    private function historyColumn(string $column): array
    {
        $result = $this->db->query(
            'SELECT `' . $column . '` FROM ' . self::HISTORY_TABLE .
            ' WHERE id > ' . $this->baselineHistoryId . ' ORDER BY id'
        );
        $out = array();
        while ($row = $result->fetch_row())
        {
            $out[] = $row[0];
        }
        return $out;
    }
}
