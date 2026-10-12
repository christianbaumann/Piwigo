<?php
use PHPUnit\Framework\TestCase;

/**
 * The provenance rows of the role matrix, as an executable table.
 *
 * MATRIX is the requirement: the target matrix of
 * .agents/changes/2026-10-12-role-test-coverage/design.md, set by the owner on
 * 2026-10-11. Its levels are typed from that table, never read off the code, so
 * the rows are tests against a requirement and not characterization.
 *
 * Each function has a probe that sends the function's real requests as one role
 * and returns the level it observed:
 *
 *   content shown | control shown | ws accepted | observed
 *   no            | no            | no          | none
 *   yes           | no            | no          | see
 *   yes           | yes           | yes         | edit
 *   anything else                               | INCONSISTENT: <what was seen>
 *
 * A function without one of the three parts passes null for it. `see*` is `see`
 * reached only through the read-only page (decision 0064); `see` on history is
 * the read-only page plus pwg.provenance.getHistory. The generic column always
 * equals the normal one.
 *
 * Every guest row requests that function's own page or method: the gallery
 * lockout of decision 0055 does not count in its place.
 *
 * Core rows (task 02) go into MATRIX the same way: one entry, one probe.
 *
 * Technique per row: [NEG] guest; [ECP] generic, the equivalence class of
 * normal; [BVA] a role whose level differs from the role below it; [HAPPY]
 * every other row.
 */
final class RoleMatrixTest extends TestCase
{
    private const HISTORY_TABLE = 'piwigo_provenance_history';

    /** The prefix of every method this plugin registers; the guard filters by it. */
    private const METHOD_PREFIX = 'pwg.provenance.';

    /** pwg.provenance.* methods, measured 2026-10-12. Fewer means the listing broke. */
    private const MIN_PLUGIN_METHODS = 5;

    /** A rendered gallery or admin page shorter than this is an error page. */
    private const MIN_PAGE_BYTES = 2000;

    /** Roles in ascending order; [BVA] compares each with the one before it. */
    private const ROLES = array('guest', 'generic', 'normal', 'admin', 'webmaster');

    /**
     * function => probe, the ws methods the probe calls, and the level per role
     * (generic takes normal's).
     */
    private const MATRIX = array(
        'provenance.public-row' => array(
            'probe' => 'probePublicRow',
            'methods' => array(),
            'levels' => array('guest' => 'none', 'normal' => 'see', 'admin' => 'see', 'webmaster' => 'see'),
            ),
        'provenance.album' => array(
            'probe' => 'probeAlbum',
            'methods' => array('pwg.provenance.setAlbumInfo'),
            'levels' => array('guest' => 'none', 'normal' => 'see*', 'admin' => 'edit', 'webmaster' => 'edit'),
            ),
        'provenance.apply' => array(
            'probe' => 'probeApply',
            'methods' => array('pwg.provenance.applyToPhotos'),
            'levels' => array('guest' => 'none', 'normal' => 'none', 'admin' => 'edit', 'webmaster' => 'edit'),
            ),
        'provenance.writeback' => array(
            'probe' => 'probeWriteBack',
            'methods' => array('pwg.provenance.writeBack'),
            'levels' => array('guest' => 'none', 'normal' => 'none', 'admin' => 'edit', 'webmaster' => 'edit'),
            ),
        'provenance.photo-note' => array(
            'probe' => 'probePhotoNote',
            'methods' => array('pwg.provenance.setPhotoInfo'),
            'levels' => array('guest' => 'none', 'normal' => 'see*', 'admin' => 'edit', 'webmaster' => 'edit'),
            ),
        'provenance.move-prompt' => array(
            'probe' => 'probeMovePrompt',
            'methods' => array('pwg.images.setCategory'),
            'levels' => array('guest' => 'none', 'normal' => 'none', 'admin' => 'edit', 'webmaster' => 'edit'),
            ),
        'provenance.inherit' => array(
            'probe' => 'probeInherit',
            'methods' => array('pwg.images.setCategory'),
            'levels' => array('guest' => 'none', 'normal' => 'none', 'admin' => 'edit', 'webmaster' => 'edit'),
            ),
        'provenance.history' => array(
            'probe' => 'probeHistory',
            'methods' => array('pwg.provenance.getHistory'),
            'levels' => array('guest' => 'none', 'normal' => 'see*', 'admin' => 'see', 'webmaster' => 'see'),
            ),
        );

    /** What an album of this suite carries, so a probe can tell it apart on a page. */
    private const ALBUM = array(
        'provenance_physical_album' => 'Rollenmatrix Album',
        'provenance_owner' => 'Rollenmatrix Besitzerin',
        'provenance_scanned_on' => '2026-10-12',
        'provenance_note' => 'Rollenmatrix Albumnotiz',
        );

    /** A second album's values, for the move and the lounge. */
    private const OTHER_ALBUM = array(
        'provenance_physical_album' => 'Rollenmatrix Kasten',
        'provenance_owner' => 'Rollenmatrix Zweite',
        'provenance_scanned_on' => '2026-10-01',
        'provenance_note' => 'Rollenmatrix andere Notiz',
        );

    /** role => logged-in client, kept for the whole run so each role logs in once. */
    private static array $clients = array();

    private Db $db;
    private FixtureBuilder $fixture;
    private int $baselineHistoryId;

    /** lounge_active as found, when a probe forced it; restored in tearDown. */
    private ?string $originalLoungeActive = null;

    public static function tearDownAfterClass(): void
    {
        foreach (self::$clients as $role => $client)
        {
            if ($role !== 'guest')
            {
                $client->logout();
            }
        }
        self::$clients = array();
    }

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->baselineHistoryId = (int)$this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM ' . self::HISTORY_TABLE);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->db->query('DELETE FROM piwigo_lounge WHERE image_id NOT IN (SELECT id FROM piwigo_images)');
        $this->restoreLounge();
        $this->fixture->destroyTestAlbums();
        $this->fixture->invalidateUserCache();
        $this->db->query('DELETE FROM ' . self::HISTORY_TABLE . ' WHERE id > ' . $this->baselineHistoryId);
    }

    /** One row per (function, role), named "<function> as <role>". */
    public static function cells(): array
    {
        $rows = array();
        foreach (self::MATRIX as $function => $spec)
        {
            $previous = null;
            foreach (self::ROLES as $role)
            {
                $expected = $spec['levels'][$role === 'generic' ? 'normal' : $role];
                if ($role === 'guest')
                {
                    $tag = '[NEG]';
                }
                elseif ($role === 'generic')
                {
                    $tag = '[ECP]';
                }
                else
                {
                    $tag = $expected !== $previous ? '[BVA]' : '[HAPPY]';
                }
                $previous = $expected;

                $rows["$tag $function as $role"] = array($function, $role, $expected);
            }
        }
        return $rows;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cells')]
    public function testTheRoleGetsItsLevel(string $function, string $role, string $expected): void
    {
        $probe = self::MATRIX[$function]['probe'];

        $this->assertSame($expected, $this->$probe($role), "$function as $role");
    }

    /**
     * [NEG] Structural guard: every pwg.provenance.* method the server registers is
     * declared by a matrix row, so a new method cannot ship without role rows.
     */
    public function testEveryPluginMethodIsDeclaredByAMatrixRow(): void
    {
        $res = $this->client('webmaster')->call('reflection.getMethodList');
        $this->assertSame('ok', $res['json']['stat'] ?? null, (string)$res['body']);

        $registered = array_values(array_filter(
            $res['json']['result']['methods'],
            fn($method) => strpos($method, self::METHOD_PREFIX) === 0
        ));
        $this->assertGreaterThanOrEqual(
            self::MIN_PLUGIN_METHODS,
            count($registered),
            'anti-vacuity: the listing returned fewer plugin methods than were measured'
        );

        $declared = array();
        foreach (self::MATRIX as $spec)
        {
            $declared = array_merge($declared, $spec['methods']);
        }

        $this->assertSame(
            array(),
            array_values(array_diff($registered, $declared)),
            'no RoleMatrixTest row declares these methods'
        );
    }

    // ── probes ────────────────────────────────────────────────────────────

    /** The provenance row on the picture page: content only. */
    private function probePublicRow(string $role): string
    {
        list($catId, $imageId) = $this->albumWithPhoto();
        $this->fixture->imageProvenance($imageId, array('provenance_owner' => self::ALBUM['provenance_owner']));

        $page = $this->page($role, "/picture.php?/$imageId/category/$catId");
        $content = $page !== null
            && strpos($page, 'id="Provenance"') !== false
            && strpos($page, self::ALBUM['provenance_owner']) !== false;

        return $this->level($content, null, null);
    }

    /**
     * An album's provenance: the album page's link and the read-only page (content),
     * the block on the album properties screen (control), setAlbumInfo (ws).
     */
    private function probeAlbum(string $role): string
    {
        list($catId) = $this->albumWithPhoto();

        $content = $this->albumContent($role, $catId);
        if (!is_bool($content))
        {
            return $content;
        }

        $control = $this->adminPageHas($role, "/admin.php?page=album&cat_id=$catId&tab=properties", 'id="provenance-save"');

        $client = $this->client($role);
        $changed = 'Rollenmatrix geändert ' . $role;
        $res = $client->call('pwg.provenance.setAlbumInfo', array(
            'cat_id' => $catId,
            'physical_album' => self::ALBUM['provenance_physical_album'],
            'owner' => $changed,
            'scanned_on' => self::ALBUM['provenance_scanned_on'],
            'note' => self::ALBUM['provenance_note'],
            'pwg_token' => $client->token(),
            ));
        $ws = $this->wsOutcome($res, $this->fixture->readAlbumProvenance($catId)['provenance_owner'] === $changed);

        return $this->readOnlyStar($this->level($content, $control, $ws));
    }

    /** The apply button (control) and applyToPhotos (ws); no content of its own. */
    private function probeApply(string $role): string
    {
        list($catId, $imageId) = $this->albumWithPhoto();

        $control = $this->adminPageHas($role, "/admin.php?page=album&cat_id=$catId&tab=properties", 'id="provenance-apply"');

        $client = $this->client($role);
        $res = $client->call('pwg.provenance.applyToPhotos', array(
            'cat_id' => $catId,
            'image_ids' => (string)$imageId,
            'pwg_token' => $client->token(),
            ));
        $applied = $this->fixture->readImageProvenance($imageId)['provenance_owner'] === self::ALBUM['provenance_owner'];

        return $this->level(null, $control, $this->wsOutcome($res, $applied));
    }

    /** The write button (control) and writeBack (ws), on a copied photo only. */
    private function probeWriteBack(string $role): string
    {
        list($catId, $imageId) = $this->albumWithPhoto();
        $this->fixture->imageProvenance($imageId, array('provenance_owner' => self::ALBUM['provenance_owner']));

        $control = $this->adminPageHas($role, "/admin.php?page=album&cat_id=$catId&tab=properties", 'id="provenance-write"');

        $client = $this->client($role);
        $res = $client->call('pwg.provenance.writeBack', array(
            'image_ids' => (string)$imageId,
            'pwg_token' => $client->token(),
            ));
        $written = (int)($res['json']['result']['written'] ?? 0) === 1;

        return $this->level(null, $control, $this->wsOutcome($res, $written));
    }

    /**
     * A photo's own note: the read-only photo page (content), the block on the
     * photo properties screen (control), setPhotoInfo (ws).
     */
    private function probePhotoNote(string $role): string
    {
        list(, $imageId) = $this->albumWithPhoto();
        $note = 'Rollenmatrix Fotonotiz';
        $this->fixture->imageProvenance($imageId, array('provenance_note' => $note));

        $page = $this->page($role, "/index.php?/provenance/photo/$imageId");
        $content = $page !== null && strpos($page, $note) !== false;

        $control = $this->adminPageHas($role, "/admin.php?page=photo&image_id=$imageId&tab=properties", 'id="provenance-photo-save"');

        $client = $this->client($role);
        $changed = 'Rollenmatrix geändert ' . $role;
        $res = $client->call('pwg.provenance.setPhotoInfo', array(
            'image_id' => $imageId,
            'note' => $changed,
            'pwg_token' => $client->token(),
            ));
        $ws = $this->wsOutcome($res, $this->fixture->readImageProvenance($imageId)['provenance_note'] === $changed);

        return $this->readOnlyStar($this->level($content, $control, $ws));
    }

    /**
     * The move-mode radios in the Batch Manager (control), and a move that carries
     * a mode (ws): replace must overwrite what the photo carried.
     */
    private function probeMovePrompt(string $role): string
    {
        list($from, $imageId) = $this->albumWithPhoto();
        $this->fixture->imageProvenance($imageId, array('provenance_owner' => self::ALBUM['provenance_owner']));
        $to = $this->fixture->createTestAlbum('provenance role matrix destination');
        $this->fixture->albumProvenance($to, self::OTHER_ALBUM);

        $control = $this->adminPageHas(
            $role,
            '/admin.php?page=batch_manager&mode=global',
            'name="' . PROVENANCE_MOVE_MODE_PARAM . '"'
        );

        $client = $this->client($role);
        $res = $client->call('pwg.images.setCategory', array(
            'image_id' => array($imageId),
            'category_id' => $to,
            'action' => 'move',
            PROVENANCE_MOVE_MODE_PARAM => PROVENANCE_MODE_REPLACE,
            'pwg_token' => $client->token(),
            ));
        $replaced = $this->fixture->readImageProvenance($imageId)['provenance_owner'] === self::OTHER_ALBUM['provenance_owner'];

        return $this->level(null, $control, $this->wsOutcome($res, $replaced));
    }

    /**
     * Inheritance has no page. A role that can link a photo through the API gets
     * the album's values with a history row naming it (edit). A role that cannot
     * link anything reaches inheritance only by emptying the lounge with a page
     * view, which runs in keep whatever mode it posts and writes no history row
     * (decision 0060): none.
     */
    private function probeInherit(string $role): string
    {
        $catId = $this->fixture->createTestAlbum('provenance role matrix inherit');
        $this->fixture->albumProvenance($catId, self::ALBUM);
        $imageId = $this->fixture->createTestImage()['id'];

        $client = $this->client($role);
        $res = $client->call('pwg.images.setCategory', array(
            'image_id' => array($imageId),
            'category_id' => $catId,
            'action' => 'associate',
            'pwg_token' => $client->token(),
            ));

        if (($res['json']['stat'] ?? null) === 'ok')
        {
            $inherited = $this->fixture->readImageProvenance($imageId)['provenance_owner'] === self::ALBUM['provenance_owner'];
            $actor = $this->db->scalar(
                'SELECT DISTINCT performed_by FROM ' . self::HISTORY_TABLE .
                " WHERE id > {$this->baselineHistoryId} AND object = 'photo' AND object_id = $imageId AND source = 'inherit'"
            );
            if ($inherited and $actor !== null and (int)$actor === $this->userId($role))
            {
                return 'edit';
            }
            return "INCONSISTENT: link accepted, inherited=" . var_export($inherited, true) . ', history by ' . var_export($actor, true);
        }

        return $this->loungeViewAs($role, $catId, $imageId);
    }

    /**
     * The history: the read-only page's table (content) and getHistory (ws, a
     * read). The page alone is see*, the page with the method is see.
     */
    private function probeHistory(string $role): string
    {
        list($catId) = $this->albumWithPhoto();
        $value = 'Rollenmatrix Verlauf ' . bin2hex(random_bytes(4));
        $this->db->query(sprintf(
            "INSERT INTO %s (object, object_id, field, old_value, new_value, source) VALUES ('album', %d, 'provenance_owner', NULL, '%s', 'album_edit')",
            self::HISTORY_TABLE, $catId, $value
        ));

        $page = $this->page($role, "/index.php?/provenance/album/$catId");
        $content = $page !== null && strpos($page, 'id="provenancePageHistory"') !== false && strpos($page, $value) !== false;

        $res = $this->client($role)->call('pwg.provenance.getHistory', array('object' => 'album', 'object_id' => $catId));
        // A refusal whose body still carries the row is a leak: wsOutcome calls it INCONSISTENT.
        $ws = $this->wsOutcome($res, strpos((string)$res['body'], $value) !== false);

        if (!is_bool($ws))
        {
            return $ws;
        }
        if ($content and $ws)
        {
            return 'see';
        }
        if ($content and !$ws)
        {
            return 'see*';
        }
        if (!$content and !$ws)
        {
            return 'none';
        }
        return 'INCONSISTENT: page history ' . var_export($content, true) . ', getHistory ' . var_export($ws, true);
    }

    // ── observation helpers ───────────────────────────────────────────────

    /**
     * The design's table. null = the function has no such part.
     *
     * @param bool|string|null $content a string is an INCONSISTENT already found
     */
    private function level($content, ?bool $control, $ws): string
    {
        foreach (array($content, $ws) as $part)
        {
            if (is_string($part))
            {
                return $part;
            }
        }

        $parts = array_filter(
            array('content' => $content, 'control' => $control, 'ws' => $ws),
            fn($part) => $part !== null
        );
        $this->assertNotEmpty($parts, 'a probe must observe at least one part');

        if (!in_array(true, $parts, true))
        {
            return 'none';
        }
        if (!in_array(false, $parts, true))
        {
            return ($control === null and $ws === null) ? 'see' : 'edit';
        }
        if ($content === true and $control === false and $ws === false)
        {
            return 'see';
        }

        $seen = array();
        foreach ($parts as $name => $part)
        {
            $seen[] = $name . ($part ? ' shown' : ' absent');
        }
        return 'INCONSISTENT: ' . implode(', ', $seen);
    }

    /** album and photo-note show their content only on the read-only page (0064). */
    private function readOnlyStar(string $level): string
    {
        return $level === 'see' ? 'see*' : $level;
    }

    /**
     * Whether a ws call was accepted with its effect, refused without it, or an
     * INCONSISTENT string for an answer that was neither.
     */
    private function wsOutcome(array $res, bool $effect)
    {
        $stat = $res['json']['stat'] ?? null;
        if ($stat === 'ok' and $effect)
        {
            return true;
        }
        if ($stat === 'fail' and !$effect)
        {
            return false;
        }
        return 'INCONSISTENT: ws answered ' . var_export($stat, true) . ', effect ' . var_export($effect, true)
            . ': ' . substr((string)$res['body'], 0, 200);
    }

    /**
     * The album's content: the album page's link and the read-only page. Both
     * come from the same gate family, so a disagreement is INCONSISTENT.
     *
     * @return bool|string
     */
    private function albumContent(string $role, int $catId)
    {
        $albumPage = $this->page($role, "/index.php?/category/$catId");
        $link = $albumPage !== null && strpos($albumPage, 'id="provenancePageLink"') !== false;

        $page = $this->page($role, "/index.php?/provenance/album/$catId");
        $values = $page !== null && strpos($page, self::ALBUM['provenance_owner']) !== false;

        if ($link !== $values)
        {
            return 'INCONSISTENT: album page link ' . var_export($link, true) . ', read-only page ' . var_export($values, true);
        }
        return $values;
    }

    /**
     * A page's body, or null when the role was sent to the login page, refused,
     * or got no page at all.
     */
    private function page(string $role, string $path): ?string
    {
        $res = $this->client($role)->fetchPage($path);
        $body = (string)$res['body'];

        if ($res['http_code'] !== 200
            or strpos((string)$res['url'], 'identification.php') !== false
            or strlen($body) < self::MIN_PAGE_BYTES)
        {
            return null;
        }
        $this->assertStringNotContainsString('Fatal error', $body, $path);

        return $body;
    }

    private function adminPageHas(string $role, string $path, string $marker): bool
    {
        $page = $this->page($role, $path);

        return $page !== null && strpos($page, $marker) !== false;
    }

    /**
     * A page view as this role empties a lounge holding a fresh photo and one
     * carrying its own provenance, posting replace. none = the fresh one is
     * filled, the carrying one kept, and no history row written.
     */
    private function loungeViewAs(string $role, int $catId, int $fresh): string
    {
        $this->forceLounge();
        $carrying = $this->fixture->createTestImage()['id'];
        $this->fixture->imageProvenance($carrying, array('provenance_owner' => self::OTHER_ALBUM['provenance_owner']));
        $this->db->query("INSERT INTO piwigo_lounge (image_id, category_id) VALUES ($fresh, $catId), ($carrying, $catId)");
        $this->assertSame(
            array($fresh, $carrying),
            $this->loungeImageIds(),
            'precondition: the lounge holds exactly this probe\'s two photos'
        );
        $this->db->query("UPDATE piwigo_images SET date_available = NOW() - INTERVAL 1 DAY WHERE id IN ($fresh, $carrying)");

        $this->client($role)->postPage('/index.php', array(PROVENANCE_MOVE_MODE_PARAM => PROVENANCE_MODE_REPLACE));
        $this->assertSame(array(), $this->loungeImageIds(), 'anti-vacuity: the page view must have emptied the lounge');

        $filled = $this->fixture->readImageProvenance($fresh)['provenance_owner'] === self::ALBUM['provenance_owner'];
        $kept = $this->fixture->readImageProvenance($carrying)['provenance_owner'] === self::OTHER_ALBUM['provenance_owner'];
        $history = (int)$this->db->scalar(
            'SELECT COUNT(*) FROM ' . self::HISTORY_TABLE .
            " WHERE id > {$this->baselineHistoryId} AND object = 'photo' AND object_id IN ($fresh, $carrying)"
        );

        if ($filled and $kept and $history === 0)
        {
            return 'none';
        }
        return 'INCONSISTENT: lounge view as ' . $role . ' filled=' . var_export($filled, true)
            . ', kept=' . var_export($kept, true) . ", history rows $history";
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /** A public album carrying ALBUM, with one copied photo carrying nothing. */
    private function albumWithPhoto(): array
    {
        $catId = $this->fixture->createTestAlbum('provenance role matrix');
        $this->fixture->albumProvenance($catId, self::ALBUM);
        $imageId = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($imageId, $catId);
        $this->fixture->invalidateUserCache();

        return array($catId, $imageId);
    }

    private function client(string $role): WsClient
    {
        if (!isset(self::$clients[$role]))
        {
            $client = new WsClient();
            switch ($role)
            {
                case 'guest':
                    break;
                case 'generic':
                    $client->login(Config::genericUsername(), Config::genericPassword());
                    break;
                case 'normal':
                    $client->login(Config::normalUsername(), Config::normalPassword());
                    break;
                case 'admin':
                    $client->login(Config::adminUsername(), Config::adminPassword());
                    break;
                case 'webmaster':
                    $client->login(Config::username(), Config::password());
                    break;
                default:
                    throw new InvalidArgumentException("unknown role $role");
            }
            self::$clients[$role] = $client;
        }
        return self::$clients[$role];
    }

    private function userId(string $role): int
    {
        $username = array_search($role, TestUsers::ROLES, true);
        $this->assertNotFalse($username, "no test account has status $role");

        return (int)$this->db->scalar(
            "SELECT id FROM piwigo_users WHERE username = '" . $this->db->escape($username) . "'"
        );
    }

    private function forceLounge(): void
    {
        $found = $this->db->scalar("SELECT value FROM piwigo_config WHERE param = 'lounge_active'");
        $this->originalLoungeActive = $found === null ? '' : (string)$found;

        $this->db->query(
            "INSERT INTO piwigo_config (param, value) VALUES ('lounge_active', 'true')" .
            " ON DUPLICATE KEY UPDATE value = 'true'"
        );
        $this->assertSame('true', (string)$this->db->scalar("SELECT value FROM piwigo_config WHERE param = 'lounge_active'"));
    }

    private function restoreLounge(): void
    {
        if ($this->originalLoungeActive === null)
        {
            return;
        }

        if ($this->originalLoungeActive === '')
        {
            $this->db->query("DELETE FROM piwigo_config WHERE param = 'lounge_active'");
        }
        else
        {
            $this->db->query(
                "UPDATE piwigo_config SET value = '" . $this->db->escape($this->originalLoungeActive) .
                "' WHERE param = 'lounge_active'"
            );
        }
        $this->originalLoungeActive = null;
    }

    /** @return int[] */
    private function loungeImageIds(): array
    {
        return array_map(
            'intval',
            array_column(
                $this->db->query('SELECT image_id FROM piwigo_lounge ORDER BY image_id')->fetch_all(MYSQLI_ASSOC),
                'image_id'
            )
        );
    }
}
