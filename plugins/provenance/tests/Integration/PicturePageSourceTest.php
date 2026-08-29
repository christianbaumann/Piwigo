<?php
use PHPUnit\Framework\TestCase;

/**
 * What the public picture page renders for a photo that carries provenance.
 *
 * Scope: page source only. Whether the row is *visible* in a real browser, and
 * in the modus theme the install actually runs, belongs to the E2E layer and is
 * not restated here.
 *
 * The fixture forces both preconditions the row depends on - the photo's five
 * columns and the picture_informations visibility key - and puts the install
 * back afterwards.
 */
final class PicturePageSourceTest extends TestCase
{
    /** A rendered picture page shorter than this is an error page, not the photo screen. */
    private const MIN_PAGE_BYTES = 2000;

    /**
     * What the fixture writes onto the photo.
     *
     * All five columns, deliberately distinct from one another, so an assertion
     * that finds one value cannot be satisfied by another.
     */
    private const SEEDED = array(
        'provenance_physical_album' => 'Oma Müllers Fotoalbum',
        'provenance_owner'          => 'Anna Müller',
        'provenance_scanned_on'     => '2026-08-29',
        'provenance_album_note'     => 'geliehen im August',
        'provenance_note'           => 'auf der Rückseite: Sommer 1972',
        );

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private int $catId;
    private int $imageId;
    private string $picturePath;
    private string $originalConfig;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->recordAllProvenance();

        $this->catId = $this->fixture->anyAlbumId();
        $this->imageId = $this->fixture->photoIdsInAlbum($this->catId)[0];
        $this->picturePath = '/picture.php?/' . $this->imageId . '/category/' . $this->catId;

        $original = $this->db->scalar(
            "SELECT value FROM piwigo_config WHERE param = '" . PROVENANCE_DISPLAY_INFO_PARAM . "'"
        );
        if ($original === null)
        {
            throw new RuntimeException('this install has no ' . PROVENANCE_DISPLAY_INFO_PARAM . ' to toggle');
        }
        $this->originalConfig = (string)$original;

        // Force the precondition rather than hope the plugin's install() has
        // already run against this database.
        $this->setDisplayKey(true);
    }

    protected function tearDown(): void
    {
        $this->db->query(
            "UPDATE piwigo_config SET value = '" . $this->db->escape($this->originalConfig) .
            "' WHERE param = '" . PROVENANCE_DISPLAY_INFO_PARAM . "'"
        );
        // Clear first, restore second. recordAllProvenance() only remembers rows
        // that already carried provenance, so a photo that had none is not in
        // the recorded state at all and restore() alone would leave this
        // fixture's values on it for every later test to trip over.
        $this->fixture->clearImageProvenance(array($this->imageId));
        $this->fixture->restore();
        $this->ws->logout();
    }

    // ── the row ───────────────────────────────────────────────────────────

    /** [HAPPY] The page renders at all before anything is asserted about it. */
    public function testPicturePageRendersForAPhotoWithProvenance(): void
    {
        $this->seedPhoto(self::SEEDED);
        $html = $this->page();

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($html), 'the picture page did not render');
        $this->assertStringNotContainsString('Fatal error', $html);
        $this->assertStringNotContainsString('Smarty Compiler', $html);
    }

    /** [HAPPY] The row is rendered, and inside <dl id="standard"> rather than loose after it. */
    public function testRowIsRenderedInsideTheStandardInfoList(): void
    {
        $this->seedPhoto(self::SEEDED);
        $markup = $this->markup($this->page());

        $listAt = strpos($markup, '<dl id="standard"');
        $this->assertNotFalse($listAt, 'the standard info list is gone; the injection had nowhere to land');

        $closeAt = strpos($markup, '</dl>', $listAt);
        $rowAt = strpos($markup, 'id="' . $this->rowId() . '"');

        $this->assertNotFalse($rowAt, 'the provenance row was not rendered');
        $this->assertGreaterThan($listAt, $rowAt);
        $this->assertLessThan($closeAt, $rowAt, 'the row was rendered outside <dl id="standard">');
    }

    /** [HAPPY] Every seeded value reaches the row, joined by the composer's separator. */
    public function testRowCarriesEveryProvenanceValue(): void
    {
        $this->seedPhoto(self::SEEDED);
        $row = $this->row($this->page());

        $this->assertNotSame('', $row, 'the provenance row was not rendered');

        foreach (self::SEEDED as $column => $value)
        {
            $this->assertStringContainsString($value, $row, "$column is missing from the rendered row");
        }

        $this->assertSame(
            count(self::SEEDED) - 1,
            substr_count($row, PROVENANCE_CAPTION_SEPARATOR),
            'the five parts are not joined by exactly four separators'
        );
    }

    /**
     * [HAPPY] A logged-out visitor gets the row too.
     *
     * The whole point of this row is that it is public, and every other case
     * here runs as the webmaster. The handler carries no is_a_guest() guard -
     * unlike the Colored Tags one on the same page - so nothing but this test
     * says the public half of "public row" actually works.
     */
    public function testGuestGetsTheRow(): void
    {
        $this->seedPhoto(self::SEEDED);

        $res = $this->ws->fetchPage($this->picturePath, false);
        $this->assertSame(200, $res['http_code'], 'the picture page is not reachable without logging in');

        $markup = $this->markup((string)$res['body']);

        $this->assertStringContainsString('<dl id="standard"', $markup, 'anti-vacuity: stripping left no markup to search');
        $this->assertStringContainsString('id="' . $this->rowId() . '"', $markup);
        $this->assertStringContainsString(self::SEEDED['provenance_owner'], $markup);
    }

    /**
     * [NEG] Markup in a provenance value is rendered as text, never as markup.
     *
     * Decision 0009 strips tags on every write path the plugin owns, so this
     * value cannot arrive through the admin screens - it is forced straight into
     * the column here, which is exactly the case the template's |escape exists
     * for: a value that reached the database some other way (a migration, a
     * later write path, direct SQL) must not become an element on a public page.
     */
    public function testMarkupInAValueIsEscapedRatherThanRendered(): void
    {
        $this->seedPhoto(array('provenance_owner' => '<b>Anna</b><script>alert(1)</script>'));

        $page = $this->page();
        $row = $this->row($page);

        $this->assertNotSame('', $row, 'the provenance row was not rendered');
        $this->assertStringContainsString('&lt;b&gt;Anna&lt;/b&gt;', $row);
        $this->assertStringNotContainsString('<b>', $row);

        // The whole page, not just the row: an unescaped <script> would have been
        // cut out again by markup() and the row assertions above would not see it.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page);
    }

    /**
     * [DT] With the visibility key off, the row is absent.
     *
     * The stripping guard runs first: a markup() that removed too much would
     * make this absence assertion pass on any page at all.
     */
    public function testRowIsAbsentWhenTheDisplayKeyIsOff(): void
    {
        $this->seedPhoto(self::SEEDED);
        $this->setDisplayKey(false);

        $markup = $this->markup($this->page());

        $this->assertStringContainsString('<dl id="standard"', $markup, 'anti-vacuity: stripping left no markup to search');
        $this->assertStringNotContainsString('id="' . $this->rowId() . '"', $markup);
    }

    /** [NEG] A photo with no provenance at all gets no row, and no empty labels. */
    public function testRowIsAbsentWhenThePhotoHasNoProvenance(): void
    {
        $this->fixture->clearImageProvenance(array($this->imageId));

        $markup = $this->markup($this->page());

        $this->assertStringContainsString('<dl id="standard"', $markup, 'anti-vacuity: stripping left no markup to search');
        $this->assertStringNotContainsString('id="' . $this->rowId() . '"', $markup);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * The row's element id, read out of the injected template rather than typed
     * again here, so this cannot drift away from what the prefilter injects.
     */
    private function rowId(): string
    {
        $tpl = (string)file_get_contents(PROVENANCE_PATH . 'template/public_provenance.tpl');
        $this->assertMatchesRegularExpression(
            '/id="[A-Za-z-]+"/',
            $tpl,
            'anti-vacuity: the injected template carries no element id for these checks to look for'
        );

        preg_match('/id="([A-Za-z-]+)"/', $tpl, $m);
        return $m[1];
    }

    private function seedPhoto(array $values): void
    {
        $this->fixture->imageProvenance($this->imageId, $values);
    }

    private function page(): string
    {
        $res = $this->ws->fetchPage($this->picturePath);
        $this->assertSame(200, $res['http_code'], 'picture page must load before its source can be asserted on');
        return (string)$res['body'];
    }

    /**
     * The page with every <script> block removed.
     *
     * The trap docs/agents/TESTING.md records for typetags: an element name that
     * also appears inside injected JavaScript is found by a raw-body scan even
     * when the server rendered nothing. This layer is about what the server
     * rendered, so the scripts go first.
     */
    private function markup(string $html): string
    {
        return (string)preg_replace('#<script\b.*?</script>#is', '', $html);
    }

    /** The rendered provenance row, or '' when it is absent. */
    private function row(string $html): string
    {
        return preg_match('#<div id="' . $this->rowId() . '".*?</div>#s', $this->markup($html), $m) ? $m[0] : '';
    }

    private function setDisplayKey(bool $on): void
    {
        $map = unserialize($this->originalConfig);
        if (!is_array($map))
        {
            throw new RuntimeException(PROVENANCE_DISPLAY_INFO_PARAM . ' is not a serialized array');
        }

        $map[PROVENANCE_DISPLAY_INFO_KEY] = $on;

        $this->db->query(
            "UPDATE piwigo_config SET value = '" . $this->db->escape(serialize($map)) .
            "' WHERE param = '" . PROVENANCE_DISPLAY_INFO_PARAM . "'"
        );
    }
}
