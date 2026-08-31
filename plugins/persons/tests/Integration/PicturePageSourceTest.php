<?php
use PHPUnit\Framework\TestCase;

/**
 * What the public picture page renders for a photo that carries regions.
 *
 * Scope: page source only. The boxes are laid out in percent inside an overlay
 * that JavaScript positions over the photo, so whether they sit where the faces
 * are - and whether they survive a derivative switch - is unreachable here and
 * belongs to the E2E layer. What this layer can witness is that the server
 * emitted the stage, one box per region with the coordinates the index holds,
 * and the person row.
 *
 * The fixture forces both preconditions the row depends on - the regions and
 * the picture_informations visibility key - and puts the install back
 * afterwards.
 */
final class PicturePageSourceTest extends TestCase
{
    /** A rendered picture page shorter than this is an error page, not the photo screen. */
    private const MIN_PAGE_BYTES = 2000;

    /** Names this test seeds. Distinctive, so a leftover row is obvious. */
    private const JANE = 'Persons Page Jane Doe';
    private const JOHN = 'Persons Page John Smith';

    /** What the escaping case renames JANE to, so teardown can remove it too. */
    private const MARKUP_NAME = '<b>Jane</b><script>alert(1)</script>';

    /** The AppliedToDimensions the seeded regions are written against. */
    private const APPLIED_W = 4000;
    private const APPLIED_H = 3000;

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private array $image;
    private int $catId;
    private string $picturePath;
    private string $originalConfig;

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

        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->image = $this->fixture->createTestImage();
        $this->catId = $this->fixture->createTestAlbum('Persons page source ' . bin2hex(random_bytes(4)));
        $this->fixture->attachImage($this->image['id'], $this->catId);
        $this->fixture->invalidateUserCache();

        $this->picturePath = '/picture.php?/' . $this->image['id'] . '/category/' . $this->catId;

        $original = $this->db->scalar(
            "SELECT value FROM piwigo_config WHERE param = '" . PERSONS_DISPLAY_INFO_PARAM . "'"
        );
        if ($original === null)
        {
            throw new RuntimeException('this install has no ' . PERSONS_DISPLAY_INFO_PARAM . ' to toggle');
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
            "' WHERE param = '" . PERSONS_DISPLAY_INFO_PARAM . "'"
        );
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPersons(array(self::JANE, self::JOHN, self::MARKUP_NAME));
        $this->ws->logout();
    }

    // ── the overlay ───────────────────────────────────────────────────────

    /** [HAPPY] The page renders at all before anything is asserted about it. */
    public function testPicturePageRendersForAPhotoWithRegions(): void
    {
        $this->seed($this->twoFaces());
        $html = $this->page();

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($html), 'the picture page did not render');
        $this->assertStringNotContainsString('Fatal error', $html);
        $this->assertStringNotContainsString('Smarty Compiler', $html);
    }

    /** [HAPPY] The stage wraps the photo, so the overlay has a box to be positioned in. */
    public function testTheStageWrapsThePhotoElement(): void
    {
        $this->seed($this->twoFaces());
        $markup = $this->markup($this->page());

        $stageAt = strpos($markup, 'id="persons-stage"');
        $this->assertNotFalse($stageAt, 'the stage was not injected');

        $imageAt = strpos($markup, 'id="theMainImage"');
        $this->assertNotFalse($imageAt, 'anti-vacuity: the photo element is gone from the page');
        $this->assertGreaterThan($stageAt, $imageAt, 'the stage does not enclose the photo');

        $overlayAt = strpos($markup, 'id="persons-overlay"');
        $this->assertNotFalse($overlayAt, 'the overlay was not injected');
        $this->assertGreaterThan($imageAt, $overlayAt, 'the overlay must come after the photo it covers');
    }

    /** [BVA] The stage is injected once, not once per compiled sub-template. */
    public function testTheStageIsInjectedExactlyOnce(): void
    {
        $this->seed($this->twoFaces());
        $markup = $this->markup($this->page());

        $this->assertSame(1, substr_count($markup, 'id="persons-stage"'));
        $this->assertSame(1, substr_count($markup, 'id="persons-overlay"'));
    }

    /** [HAPPY] One box per indexed region, each carrying its region id. */
    public function testOneBoxIsRenderedPerRegion(): void
    {
        $this->seed($this->twoFaces());
        $markup = $this->markup($this->page());

        $regionIds = $this->regionIds();
        $this->assertCount(2, $regionIds, 'anti-vacuity: the fixture indexed no regions to render');

        // data-person-region, not the class: 'class="person-box' also matches
        // the label inside each box and would count every box twice.
        $this->assertSame(2, substr_count($markup, 'data-person-region='));

        foreach ($regionIds as $id)
        {
            $this->assertStringContainsString('data-person-region="' . $id . '"', $markup);
        }
    }

    /**
     * [HAPPY] A box carries the geometry the index holds, converted from MWG's
     * centre origin to the top-left corner CSS lays out from.
     *
     * The expected values are computed with the same pure helper the page uses
     * rather than typed again here - a second copy of the conversion would
     * agree with a wrong one just as happily.
     */
    public function testABoxCarriesTheGeometryOfItsRegion(): void
    {
        $this->seed(array(
            array('name' => self::JANE, 'x' => 0.5, 'y' => 0.4, 'w' => 0.2, 'h' => 0.3),
        ));

        $markup = $this->markup($this->page());
        $corner = persons_center_to_corner(0.5, 0.4, 0.2, 0.3);

        $this->assertStringContainsString(
            'left:' . persons_percent($corner['left']) . ';top:' . persons_percent($corner['top']) .
            ';width:' . persons_percent($corner['w']) . ';height:' . persons_percent($corner['h']),
            $markup
        );
    }

    /** [NEG] A guest gets no stage, no boxes and no names - regions stay behind the login. */
    public function testAGuestSeesNoOverlay(): void
    {
        $this->seed($this->twoFaces());

        $res = $this->ws->fetchPage($this->picturePath, false);
        $this->assertSame(200, $res['http_code'], 'the picture page is not reachable without logging in');

        $html = (string)$res['body'];
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($html), 'the guest page did not render');
        $this->assertStringContainsString('id="theMainImage"', $html, 'anti-vacuity: this is not the photo page');

        $this->assertStringNotContainsString('persons-stage', $html);
        $this->assertStringNotContainsString('person-box', $html);
        $this->assertStringNotContainsString('id="Persons"', $html);
        $this->assertStringNotContainsString('persons-tag-toggle', $html);

        // The *names* are a different matter and are deliberately not asserted
        // absent: every person is mirrored as an ordinary Piwigo tag, which is
        // what makes browsing work with no new code, and core renders its own
        // related-tags row on this page for guests. Hiding that would mean
        // changing core's row, not this plugin's.
    }

    /**
     * [BVA] A photo with no regions still gets the stage, and no boxes.
     *
     * Phase 5 rendered nothing here, because there was nothing to draw. The
     * editor changed that: the first face on a photo is drawn onto an empty
     * stage, so a page without one is a photo that can never be tagged.
     */
    public function testAPhotoWithNoRegionsStillGetsAnEmptyStage(): void
    {
        $markup = $this->markup($this->page());

        $this->assertSame(0, $this->regionCount(), 'anti-vacuity: this photo was expected to carry no region');
        $this->assertStringContainsString('id="theMainImage"', $markup, 'anti-vacuity: this is not the photo page');
        $this->assertStringContainsString('id="persons-stage"', $markup);
        $this->assertSame(0, substr_count($markup, 'data-person-region='));
    }

    // ── the editor ───────────────────────────────────────────────────

    /** [HAPPY] A logged-in visitor is offered the editor, on a photo with regions and without. */
    public function testTheTagButtonIsRenderedForALoggedInVisitor(): void
    {
        $withoutRegions = $this->markup($this->page());
        $this->assertStringContainsString('id="persons-tag-toggle"', $withoutRegions);

        $this->seed($this->twoFaces());
        $withRegions = $this->markup($this->page());
        $this->assertStringContainsString('id="persons-tag-toggle"', $withRegions);
    }

    /**
     * [HAPPY] The editor's configuration reaches the browser on the element
     * itself, not through a script block whose evaluation order would matter.
     */
    public function testTheEditorCarriesItsConfiguration(): void
    {
        $markup = $this->markup($this->page());

        $this->assertMatchesRegularExpression(
            '/data-persons-image="' . $this->image['id'] . '"/',
            $markup,
            'the editor does not know which photo it is on'
        );
        $this->assertMatchesRegularExpression('/data-persons-token="[0-9a-f]{4,}"/', $markup);
        $this->assertStringContainsString(
            'data-persons-min-fraction="' . PERSONS_MIN_BOX_FRACTION . '"',
            $markup,
            'the minimum box size must come from the one constant, not a second copy in JavaScript'
        );
    }

    /** [HAPPY] Every box offers a delete affordance the editor can bind to. */
    public function testEachBoxCarriesADeleteControl(): void
    {
        $this->seed($this->twoFaces());
        $markup = $this->markup($this->page());

        $this->assertCount(2, $this->regionIds(), 'anti-vacuity: the fixture indexed no regions');
        $this->assertSame(2, substr_count($markup, 'class="person-box-delete"'));
    }

    // ── the person row ────────────────────────────────────────────────────

    /** [HAPPY] The names are rendered inside <dl id="standard">, linked to their tag pages. */
    public function testThePersonRowIsRenderedInsideTheStandardInfoList(): void
    {
        $this->seed($this->twoFaces());
        $markup = $this->markup($this->page());

        $listAt = strpos($markup, '<dl id="standard"');
        $this->assertNotFalse($listAt, 'the standard info list is gone; the row had nowhere to land');

        $closeAt = strpos($markup, '</dl>', $listAt);
        $rowAt = strpos($markup, 'id="Persons"');

        $this->assertNotFalse($rowAt, 'the person row was not rendered');
        $this->assertGreaterThan($listAt, $rowAt);
        $this->assertLessThan($closeAt, $rowAt, 'the row was rendered outside <dl id="standard">');

        $row = $this->row($markup);
        $this->assertStringContainsString(self::JANE, $row);
        $this->assertStringContainsString(self::JOHN, $row);
        $this->assertSame(2, substr_count($row, '<a href='), 'each name links to its tag gallery page');
    }

    /**
     * [DT] With the visibility key off, the row is absent - and the overlay is
     * not, because the key switches one and not the other.
     */
    public function testTheRowIsAbsentWhenTheDisplayKeyIsOff(): void
    {
        $this->seed($this->twoFaces());
        $this->setDisplayKey(false);

        $markup = $this->markup($this->page());

        $this->assertStringContainsString('<dl id="standard"', $markup, 'anti-vacuity: stripping left no markup to search');
        $this->assertStringNotContainsString('id="Persons"', $markup);
        $this->assertStringContainsString('id="persons-stage"', $markup, 'the key must not switch off the overlay too');
    }

    /**
     * [NEG] Markup in a person's name is rendered as text, never as markup.
     *
     * The name cannot arrive this way through the plugin's own write paths -
     * persons_clean_name() strips tags - so it is forced straight into the row
     * here, which is exactly what the template's |escape exists for: a name that
     * reached the database some other way must not become an element on a
     * public page.
     */
    public function testMarkupInANameIsEscapedRatherThanRendered(): void
    {
        $this->seed($this->twoFaces());
        $this->db->query(
            "UPDATE piwigo_persons SET name = '" . $this->db->escape(self::MARKUP_NAME) . "'" .
            " WHERE name = '" . $this->db->escape(self::JANE) . "'"
        );

        $page = $this->page();

        $this->assertStringContainsString('&lt;b&gt;Jane&lt;/b&gt;', $page);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return array the two seeded faces */
    private function twoFaces(): array
    {
        return array(
            array('name' => self::JANE, 'x' => 0.3, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2),
            array('name' => self::JOHN, 'x' => 0.7, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2),
        );
    }

    /** Writes the regions into the file with a plain exiftool call, then indexes them. */
    private function seed(array $regions): void
    {
        $this->fixture->writeRegionsWithExiftool($this->image, $regions, self::APPLIED_W, self::APPLIED_H);

        $outcome = persons_reindex_image($this->image['id'], $this->image['file']);
        if (!$outcome['ok'])
        {
            throw new RuntimeException('fixture reindex failed: ' . $outcome['message']);
        }
        if ($outcome['regions'] !== count($regions))
        {
            throw new RuntimeException('fixture indexed ' . $outcome['regions'] . ' of ' . count($regions) . ' regions');
        }
    }

    private function regionCount(): int
    {
        return (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . (int)$this->image['id']
        );
    }

    /** @return int[] */
    private function regionIds(): array
    {
        $result = $this->db->query(
            'SELECT id FROM piwigo_person_region WHERE image_id = ' . (int)$this->image['id'] . ' ORDER BY id'
        );

        $ids = array();
        while ($row = $result->fetch_assoc())
        {
            $ids[] = (int)$row['id'];
        }

        return $ids;
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

    /** The rendered person row, or '' when it is absent. */
    private function row(string $markup): string
    {
        return preg_match('#<div id="Persons".*?</div>#s', $markup, $m) ? $m[0] : '';
    }

    private function setDisplayKey(bool $on): void
    {
        $map = unserialize($this->originalConfig);
        if (!is_array($map))
        {
            throw new RuntimeException(PERSONS_DISPLAY_INFO_PARAM . ' is not a serialized array');
        }

        $map[PERSONS_DISPLAY_INFO_KEY] = $on;

        $this->db->query(
            "UPDATE piwigo_config SET value = '" . $this->db->escape(serialize($map)) .
            "' WHERE param = '" . PERSONS_DISPLAY_INFO_PARAM . "'"
        );
    }
}
