<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Info row in the picture page's source, per account.
 *
 * Server-rendered markup only: whether a click opens the editor is the E2E
 * suite's to witness. Absence of the form in the source is the whole [NEG]
 * fact - an account that never receives it cannot reveal it.
 *
 * Every logged-in account edits (decision 0058), so the read-only rows are a
 * guest's alone, and a guest reaches the page only with guest_access on
 * (decision 0055). The guest cases switch it on with a config row, which
 * load_conf_from_db() lets override local/config, and tearDown deletes it. A
 * run killed in between leaves the gallery open to guests: delete the row by
 * hand.
 */
final class PicturePageSourceTest extends TestCase
{
    /** A picture page shorter than this is an error page, on which "absent" says nothing. */
    private const MIN_PAGE_BYTES = 5000;

    private const ROW = 'id="PhotoInfo"';
    private const FORM = 'id="photoinfo-info-form"';
    private const CORE_DESCRIPTION = 'class="imageComment"';
    private const COMMENT = 'Die Taufe im Garten';
    private const DATE_ROW = 'id="PhotoDate"';
    private const DATE_FORM = 'id="photoinfo-date-form"';
    private const CORE_DATE_ROW = 'id="datecreate"';
    private const GUEST_ACCESS_PARAM = 'guest_access';

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private string $picturePath;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('photoinfo');
        // Never take over a row someone set on purpose; one left by a killed run is deleted by hand.
        $this->assertNull($this->guestAccessRow(), 'a guest_access config row exists; delete it if a killed run left it');

        $this->image = $this->fixture->createTestImage();
        $album = $this->fixture->createTestAlbum('Photoinfo test ' . bin2hex(random_bytes(4)));
        $this->fixture->attachImage((int)$this->image['id'], $album);
        $this->fixture->invalidateUserCache();

        $this->picturePath = '/picture.php?/' . $this->image['id'] . '/category/' . $album;
    }

    protected function tearDown(): void
    {
        $this->db->query("DELETE FROM piwigo_config WHERE param = '" . self::GUEST_ACCESS_PARAM . "'");
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    public static function allAccounts(): array
    {
        return array(
            'webmaster' => array(TestUsers::WEBMASTER, true),
            'administrator' => array(TestUsers::ADMIN, true),
            'normal user' => array(TestUsers::NORMAL, true),
            'guest' => array(null, false),
            );
    }

    /**
     * [DT] Everyone sees the description in the row and not above the photo;
     * every logged-in account gets the editor, a guest does not.
     */
    #[DataProvider('allAccounts')]
    public function testTheDescriptionShowsInTheRowOnly(?string $role, bool $editable): void
    {
        $this->fixture->setComment($this->image['id'], self::COMMENT);

        $page = $this->pageAs($role);
        $row = $this->rowOf($page);

        $this->assertStringContainsString(self::COMMENT, $row);
        $this->assertStringNotContainsString(self::CORE_DESCRIPTION, $page);
        $this->assertSame($editable ? 1 : 0, substr_count($page, self::FORM));
    }

    /** [ECP] With no description, a guest gets no row at all. */
    public function testNoDescriptionNoRowForAGuest(): void
    {
        $this->assertSame(0, substr_count($this->pageAs(null), self::ROW));
    }

    /** [BVA] A description of "0" is none to core, so a guest gets no row either. */
    public function testADescriptionOfZeroGivesAGuestNoRow(): void
    {
        $this->fixture->setComment($this->image['id'], '0');

        $this->assertSame(0, substr_count($this->pageAs(null), self::ROW));
    }

    public static function editors(): array
    {
        return array(
            'administrator' => array(TestUsers::ADMIN),
            'normal user' => array(TestUsers::NORMAL),
            );
    }

    /** [ECP] With no description, an account that may edit gets the row to add one. */
    #[DataProvider('editors')]
    public function testNoDescriptionStillARowForAnEditor(string $role): void
    {
        $page = $this->pageAs($role);

        $this->assertSame(1, substr_count($page, self::ROW));
        $this->assertSame(1, substr_count($page, self::FORM));
    }

    /** [HAPPY] The row renders the description the way core rendered it above the photo. */
    public function testTheRowRendersMarkupLikeCore(): void
    {
        $this->fixture->setComment($this->image['id'], 'Oma <b>und</b> Opa');

        $this->assertStringContainsString('Oma <b>und</b> Opa', $this->rowOf($this->pageAs(TestUsers::NORMAL)));
    }

    /** [HAPPY] The editor's textarea holds the raw text, escaped. */
    public function testTheTextareaHoldsTheRawTextEscaped(): void
    {
        $this->fixture->setComment($this->image['id'], 'Oma <b>und</b> Opa');

        $this->assertStringContainsString(
            '<textarea name="info" rows="5">Oma &lt;b&gt;und&lt;/b&gt; Opa</textarea>',
            $this->pageAs(TestUsers::ADMIN)
        );
    }

    /** [NEG] The provenance row stays provenance-only on a photo with a description. */
    public function testTheProvenanceRowDoesNotCarryTheDescription(): void
    {
        $this->fixture->setComment($this->image['id'], self::COMMENT);
        $this->fixture->setProvenance($this->image['id'], array('provenance_owner' => 'Anna Mueller'));

        $page = $this->pageAs(TestUsers::NORMAL);
        $this->assertSame(1, preg_match('~<div id="Provenance" class="imageInfo">(.*?)</div>~s', $page, $m), 'anti-vacuity: no provenance row');
        $this->assertStringContainsString('Anna Mueller', $m[1]);
        $this->assertStringNotContainsString(self::COMMENT, $m[1]);
    }

    /**
     * [DT] Everyone sees the date in German in the Datum row, core's "Created
     * on" row is gone, and every logged-in account gets the editor.
     */
    #[DataProvider('allAccounts')]
    public function testTheDateShowsInTheDatumRow(?string $role, bool $editable): void
    {
        $this->fixture->setDate($this->image['id'], '1965-03-01 00:00:00', 'month');

        $page = $this->pageAs($role);

        $this->assertStringContainsString('März 1965', $this->dateRowOf($page));
        $this->assertStringNotContainsString(self::CORE_DATE_ROW, $page);
        $this->assertSame($editable ? 1 : 0, substr_count($page, self::DATE_FORM));
    }

    /** [HAPPY] A guest's date links to the calendar at the date's own precision. */
    public function testTheDateLinksToTheCalendar(): void
    {
        $this->fixture->setDate($this->image['id'], '1965-03-01 00:00:00', 'month');

        $this->assertMatchesRegularExpression('~href="[^"]*created-monthly-list-1965-03"~', $this->dateRowOf($this->pageAs(null)));
    }

    /** [ECP] With no date, a guest gets no Datum row - and no core row either. */
    public function testNoDateNoRowForAGuest(): void
    {
        $page = $this->pageAs(null);

        $this->assertSame(0, substr_count($page, self::DATE_ROW));
        $this->assertSame(0, substr_count($page, self::CORE_DATE_ROW));
    }

    /** [ECP] With no date, an account that may edit gets the row to add one, with empty controls. */
    #[DataProvider('editors')]
    public function testNoDateStillARowForAnEditor(string $role): void
    {
        $row = $this->dateRowOf($this->pageAs($role));

        $this->assertSame(1, substr_count($row, self::DATE_FORM));
        $this->assertMatchesRegularExpression('~<input type="text" name="year" inputmode="numeric"[^>]* value="">~', $row);
    }

    /** [HAPPY] The editor starts from the saved date. */
    public function testTheEditorStartsFromTheSavedDate(): void
    {
        $this->fixture->setDate($this->image['id'], '1965-03-14 00:00:00', 'day');

        $row = $this->dateRowOf($this->pageAs(TestUsers::ADMIN));

        $this->assertMatchesRegularExpression('~<input type="text" name="year"[^>]* value="1965">~', $row);
        $this->assertStringContainsString('<option value="3" selected>März</option>', $row);
        $this->assertStringContainsString('data-day="14"', $row);
    }

    /** [HAPPY] A guest sees a range in German, linked to the calendar at its start. */
    public function testARangeShowsToAGuest(): void
    {
        $this->fixture->setDate($this->image['id'], '1965-03-01 00:00:00', 'month');
        $this->fixture->setQualifier($this->image['id'], 'between', '1970-01-01', 'year');

        $row = $this->dateRowOf($this->pageAs(null));

        $this->assertStringContainsString("März 1965\u{2013}1970</a>", $row);
        $this->assertMatchesRegularExpression('~href="[^"]*created-monthly-list-1965-03"~', $row);
    }

    /** [HAPPY] The editor starts from a saved range: qualifier, start and end. */
    public function testTheEditorStartsFromASavedRange(): void
    {
        $this->fixture->setDate($this->image['id'], '1965-03-01 00:00:00', 'month');
        $this->fixture->setQualifier($this->image['id'], 'between', '1970-05-02', 'day');

        $row = $this->dateRowOf($this->pageAs(TestUsers::ADMIN));

        $this->assertStringContainsString('<option value="between" selected>zwischen</option>', $row);
        $this->assertMatchesRegularExpression('~<input type="text" name="year"[^>]* value="1965">~', $row);
        $this->assertMatchesRegularExpression('~<input type="text" name="end_year"[^>]* value="1970">~', $row);
        $this->assertMatchesRegularExpression('~<select name="end_month"[^>]*>.*<option value="5" selected>Mai</option>~s', $row);
        $this->assertStringContainsString('data-day="2"', $row);
    }

    /** @param string|null $role null for a guest, with guest_access switched on for the request */
    private function pageAs(?string $role): string
    {
        $client = new WsClient();
        if ($role === null)
        {
            $this->allowGuests();
        }
        else
        {
            list($username, $password) = Config::credentials($role);
            $client->login($username, $password);
        }

        $page = $client->fetchPage($this->picturePath);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($page), 'anti-vacuity: not a picture page');
        $this->assertStringContainsString('id="theMainImage"', $page, 'anti-vacuity: the photo is not on the page');

        return $page;
    }

    private function allowGuests(): void
    {
        $this->db->query("INSERT INTO piwigo_config (param, value) VALUES ('" . self::GUEST_ACCESS_PARAM . "', 'true')");
        $this->assertSame('true', $this->guestAccessRow(), 'the guest_access row was not written');
    }

    private function guestAccessRow(): ?string
    {
        $value = $this->db->scalar("SELECT value FROM piwigo_config WHERE param = '" . self::GUEST_ACCESS_PARAM . "'");

        return $value === null ? null : (string)$value;
    }

    /** The Info row's markup, up to the next row. */
    private function rowOf(string $page): string
    {
        $this->assertSame(1, substr_count($page, self::ROW), 'expected exactly one Info row');

        $start = strpos($page, self::ROW);
        $end = strpos($page, 'id="datepost"', $start);
        $this->assertNotFalse($end, 'anti-vacuity: the Info row does not sit before the "Posted on" row');

        return substr($page, $start, $end - $start);
    }

    /** The Datum row's markup, up to the next row. */
    private function dateRowOf(string $page): string
    {
        $this->assertSame(1, substr_count($page, self::DATE_ROW), 'expected exactly one Datum row');

        $start = strpos($page, self::DATE_ROW);
        $end = strpos($page, 'id="datepost"', $start);
        $this->assertNotFalse($end, 'anti-vacuity: the Datum row does not sit before the "Posted on" row');

        return substr($page, $start, $end - $start);
    }
}
