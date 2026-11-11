<?php
use PHPUnit\Framework\TestCase;

/**
 * The "Letzte Änderungen an diesem Foto" block in the picture page's source and
 * the formatted answer of pwg.provenance.getHistory it refreshes from. Server
 * markup only: opening the list and the refresh after a save are E2E.
 * Requirement: design "Letzte Änderungen an diesem Foto".
 */
final class HistoryListTest extends TestCase
{
    private const MIN_PAGE_BYTES = 5000;
    private const LIST_SIZE = 8;
    private const NO_SUCH_USER = 32000;
    private const BLOCK = 'id="photoinfo-history"';
    private const TITLE = 'Letzte Änderungen an diesem Foto';

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $normal;
    private int $albumId;
    private array $image;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');

        $this->image = $this->fixture->createTestImage();
        $this->albumId = $this->fixture->placeInPublicAlbum($this->image);

        $this->normal = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::NORMAL);
        $this->normal->login($username, $password);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [HAPPY] [DT] Three entries: the count, own entry marked, a gone account named as such, no JSON. */
    public function testTheBlockCountsAndNamesWhoChanged(): void
    {
        $normalId = $this->userId(TestUsers::NORMAL);
        $this->seed('tag', null, '{"id":5,"name":"Kirche"}', $normalId);
        $this->seed('region', null, '{"name":"Anna Schmitz","box":[0.1,0.1,0.2,0.2],"drawn_by":null}', $this->userId(TestUsers::WEBMASTER));
        $this->seed('date', 'ca. 1955 (1955~)', 'vor 1960 (../1960)', self::NO_SUCH_USER);

        $page = $this->page();

        $this->assertStringContainsString(self::BLOCK, $page);
        $this->assertStringContainsString(self::TITLE, $page);
        $this->assertStringContainsString('<span id="photoinfo-history-count">3</span>', $page);
        $this->assertStringContainsString('„Kirche“ hinzugefügt', $page);
        $this->assertStringContainsString('„Anna Schmitz“ markiert', $page);
        $this->assertStringContainsString('Datum geändert: ca. 1955 → vor 1960', $page);
        $this->assertSame(1, substr_count($page, '(Sie)'));
        $this->assertStringContainsString('gelöschter Benutzer', $page);
        $this->assertStringNotContainsString('&quot;id&quot;:5', $page);
        $this->assertStringContainsString('heute, ', $page);
    }

    /** [ECP] With no entry the block is there (a first save fills it) and says so. */
    public function testAnEmptyListStillRenders(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('<span id="photoinfo-history-count">0</span>', $page);
        $this->assertSame(0, substr_count($page, 'class="photoinfo-history-text"'));
        $this->assertMatchesRegularExpression('/<p id="photoinfo-history-empty">/', $page);
    }

    /** [ECP] Write-back rows are not changes a person made: not listed, not counted. */
    public function testWriteBackRowsAreNotListed(): void
    {
        $this->seed('file', null, 'Bildunterschrift', null);
        $this->seed('tag', null, '{"id":5,"name":"Kirche"}', null);

        $page = $this->page();

        $this->assertStringContainsString('<span id="photoinfo-history-count">1</span>', $page);
        $this->assertStringNotContainsString('Bildunterschrift', $page);
    }

    /** [BVA] Eight entries are all shown with no link; a ninth keeps eight and offers "alle anzeigen". */
    public function testTheListStopsAtEightAndLinksToTheReadOnlyPage(): void
    {
        for ($i = 1; $i <= self::LIST_SIZE; $i++)
        {
            $this->seed('info', null, "Text $i", null);
        }
        $eight = $this->page();
        $this->assertSame(self::LIST_SIZE, substr_count($eight, 'class="photoinfo-history-text"'));
        $this->assertMatchesRegularExpression('/<p id="photoinfo-history-all" hidden>/', $eight);

        $this->seed('info', 'Text 8', 'Text 9', null);
        $nine = $this->page();

        $this->assertSame(self::LIST_SIZE, substr_count($nine, 'class="photoinfo-history-text"'));
        $this->assertStringContainsString('<span id="photoinfo-history-count">9</span>', $nine);
        $this->assertMatchesRegularExpression('/<p id="photoinfo-history-all">\s*<a href="[^"]*provenance\/photo\/' . $this->image['id'] . '"/', $nine);
    }

    /** [HAPPY] The refresh asks for rows with their text; the answer carries what the block shows. */
    public function testGetHistoryAnswersFormattedRowsOfTheListFields(): void
    {
        $this->seed('tag', null, '{"id":5,"name":"Kirche"}', $this->userId(TestUsers::NORMAL));
        $this->seed('file', null, 'Bildunterschrift', null);

        $res = $this->history(array('fields' => 'date,info,tag,region,photo', 'formatted' => 'true'));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $rows = $res['json']['result']['histories'];
        $this->assertCount(1, $rows);
        $this->assertSame('„Kirche“ hinzugefügt', $rows[0]['display']['text']);
        $this->assertTrue($rows[0]['display']['own']);
        $this->assertSame(1, $res['json']['result']['paging']['total_count']);
    }

    /** [NEG] An unknown field name is refused, not passed into the query. */
    public function testAnUnknownFieldIsRefused(): void
    {
        $res = $this->history(array('fields' => 'tag,id"; DROP'));

        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
    }

    /** [NEG] An account that cannot see the photo reads no history, formatted or not. */
    public function testAPrivatePhotoWithoutGrantHasNoHistory(): void
    {
        $this->seed('tag', null, '{"id":5,"name":"Kirche"}', null);
        $this->fixture->makeAlbumPrivate($this->albumId);

        $res = $this->history(array('formatted' => 'true'));

        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
    }

    /** [NEG] A private album without a grant: the account gets no picture page, so no list block in its source. */
    public function testNoBlockInThePageOfAPhotoTheAccountCannotSee(): void
    {
        $this->seed('tag', null, '{"id":5,"name":"Kirche"}', null);
        $this->fixture->makeAlbumPrivate($this->albumId);

        $page = $this->normal->fetchPage('/picture.php?/' . $this->image['id'] . '/category/' . $this->albumId);

        $this->assertStringNotContainsString('id="theMainImage"', $page, 'the account reached the photo; the case proves nothing');
        $this->assertStringNotContainsString('photoinfo-history', $page);
        $this->assertStringNotContainsString('Kirche', $page);
    }

    private function history(array $params): array
    {
        return $this->normal->call('pwg.provenance.getHistory', $params + array('object' => 'photo', 'object_id' => $this->image['id']));
    }

    private function page(): string
    {
        $page = $this->normal->fetchPage('/picture.php?/' . $this->image['id'] . '/category/' . $this->albumId);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($page), 'anti-vacuity: not a picture page');
        $this->assertStringContainsString('id="theMainImage"', $page, 'anti-vacuity: the photo is not on the page');

        return $page;
    }

    private function seed(string $field, ?string $old, ?string $new, ?int $by): void
    {
        $quote = fn (?string $value) => $value === null ? 'NULL' : "'" . $this->db->escape($value) . "'";
        $this->db->query('INSERT INTO piwigo_provenance_history (object, object_id, field, old_value, new_value, source, performed_by)'
            . " VALUES ('photo', " . $this->image['id'] . ", '$field', " . $quote($old) . ', ' . $quote($new)
            . ", 'picture_page', " . ($by === null ? 'NULL' : $by) . ')');
    }

    private function userId(string $role): int
    {
        list($username) = Config::credentials($role);

        return (int)$this->db->scalar("SELECT id FROM piwigo_users WHERE username = '" . $this->db->escape($username) . "'");
    }
}
