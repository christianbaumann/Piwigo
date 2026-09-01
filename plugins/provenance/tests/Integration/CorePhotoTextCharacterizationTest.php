<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for the core photo-properties screen: the title, author,
 * creation date and description written by single_update() in
 * admin/picture_modify.php:81-104, and the album links its "Linked albums"
 * control moves.
 *
 * Every case here is [ERR]: the oracle is the current implementation, not a
 * requirement. Nothing promises that an album left out of the selection is
 * unlinked rather than kept, that the storage album survives that unlink, or
 * that an unparsable date aborts the whole save - these record that it does
 * today. They report a change; they do not prove the behaviour right.
 *
 * They land and pass on their first run, which is normally the tell that a test
 * recorded code rather than drove it. Here that is the point, so each was
 * watched go red by breaking the behaviour it claims to watch.
 *
 * Drives the real boundary the handbook documents: a form POST to
 * admin.php?page=photo-<id>-properties, guarded by check_pwg_token(). Not
 * pwg.images.setInfo - that is a different writer, and the handbook describes
 * the screen.
 */
final class CorePhotoTextCharacterizationTest extends TestCase
{
    /** A rendered admin page shorter than this is an error page or a redirect. */
    private const MIN_PAGE_BYTES = 2000;

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private int $albumId;
    private int $imageId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->fixture = new FixtureBuilder($this->db);
        $this->albumId = $this->fixture->createTestAlbum('provenance-char-phototext-' . bin2hex(random_bytes(4)));
        $this->imageId = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($this->imageId, $this->albumId);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->ws->logout();
    }

    // ── the four text fields ──────────────────────────────────────────────

    /** [HAPPY] Title, author, creation date and description arrive in one POST. */
    public function testTitleAuthorDateAndDescriptionAreStored(): void
    {
        $this->save(array(
            'name' => 'Kirmes in Sefferweich',
            'author' => 'Josef Baumann',
            'date_creation' => '1954-06-12',
            'comment' => 'Aufgenommen vor dem Pfarrhaus.',
            ));

        $row = $this->photo();
        $this->assertSame('Kirmes in Sefferweich', $row['name']);
        $this->assertSame('Josef Baumann', $row['author']);
        $this->assertSame('1954-06-12 00:00:00', $row['date_creation'], 'a date-only value is widened to midnight');
        $this->assertSame('Aufgenommen vor dem Pfarrhaus.', $row['comment']);
    }

    /**
     * [ECP] [BVA] An empty value clears its own field and leaves the others alone.
     *
     * The screen always posts all four, so "unchanged" is not a state the form
     * can produce: clearing one field means posting it empty next to the values
     * that must survive.
     */
    public function testEachFieldCanBeClearedIndependently(): void
    {
        $full = array(
            'name' => 'Titel',
            'author' => 'Autor',
            'date_creation' => '1954-06-12',
            'comment' => 'Beschreibung',
            );

        foreach (array('name', 'author', 'date_creation', 'comment') as $cleared)
        {
            $this->save($full);
            $this->assertNotNull($this->photo()[$cleared], "anti-vacuity: $cleared must start set");

            $this->save(array_merge($full, array($cleared => '')));

            $row = $this->photo();
            $this->assertNull($row[$cleared], "$cleared was not cleared");
            foreach (array_diff(array_keys($full), array($cleared)) as $kept)
            {
                $this->assertNotNull($row[$kept], "clearing $cleared also cleared $kept");
            }
        }
    }

    /** [NEG] The file name is shown, never posted, and a posted one is ignored. */
    public function testTheFilenameIsNotWritable(): void
    {
        $before = $this->photo()['file'];
        $this->assertNotSame('', (string)$before, 'anti-vacuity: the fixture photo must have a file name');

        $this->save(array('name' => 'Titel', 'file' => 'umbenannt.png'));

        $this->assertSame($before, $this->photo()['file'], 'picture_modify.php writes no file column');
    }

    /**
     * [ERR] An unparsable creation date aborts the save before anything is written.
     *
     * check_input_parameter() runs at the top of picture_modify.php, so the
     * title posted alongside the bad date is lost too. Records that the save is
     * all-or-nothing rather than partial.
     */
    public function testAnInvalidCreationDateIsRejectedOrNormalised(): void
    {
        $this->save(array('name' => 'Vorher'));
        $this->assertSame('Vorher', $this->photo()['name'], 'anti-vacuity: the title must start set');

        $res = $this->post(array(
            'name' => 'Nachher',
            'date_creation' => '12.06.1954',
            'pwg_token' => $this->ws->token(),
            'level' => 0,
            'submit' => 1,
            'associate' => array($this->albumId),
            ));

        $this->assertStringContainsString('Hacking attempt', (string)$res['body'], $res['body']);
        $this->assertSame('Vorher', $this->photo()['name'], 'a refused save must write nothing');
    }

    /**
     * [DT] Two conditions decide what reaches the description column: the
     * $conf['allow_html_descriptions'] setting and the field's content.
     *
     * allow_html_descriptions is true on this install, so markup survives and
     * umlauts survive with it. The false-configuration row is not covered:
     * changing a global config value for one test would leak into the rest of
     * the run.
     */
    public function testUnicodeAndMarkupInTheDescription(): void
    {
        $this->assertNotSame(
            'false',
            (string)($this->db->scalar(
                "SELECT value FROM `piwigo_config` WHERE param = 'allow_html_descriptions'"
            ) ?? 'true'),
            'this case assumes HTML descriptions are allowed; the decision table row would differ otherwise'
        );

        $this->save(array('comment' => '<b>Größe</b> und Straße, Öl auf Büttenpapier'));

        $this->assertSame(
            '<b>Größe</b> und Straße, Öl auf Büttenpapier',
            $this->photo()['comment'],
            'with HTML descriptions allowed the markup and the umlauts both survive'
        );
    }

    // ── the gates ─────────────────────────────────────────────────────────

    /** [NEG] check_pwg_token() refuses a POST with no token and with a wrong one. */
    public function testAPostWithoutAValidTokenIsRefused(): void
    {
        $this->save(array('name' => 'Vorher'));
        $this->assertSame('Vorher', $this->photo()['name'], 'anti-vacuity: the title must start set');

        foreach (array(array(), array('pwg_token' => 'not-the-token')) as $tokenField)
        {
            $res = $this->post(array_merge(array(
                'name' => 'Nachher',
                'level' => 0,
                'submit' => 1,
                'associate' => array($this->albumId),
                ), $tokenField));

            $this->assertNotSame(200, $res['http_code'], 'a tokenless save answered as if it had worked');
            $this->assertSame('Vorher', $this->photo()['name'], 'a refused save must write nothing');
        }
    }

    /** [NEG] check_status(ACCESS_ADMINISTRATOR) refuses an authenticated non-admin. */
    public function testANormalUserIsRefused(): void
    {
        $this->save(array('name' => 'Vorher'));

        $normal = new WsClient();
        $normal->login(Config::normalUsername(), Config::normalPassword());
        $res = $normal->postPage($this->pagePath(), array(
            'name' => 'Nachher',
            'level' => 0,
            'submit' => 1,
            'pwg_token' => $normal->token(),
            'associate' => array($this->albumId),
            ));
        $normal->logout();

        $this->assertNotSame(200, $res['http_code'], 'a non-admin reached the admin screen');
        $this->assertSame('Vorher', $this->photo()['name'], 'a refused save must write nothing');
    }

    // ── linked albums ─────────────────────────────────────────────────────

    /**
     * [ERR] The "Linked albums" control moves rather than associates: an album
     * left out of the selection loses the photo.
     *
     * admin/picture_modify.php:119-126 hands the selection to
     * move_images_to_categories(), which first deletes every link not in it.
     * The handbook has to warn about this, so it is recorded here.
     */
    public function testLinkedAlbumsUnlinksAlbumsLeftOutOfTheSelection(): void
    {
        $second = $this->fixture->createTestAlbum('provenance-char-phototext-b-' . bin2hex(random_bytes(4)));
        $this->fixture->attachImage($this->imageId, $second);
        $this->assertSame(array($this->albumId, $second), $this->albumsOfPhoto(), 'anti-vacuity: start in both albums');

        $this->save(array('name' => 'Titel'), array($second));

        $this->assertSame(array($second), $this->albumsOfPhoto(), 'the album left out of the selection was unlinked');
    }

    /** [NEG] The storage album survives an unlink it was not selected for. */
    public function testTheStorageAlbumCannotBeUnlinked(): void
    {
        $this->makeStorageAlbum($this->albumId);
        $second = $this->fixture->createTestAlbum('provenance-char-phototext-c-' . bin2hex(random_bytes(4)));
        $this->fixture->attachImage($this->imageId, $second);

        $this->save(array('name' => 'Titel'), array($second));

        $this->assertSame(
            array($this->albumId, $second),
            $this->albumsOfPhoto(),
            'move_images_to_categories() spares the storage album'
        );
    }

    /**
     * [ERR] The privacy dropdown labels the five levels cumulatively.
     *
     * get_privacy_level_options() (include/functions.inc.php:2227-2249) walks
     * available_permission_levels in reverse and appends each group name to the
     * one before, so the options read "Administratoren", then
     * "Administratoren, Familie", and so on, with "Jeder" for level 0. No option
     * carries a single group name such as "Familie" on its own.
     *
     * handbuch/03-fototexte.html quotes these labels. It said "Kontakte,
     * Freunde, Familie oder Administratoren" until 2026-08-31, which sent the
     * reader looking for options that do not exist. This case exists so the
     * handbook cannot drift from the screen again unnoticed. The oracle is the
     * implementation, not a requirement.
     */
    public function testThePrivacyLevelsAreLabelledCumulatively(): void
    {
        $res = $this->ws->fetchPage($this->pagePath());
        $body = (string)$res['body'];

        $this->assertSame(200, $res['http_code'], 'the properties screen did not answer');
        $this->assertGreaterThan(
            self::MIN_PAGE_BYTES,
            strlen($body),
            'anti-vacuity: the answer is too short to be the rendered properties screen'
        );

        $this->assertMatchesRegularExpression(
            '#<select name="level".*?</select>#s',
            $body,
            'the privacy dropdown is not on the screen the handbook documents'
        );
        preg_match('#<select name="level".*?</select>#s', $body, $select);

        $count = preg_match_all('#<option[^>]*>([^<]*)</option>#', $select[0], $options);
        $this->assertGreaterThan(0, $count, 'anti-vacuity: the dropdown holds no option');

        $labels = array_map('trim', $options[1]);
        $this->assertSame(
            array(
                'Administratoren',
                'Administratoren, Familie',
                'Administratoren, Familie, Freunde',
                'Administratoren, Familie, Freunde, Kontakte',
                'Jeder',
            ),
            $labels,
            'the privacy options no longer read as handbuch/03-fototexte.html says'
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Posts the properties form the way the screen does, and asserts the screen
     * came back rather than an error page.
     *
     * associate[] is always sent: the form posts every album the photo is to
     * keep, and omitting it would silently unlink the fixture album.
     */
    private function save(array $fields, ?array $associate = null): void
    {
        $res = $this->post(array_merge(array(
            'level' => 0,
            'submit' => 1,
            'pwg_token' => $this->ws->token(),
            'associate' => $associate ?? $this->albumsOfPhoto(),
            ), $fields));

        $this->assertSame(200, $res['http_code'], 'the properties screen did not answer');
        $this->assertGreaterThan(
            self::MIN_PAGE_BYTES,
            strlen((string)$res['body']),
            'the answer is too short to be the rendered properties screen'
        );
    }

    private function post(array $params): array
    {
        return $this->ws->postPage($this->pagePath(), $params);
    }

    private function pagePath(): string
    {
        return '/admin.php?page=photo-' . $this->imageId . '-properties';
    }

    private function photo(): array
    {
        $result = $this->db->query(
            'SELECT name, author, date_creation, comment, file FROM `piwigo_images` WHERE id = ' . $this->imageId
        );
        $row = $result->fetch_assoc();
        if ($row === null)
        {
            throw new RuntimeException('the fixture photo disappeared');
        }
        return $row;
    }

    /** @return int[] ascending, so the assertion does not depend on row order */
    private function albumsOfPhoto(): array
    {
        $result = $this->db->query(
            'SELECT category_id FROM `piwigo_image_category` WHERE image_id = ' . $this->imageId . ' ORDER BY category_id'
        );
        $ids = array();
        while ($row = $result->fetch_assoc())
        {
            $ids[] = (int)$row['category_id'];
        }
        return $ids;
    }

    /** Forces the fixture photo to call one album its storage album, and asserts it took. */
    private function makeStorageAlbum(int $catId): void
    {
        $this->db->query(
            'UPDATE `piwigo_images` SET storage_category_id = ' . $catId . ' WHERE id = ' . $this->imageId
        );

        $actual = (int)$this->db->scalar(
            'SELECT storage_category_id FROM `piwigo_images` WHERE id = ' . $this->imageId
        );
        if ($actual !== $catId)
        {
            throw new RuntimeException("fixture photo did not take album $catId as its storage album: $actual");
        }
    }
}
