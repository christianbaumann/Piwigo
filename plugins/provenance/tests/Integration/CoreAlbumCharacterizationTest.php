<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for core album creation and description: create_virtual_category()
 * in admin/include/functions.php, reached through pwg.categories.add and
 * pwg.categories.setInfo.
 *
 * Every case here is [ERR]: the oracle is the current implementation, not a
 * requirement. Nothing promises that a new top-level album takes its own id as
 * uppercats, that a description arrives NULL rather than empty, or that markup
 * survives only when a token is sent - these record that it does today. They
 * report a change; they do not prove the behaviour right.
 *
 * They land and pass on their first run, which is normally the tell that a test
 * recorded code rather than drove it. Here that is the point, so each was
 * watched go red by breaking the behaviour it claims to watch.
 *
 * Written because the German handbook documents this workflow and it had no test
 * at any layer. Drives the real boundary: the two ws.php methods the admin album
 * screens POST (albums.js:219-241 creates, cat_modify.js:70-85 saves).
 */
final class CoreAlbumCharacterizationTest extends TestCase
{
    /** piwigo_categories.comment is TEXT, so 65535 bytes is the column's ceiling. */
    private const COMMENT_COLUMN_BYTES = 65535;

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->fixture = new FixtureBuilder($this->db);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestAlbums();
        $this->ws->logout();
    }

    // ── creation ──────────────────────────────────────────────────────────

    /** [HAPPY] pwg.categories.add with a name only returns an id and creates the row. */
    public function testAddingAnAlbumReturnsAnIdAndCreatesTheRow(): void
    {
        $name = $this->uniqueName('add');

        $id = $this->addAlbum($name);

        $this->assertGreaterThan(0, $id, 'the call returned no album id');
        $this->assertSame($name, $this->column($id, 'name'));
    }

    /**
     * [ERR] A new top-level album's uppercats is its own id.
     *
     * Core inserts the row and only then writes uppercats, because the value
     * contains the id the insert has just generated. Records the two-step.
     */
    public function testANewTopLevelAlbumTakesItsOwnIdAsUppercats(): void
    {
        $id = $this->addAlbum($this->uniqueName('uppercats'));

        $this->assertSame((string)$id, $this->column($id, 'uppercats'));
        $this->assertNull($this->column($id, 'id_uppercat'), 'a top-level album has no parent');
    }

    /** [ECP] With a parent, uppercats is the parent's path plus the new id. */
    public function testAddingWithAParentNestsTheAlbum(): void
    {
        $parent = $this->addAlbum($this->uniqueName('parent'));
        $child = $this->addAlbum($this->uniqueName('child'), array('parent' => $parent));

        $this->assertSame((string)$parent, $this->column($child, 'id_uppercat'));
        $this->assertSame("$parent,$child", $this->column($child, 'uppercats'));
    }

    /** [BVA] With no comment sent, the description column is NULL, not an empty string. */
    public function testANewAlbumHasAnEmptyDescription(): void
    {
        $id = $this->addAlbum($this->uniqueName('nodesc'));

        $this->assertNull($this->column($id, 'comment'));
    }

    /** [NEG] [BVA] A name that is empty or only blanks is refused and creates nothing. */
    public function testAnEmptyNameIsRefused(): void
    {
        $before = $this->albumCount();

        foreach (array('', '   ') as $name)
        {
            $res = $this->ws->call('pwg.categories.add', array('name' => $name));
            $this->assertSame('fail', $res['json']['stat'] ?? null, "name '$name' was accepted: " . $res['body']);
        }

        $this->assertSame($before, $this->albumCount(), 'a refused call must create no album');
    }

    /** [NEG] The method is admin_only, so an unauthenticated caller is refused. */
    public function testAGuestCannotAddAnAlbum(): void
    {
        $before = $this->albumCount();

        $res = $this->ws->call('pwg.categories.add', array('name' => $this->uniqueName('guest')), false);

        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame($before, $this->albumCount(), 'a refused call must create no album');
    }

    /** [NEG] An authenticated non-admin is refused too - the gate is on status, not on login. */
    public function testANormalUserCannotAddAnAlbum(): void
    {
        $before = $this->albumCount();

        $normal = new WsClient();
        $normal->login(Config::normalUsername(), Config::normalPassword());
        $res = $normal->call('pwg.categories.add', array('name' => $this->uniqueName('normal')));
        $normal->logout();

        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame($before, $this->albumCount(), 'a refused call must create no album');
    }

    /** [ERR] Umlauts survive the round trip, which this German install needs. */
    public function testAUnicodeNameSurvivesTheRoundTrip(): void
    {
        $name = 'Provenance Röntgenstraße Äöü ' . bin2hex(random_bytes(4));

        $id = $this->addAlbum($name);

        $this->assertSame($name, $this->column($id, 'name'));
    }

    // ── description ───────────────────────────────────────────────────────

    /** [HAPPY] pwg.categories.setInfo stores the description. */
    public function testSetInfoStoresTheDescription(): void
    {
        $id = $this->addAlbum($this->uniqueName('setinfo'));

        $this->setInfo($id, array('comment' => 'Fotos aus dem Nachlass, 1954 bis 1961.'));

        $this->assertSame('Fotos aus dem Nachlass, 1954 bis 1961.', $this->column($id, 'comment'));
    }

    /**
     * [DT] Two conditions decide whether markup survives: $conf['allow_html_descriptions']
     * and whether a pwg_token was sent (pwg.categories.php:953).
     *
     * allow_html_descriptions is true on this install, so the token is the
     * deciding condition and both of its outcomes are covered here. The
     * false-configuration row is not: changing a global config value for one
     * test would leak into every other test in the run.
     */
    public function testSetInfoWithNoTokenStripsMarkup(): void
    {
        $this->assertNotSame(
            'false',
            (string)($this->db->scalar(
                "SELECT value FROM `piwigo_config` WHERE param = 'allow_html_descriptions'"
            ) ?? 'true'),
            'this case assumes HTML descriptions are allowed; the decision table row would differ otherwise'
        );

        $withToken = $this->addAlbum($this->uniqueName('html-token'));
        $this->setInfo($withToken, array('comment' => '<b>fett</b>'), true);
        $this->assertSame('<b>fett</b>', $this->column($withToken, 'comment'), 'a valid token keeps the markup');

        $withoutToken = $this->addAlbum($this->uniqueName('html-plain'));
        $this->setInfo($withoutToken, array('comment' => '<b>fett</b>'), false);
        $this->assertSame('fett', $this->column($withoutToken, 'comment'), 'without a token strip_tags() applies');
    }

    /** [BVA] A description filling the TEXT column is stored whole, not truncated. */
    public function testALongDescriptionIsStoredWhole(): void
    {
        $id = $this->addAlbum($this->uniqueName('long'));
        $comment = str_repeat('a', self::COMMENT_COLUMN_BYTES);
        $this->assertSame(self::COMMENT_COLUMN_BYTES, strlen($comment), 'anti-vacuity: the fixture must fill the column');

        $this->setInfo($id, array('comment' => $comment));

        $stored = (string)$this->column($id, 'comment');
        $this->assertSame(self::COMMENT_COLUMN_BYTES, strlen($stored), 'the description came back a different length');
        $this->assertSame($comment, $stored);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** Creates an album through the API and hands it to the fixture for teardown. */
    private function addAlbum(string $name, array $params = array()): int
    {
        $res = $this->ws->call('pwg.categories.add', array_merge(array('name' => $name), $params));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $id = (int)($res['json']['result']['id'] ?? 0);
        $this->assertGreaterThan(0, $id, 'the call returned no album id: ' . $res['body']);
        $this->fixture->adoptAlbum($id);

        return $id;
    }

    /** @param bool|null $withToken null sends the token, as the admin screen does */
    private function setInfo(int $catId, array $values, ?bool $withToken = null): void
    {
        $params = array_merge(array('category_id' => $catId), $values);
        if ($withToken !== false)
        {
            $params['pwg_token'] = $this->ws->token();
        }

        $res = $this->ws->call('pwg.categories.setInfo', $params);

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    private function column(int $catId, string $column): mixed
    {
        return $this->db->scalar("SELECT `$column` FROM `piwigo_categories` WHERE id = " . $catId);
    }

    private function albumCount(): int
    {
        return (int)$this->db->scalar('SELECT COUNT(*) FROM `piwigo_categories`');
    }

    private function uniqueName(string $suffix): string
    {
        return 'provenance-char-album-' . $suffix . '-' . bin2hex(random_bytes(4));
    }
}
