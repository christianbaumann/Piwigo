<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The executable role matrix of the photoedit plugin: one row per function and
 * role, asserting the level the role gets.
 *
 * The expected levels are the owner's target matrix of 2026-10-12
 * (.agents/changes/2026-10-12-role-test-coverage/design.md, decision 0059),
 * not read off the code. A probe sends the function's real requests as one
 * role and reports what it observed:
 *
 *   content shown | control shown | ws accepted | observed
 *   no            | no            | no          | none
 *   yes           | no            | no          | see
 *   yes           | yes           | yes         | edit
 *   anything else                               | INCONSISTENT: ...
 *
 * The edit probe uses pwg.photoedit.apply's dry run: ws_photoedit_apply()
 * refuses a guest, a bad token and a photo the caller may not see
 * (photoedit_user_may_edit()) before it looks at dry_run, so the dry run
 * passes the same gate as the write and changes no file.
 *
 * The other dimensions of the gate (private album, grant) stay in the
 * feature files, PicturePageButtonTest and ApplyTurnTest.
 */
final class RoleMatrixTest extends TestCase
{
    private const METHOD = 'pwg.photoedit.apply';

    /** The plugin's ws methods all start with this; the guard reads it. */
    private const METHOD_PREFIX = 'pwg.photoedit.';

    /** reflection.getMethodList listed 1 method under the prefix, measured 2026-10-12. */
    private const MIN_PLUGIN_METHODS = 1;

    /** A picture page shorter than this is an error page, on which "absent" says nothing. */
    private const MIN_PAGE_BYTES = 5000;

    private const PHOTO = 'id="theMainImage"';
    private const BUTTON = 'id="photoedit-toggle"';
    private const CONTROLS = 'id="photoedit-controls"';

    /** Keeps the left half: cuts FixtureBuilder::REGION_CUT away, keeps REGION_KEPT. */
    private const LEFT_HALF = '0,0,0.5,1';

    private const GUEST = 'guest';

    /** The roles of a row, column order of the matrix. */
    private const ROLES = array(
        self::GUEST => null,
        'generic' => TestUsers::GENERIC,
        'normal' => TestUsers::NORMAL,
        'admin' => TestUsers::ADMIN,
        'webmaster' => TestUsers::WEBMASTER,
        );

    /**
     * The target matrix: function => (ws methods its probe calls, level per role).
     * generic always equals normal.
     */
    private const MATRIX = array(
        'photoedit.turn-crop' => array(
            'methods' => array(self::METHOD),
            'levels' => array('guest' => 'none', 'generic' => 'edit', 'normal' => 'edit', 'admin' => 'edit', 'webmaster' => 'edit'),
            ),
        'photoedit.warnings' => array(
            'methods' => array(self::METHOD),
            'levels' => array('guest' => 'none', 'generic' => 'see', 'normal' => 'see', 'admin' => 'see', 'webmaster' => 'see'),
            ),
        );

    /** Which technique each role's row applies. */
    private const TAGS = array(
        'guest' => '[NEG][BVA]',
        'generic' => '[ECP][BVA]',
        'normal' => '[HAPPY][BVA]',
        'admin' => '[HAPPY][ECP]',
        'webmaster' => '[HAPPY]',
        );

    /** @var WsClient[] one logged-in session per role, kept for the whole run */
    private static array $clients = array();

    private Db $db;
    private FixtureBuilder $fixture;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPersons(array(FixtureBuilder::REGION_KEPT['name'], FixtureBuilder::REGION_CUT['name']));
    }

    public static function tearDownAfterClass(): void
    {
        self::$clients = array();
    }

    public static function matrixRows(): array
    {
        $rows = array();
        foreach (self::MATRIX as $function => $row)
        {
            foreach ($row['levels'] as $role => $level)
            {
                $rows[self::TAGS[$role] . " $function as $role"] = array($function, $role, $level);
            }
        }
        return $rows;
    }

    /** One cell of the matrix: the role gets exactly its level, both halves agreeing. */
    #[DataProvider('matrixRows')]
    public function testTheRoleGetsItsLevel(string $function, string $role, string $expected): void
    {
        $observed = match ($function)
        {
            'photoedit.turn-crop' => $this->probeTurnCrop($role),
            'photoedit.warnings' => $this->probeWarnings($role),
        };

        $this->assertSame($expected, $observed, "$function as $role");
    }

    /**
     * [NEG] Every ws method of the plugin is declared by at least one matrix
     * row: a new method without role rows fails here, by name.
     */
    public function testEveryPluginMethodHasAMatrixRow(): void
    {
        $res = $this->client('webmaster')->call('reflection.getMethodList');
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $methods = array_values(array_filter(
            $res['json']['result']['methods'],
            fn ($name) => str_starts_with($name, self::METHOD_PREFIX)
        ));
        $this->assertGreaterThanOrEqual(self::MIN_PLUGIN_METHODS, count($methods), 'anti-vacuity: no ' . self::METHOD_PREFIX . ' method listed');

        $declared = array_merge(...array_column(self::MATRIX, 'methods'));
        foreach ($methods as $method)
        {
            $this->assertContains($method, $declared, "$method has no row in RoleMatrixTest::MATRIX");
        }
    }

    /**
     * Turn and crop: the picture page shows the photo and the edit button, and
     * the dry run of a turn plus a crop is accepted.
     */
    private function probeTurnCrop(string $role): string
    {
        $image = $this->fixture->createMarkedImage();
        $album = $this->fixture->showInTestAlbum($image);
        $client = $this->client($role);

        $page = $client->fetchPage('/picture.php?/' . $image['id'] . '/category/' . $album);
        $this->assertGreaterThan(0, strlen($page), 'anti-vacuity: no answer at all');
        $content = (strlen($page) > self::MIN_PAGE_BYTES and str_contains($page, self::PHOTO));
        $control = (substr_count($page, self::BUTTON) === 1 and substr_count($page, self::CONTROLS) === 1);

        $res = $this->dryRun($client, $image, array('turns' => 1, 'crop' => self::LEFT_HALF));
        $accepted = ($res['json']['stat'] ?? null) === 'ok';

        return $this->level($content, $control, $accepted, $res['body']);
    }

    /**
     * Warnings: the dry run of a crop that cuts a person away from a JPEG
     * names that person (the lost-region warning) and reports the re-encode
     * (the note). The regions are the caller's own, so a normal account may
     * cut them away (persons decision 0065); the dialog itself is roles.spec.js'.
     * The function has no control and no write of its own.
     */
    private function probeWarnings(string $role): string
    {
        $this->fixture->assertPluginActive('persons');
        $image = $this->fixture->createMarkedJpeg(1);
        $this->fixture->showInTestAlbum($image);
        $this->fixture->writeRegions($image, array(FixtureBuilder::REGION_KEPT, FixtureBuilder::REGION_CUT));
        $this->indexRegions($image);
        if ($role !== self::GUEST)
        {
            $this->setDrawer($image, self::ROLES[$role]);
        }

        $res = $this->dryRun($this->client($role), $image, array('turns' => 0, 'crop' => self::LEFT_HALF));
        if (($res['json']['stat'] ?? null) !== 'ok')
        {
            return $this->level(false, false, false, $res['body']);
        }

        $result = $res['json']['result'];
        $content = ($result['lost_regions'] === array(FixtureBuilder::REGION_CUT['name']) and $result['lossy'] === true);

        return $content ? 'see' : 'INCONSISTENT: dry run accepted without both warnings: ' . $res['body'];
    }

    private function level(bool $content, bool $control, bool $accepted, string $body): string
    {
        $seen = array($content, $control, $accepted);
        if ($seen === array(false, false, false))
        {
            return 'none';
        }
        if ($seen === array(true, false, false))
        {
            return 'see';
        }
        if ($seen === array(true, true, true))
        {
            return 'edit';
        }

        return 'INCONSISTENT: content ' . var_export($content, true) . ', control ' . var_export($control, true)
            . ', ws accepted ' . var_export($accepted, true) . ': ' . $body;
    }

    private function dryRun(WsClient $client, array $image, array $params): array
    {
        return $client->call(self::METHOD, $params + array(
            'image_id' => $image['id'],
            'dry_run' => 'true',
            'pwg_token' => $client->token(),
            ));
    }

    /** The role's session, logged in once per run; a guest gets a fresh client each time. */
    private function client(string $role): WsClient
    {
        if ($role === self::GUEST)
        {
            return new WsClient();
        }
        if (!isset(self::$clients[$role]))
        {
            $client = new WsClient();
            list($username, $password) = Config::credentials(self::ROLES[$role]);
            $client->login($username, $password);
            self::$clients[$role] = $client;
        }
        return self::$clients[$role];
    }

    /** Has persons index the regions written into the file, as the webmaster. */
    private function indexRegions(array $image): void
    {
        $client = $this->client('webmaster');
        $res = $client->call('pwg.persons.rescan', array('image_ids' => (string)$image['id'], 'pwg_token' => $client->token()));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame('2', (string)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . (int)$image['id']
        ), 'anti-vacuity: persons did not index both regions');
    }

    /** Records the account as the drawer of every region of the photo, and asserts it took. */
    private function setDrawer(array $image, string $username): void
    {
        $drawer = (int)$this->db->scalar("SELECT id FROM piwigo_users WHERE username = '" . $this->db->escape($username) . "'");
        $this->assertGreaterThan(0, $drawer, "anti-vacuity: no account $username");
        $this->db->query("UPDATE piwigo_person_region SET drawn_by = $drawer WHERE image_id = " . (int)$image['id']);
        $this->assertSame('2', (string)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . (int)$image['id'] . " AND drawn_by = $drawer"
        ), "precondition: the regions are not drawn by $username");
    }
}
