<?php
use PHPUnit\Framework\TestCase;

/**
 * The read-only provenance page, index.php?/provenance/album|photo/<id>, as the
 * normal test account and as a guest.
 *
 * Page source only. That the picture page's link opens it in a browser is
 * provenance-page.spec.js.
 *
 * The album and photo are this suite's own, so the history rows the webmaster
 * writes through the real save methods belong to objects nothing else reads.
 */
final class ReadOnlyPageTest extends TestCase
{
    private const HISTORY_TABLE = 'piwigo_provenance_history';

    /** A rendered page shorter than this is an error page. */
    private const MIN_PAGE_BYTES = 2000;

    private const ALBUM_VALUES = array(
        'physical_album' => 'Oma Müllers Fotoalbum',
        'owner' => 'Anna Müller',
        'scanned_on' => '2026-08-29',
        'note' => 'geliehen im August',
        );

    private const PHOTO_NOTE = 'auf der Rückseite: Sommer 1972';

    private Db $db;
    private WsClient $webmaster;
    private WsClient $normal;
    private FixtureBuilder $fixture;
    private int $catId;
    private int $imageId;
    private int $baselineHistoryId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->baselineHistoryId = (int)$this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM ' . self::HISTORY_TABLE);

        $this->catId = $this->fixture->createTestAlbum('provenance read-only page');
        $this->imageId = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($this->imageId, $this->catId);
        $this->fixture->invalidateUserCache();

        $this->webmaster = new WsClient();
        $this->webmaster->login(Config::username(), Config::password());
        $this->normal = new WsClient();
        $this->normal->login(Config::normalUsername(), Config::normalPassword());

        // Through the real save methods, so the page shows the rows those write.
        $this->assertOk($this->webmaster->call('pwg.provenance.setAlbumInfo', array_merge(
            array('cat_id' => $this->catId, 'pwg_token' => $this->webmaster->token()),
            self::ALBUM_VALUES
        )));
        $this->assertOk($this->webmaster->call('pwg.provenance.applyToPhotos', array(
            'cat_id' => $this->catId, 'image_ids' => (string)$this->imageId, 'pwg_token' => $this->webmaster->token(),
        )));
        $this->assertOk($this->webmaster->call('pwg.provenance.setPhotoInfo', array(
            'image_id' => $this->imageId, 'note' => self::PHOTO_NOTE, 'pwg_token' => $this->webmaster->token(),
        )));
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->invalidateUserCache();
        $this->db->query('DELETE FROM ' . self::HISTORY_TABLE . ' WHERE id > ' . $this->baselineHistoryId);
        $this->webmaster->logout();
        $this->normal->logout();
    }

    /** [HAPPY] A normal account sees the album's four values and the history of the save. */
    public function testNormalAccountSeesTheAlbumValuesAndHistory(): void
    {
        $page = $this->pageContent($this->normal->fetchPage('/index.php?/provenance/album/' . $this->catId));

        $this->assertStringContainsString('data-object="album"', $page);
        foreach (self::ALBUM_VALUES as $value)
        {
            $this->assertStringContainsString(htmlspecialchars($value), $page);
        }
        // One history row per field the save changed.
        $this->assertSame(count(self::ALBUM_VALUES), $this->historyRowCount($page));
    }

    /** [HAPPY] The photo page shows the copied values, the photo's own note and its history. */
    public function testNormalAccountSeesThePhotoValuesNoteAndHistory(): void
    {
        $page = $this->pageContent($this->normal->fetchPage('/index.php?/provenance/photo/' . $this->imageId));

        $this->assertStringContainsString('data-object="photo"', $page);
        $this->assertStringContainsString(htmlspecialchars(self::ALBUM_VALUES['owner']), $page);
        $this->assertStringContainsString(htmlspecialchars(self::ALBUM_VALUES['note']), $page);
        $this->assertStringContainsString(htmlspecialchars(self::PHOTO_NOTE), $page);
        // Four values the apply copied, one note the photo save wrote.
        $this->assertSame(count(self::ALBUM_VALUES) + 1, $this->historyRowCount($page));
    }

    /**
     * [NEG] A failed write's raw exiftool message (it names server paths) reaches only
     * administrators; a normal account sees that the write failed, not why.
     */
    public function testAFileErrorShowsNoServerDetailToANormalAccount(): void
    {
        $message = 'Error opening file - /var/www/html/galleries/private/scan-0001.jpg';
        $this->db->query(sprintf(
            "INSERT INTO %s (object, object_id, field, old_value, new_value, source) VALUES ('photo', %d, 'file_error', NULL, '%s', 'writeback')",
            self::HISTORY_TABLE, $this->imageId, $message
        ));
        $route = '/index.php?/provenance/photo/' . $this->imageId;

        $normalPage = $this->pageContent($this->normal->fetchPage($route));
        $this->assertSame(count(self::ALBUM_VALUES) + 2, $this->historyRowCount($normalPage));
        $this->assertStringNotContainsString('/var/www/html', $normalPage);

        $this->assertStringContainsString($message, $this->pageContent($this->webmaster->fetchPage($route)));
    }

    /** [NEG] The page offers nothing to change: no form, no input, no button. */
    public function testThePageHasNoControls(): void
    {
        foreach (array('album/' . $this->catId, 'photo/' . $this->imageId) as $route)
        {
            $page = $this->pageContent($this->normal->fetchPage('/index.php?/provenance/' . $route));
            $this->assertDoesNotMatchRegularExpression('/<(form|input|textarea|select|button)\b/i', $page, $route);
            $this->assertStringNotContainsString('buttonLike', $page, $route);
        }
    }

    /** [NEG] A private album without a grant, and the photo in it, are not found. */
    public function testAPrivateAlbumAndItsPhotoAreNotFound(): void
    {
        // The same account sees it while it is public, so the 404 below is the
        // permission, not a broken page.
        $this->assertSame(200, $this->normal->fetchPage('/index.php?/provenance/album/' . $this->catId)['http_code']);

        $this->fixture->makeAlbumPrivate($this->catId);

        $album = $this->normal->fetchPage('/index.php?/provenance/album/' . $this->catId);
        $photo = $this->normal->fetchPage('/index.php?/provenance/photo/' . $this->imageId);

        $this->assertSame(404, $album['http_code']);
        $this->assertSame(404, $photo['http_code']);
        $this->assertStringNotContainsString(htmlspecialchars(self::ALBUM_VALUES['owner']), (string)$album['body']);
        $this->assertStringNotContainsString(htmlspecialchars(self::PHOTO_NOTE), (string)$photo['body']);
    }

    /** [NEG] An id that names nothing, and an unknown object, are not found. */
    public function testUnknownObjectsAreNotFound(): void
    {
        $missing = (int)$this->db->scalar('SELECT MAX(id) + 1000 FROM piwigo_categories');

        $this->assertSame(404, $this->normal->fetchPage('/index.php?/provenance/album/' . $missing)['http_code']);
        $this->assertSame(404, $this->normal->fetchPage('/index.php?/provenance/tag/' . $this->catId)['http_code']);
    }

    /** [NEG] A guest is sent to the login page. */
    public function testAGuestIsSentToTheLoginPage(): void
    {
        $res = $this->normal->fetchPage('/index.php?/provenance/album/' . $this->catId, false);

        $this->assertStringContainsString('identification.php', (string)$res['url']);
        $this->assertStringNotContainsString(htmlspecialchars(self::ALBUM_VALUES['owner']), (string)$res['body']);
    }

    /** [HAPPY] The album page links to the album's provenance page. */
    public function testTheAlbumPageLinksToThePage(): void
    {
        $res = $this->normal->fetchPage('/index.php?/category/' . $this->catId);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen((string)$res['body']));

        $this->assertMatchesRegularExpression(
            '#id="provenancePageLink" href="[^"]*index\.php\?/provenance/album/' . $this->catId . '"#',
            (string)$res['body']
        );
    }

    private function assertOk(array $res): void
    {
        $this->assertSame('ok', $res['json']['stat'] ?? null, (string)$res['body']);
    }

    /** The page's own block, after asserting the page rendered. */
    private function pageContent(array $res): string
    {
        $this->assertSame(200, $res['http_code']);
        $body = (string)$res['body'];
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), 'the page did not render');
        $this->assertStringNotContainsString('Fatal error', $body);

        $start = strpos($body, '<div id="provenancePage"');
        $this->assertNotFalse($start, 'the provenance page block is missing');
        $end = strpos($body, '<!-- /provenancePage -->', $start);
        $this->assertNotFalse($end, 'the provenance page block is not closed');

        return substr($body, $start, $end - $start);
    }

    private function historyRowCount(string $page): int
    {
        $this->assertStringContainsString('id="provenancePageHistory"', $page, 'no history table');
        $tbody = substr($page, (int)strpos($page, '<tbody>'));
        return substr_count($tbody, '<tr>');
    }
}
