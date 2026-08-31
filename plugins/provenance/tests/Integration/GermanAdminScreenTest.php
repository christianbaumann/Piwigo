<?php
use PHPUnit\Framework\TestCase;

/**
 * The core admin screens the handbook photographs render German.
 *
 * The unit guard (GermanOverrideKeyTest) says the literal still sits in the
 * template and the key still sits in local/language/de_DE.lang.php. Neither
 * says the override actually reaches a rendered page: load_language() could
 * stop scanning the flat local file, a plugin could shadow a key, or the
 * install's language could change, and every unit case would stay green while
 * the screen served English.
 *
 * Absence of the untranslated form is asserted alongside presence of the German
 * one. Presence alone would pass on a page that shows both.
 */
final class GermanAdminScreenTest extends TestCase
{
    /** An admin page shorter than this is an error page or a login redirect. */
    private const MIN_PAGE_BYTES = 2000;

    private const ALBUM_PLACEHOLDER = '{album}';
    private const PHOTO_PLACEHOLDER = '{photo}';

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixtures;
    private int $emptyAlbumId;
    private int $photoId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->fixtures = new FixtureBuilder($this->db);

        // The thumbnail hint renders only while the album has no photo, so the
        // fixture forces that state rather than hoping an existing album is empty.
        $this->emptyAlbumId = $this->fixtures->createTestAlbum('Provenance German screen ' . bin2hex(random_bytes(4)));

        // The photo properties screen dates every photo, so the case below needs
        // a photo of its own rather than whichever row the gallery happens to hold.
        $this->photoId = (int)$this->fixtures->createTestImage()['id'];

        $this->ws->login(Config::username(), Config::password());
    }

    protected function tearDown(): void
    {
        $this->ws->logout();
        // restore() puts provenance columns back; the album row itself is only
        // removed by destroyTestAlbums(), and leaving it out leaks one album per
        // test into the gallery tree the handbook screenshots.
        $this->fixtures->destroyTestAlbums();
        $this->fixtures->destroyTestImages();
        $this->fixtures->restore();
    }

    /**
     * One row per string the handbook screenshots: the screen that emits it,
     * the German the override must produce, and the untranslated form that must
     * be gone.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function screens(): array
    {
        return array(
            'album properties: save confirmation' => array(
                '/admin.php?page=album-' . self::ALBUM_PLACEHOLDER . '-properties',
                '<div class="info-message icon-ok-circled">Album gespeichert</div>',
                'Album updated',
            ),
            'album properties: save error' => array(
                '/admin.php?page=album-' . self::ALBUM_PLACEHOLDER . '-properties',
                'Beim Speichern der Albumeinstellungen ist ein Fehler aufgetreten',
                'An error has occured while saving album settings',
            ),
            'album properties: empty thumbnail hint' => array(
                '/admin.php?page=album-' . self::ALBUM_PLACEHOLDER . '-properties',
                'title="Keine Fotos in diesem Album, kein Vorschaubild verfügbar"',
                'No photos in the current album, no thumbnail available',
            ),
            'album list: rename control' => array(
                '/admin.php?page=albums',
                'Album umbenennen',
                'Rename album',
            ),
            'photo upload: album summary' => array(
                '/admin.php?page=photos_add',
                'Album %s enthält jetzt %d Fotos',
                'Album %s now contains %d photos',
            ),
            'photo upload: updated count' => array(
                '/admin.php?page=photos_add',
                '%d Fotos aktualisiert',
                '%d photos updated',
            ),
            'batch manager: empty filter hint' => array(
                '/admin.php?page=batch_manager&mode=global',
                'Kein Filter, fügen Sie einen hinzu',
                'No filter, add one',
            ),
            'photo properties: posted date' => array(
                '/admin.php?page=photo-' . self::PHOTO_PLACEHOLDER . '-properties',
                'Eingestellt am ',
                'Posted the ',
            ),
            // Source only. tags.js:298 overwrites .TagSubmit with 'Yes, rename'
            // before tags.js:306 fades the popin in, so this German never
            // reaches a reader. Recorded in the DOM by the provenance E2E
            // suite's core-admin-screens.spec.js; the key stays overridden so
            // an upstream version that stops overwriting it gets German free.
            'tag admin: rename control' => array(
                '/admin.php?page=tags',
                'Schlagwort umbenennen',
                'Rename Tag',
            ),
        );
    }

    private function page(string $path): string
    {
        $res = $this->ws->fetchPage(str_replace(
            array(self::ALBUM_PLACEHOLDER, self::PHOTO_PLACEHOLDER),
            array((string)$this->emptyAlbumId, (string)$this->photoId),
            $path
            ));

        $this->assertSame(200, (int)$res['http_code'], $path . ' did not load');

        $body = (string)$res['body'];
        $this->assertGreaterThan(
            self::MIN_PAGE_BYTES,
            strlen($body),
            'anti-vacuity: ' . $path . ' rendered too little for the assertions below to mean anything'
        );

        return $body;
    }

    /** [HAPPY] The German the local override defines reaches the rendered screen. */
    #[PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function testTheScreenRendersTheGermanString(string $path, string $german, string $untranslated): void
    {
        $this->assertStringContainsString(
            $german,
            $this->page($path),
            $path . ' does not show the German string; the local override is not reaching the page'
        );
    }

    /** [NEG] And the untranslated form it replaces is gone from that screen. */
    #[PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function testTheScreenNoLongerShowsTheUntranslatedString(string $path, string $german, string $untranslated): void
    {
        $this->assertStringNotContainsString(
            $untranslated,
            $this->page($path),
            $path . ' still shows "' . $untranslated . '"'
        );
    }

    /**
     * [ERR] 'Batch Manager Filter' is translated but renders nowhere.
     *
     * batch_manager_global.tpl:316 passes it as `title=` into
     * include/batch_manager_filter.inc.tpl, and that template never reads
     * $title - measured 2026-08-31, zero occurrences. So neither the English
     * nor the German form reaches the page, and a presence assertion for it
     * would be a test that cannot pass while an absence assertion would be one
     * that cannot fail.
     *
     * The override keeps the key: it costs nothing, GermanOverrideKeyTest
     * watches the literal, and an upstream template that starts rendering
     * $title gets German without a second change. This case exists so that
     * change is visible in a test run rather than silent.
     */
    public function testTheBatchManagerFilterTitleReachesNoScreen(): void
    {
        $body = $this->page('/admin.php?page=batch_manager&mode=global');

        $this->assertStringContainsString(
            'Kein Filter, fügen Sie einen hinzu',
            $body,
            'anti-vacuity: the filter panel did not render, so the absences below say nothing'
        );
        $this->assertStringNotContainsString('Batch Manager Filter', $body);
        $this->assertStringNotContainsString('Filter der Stapelverarbeitung', $body);
    }
}
