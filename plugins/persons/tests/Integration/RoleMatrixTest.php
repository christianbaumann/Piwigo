<?php
use PHPUnit\Framework\TestCase;

/**
 * The executable role matrix of the persons plugin: one row per function and
 * role, the expected level typed from the target matrix of
 * .agents/changes/2026-10-12-role-test-coverage/design.md (the requirement,
 * not the code), and a probe per function that sends the function's real
 * requests as that role and reports the level it observed.
 *
 * A probe reads up to three parts and folds them into one level:
 *
 *   content shown   control shown   write accepted   observed
 *   no              no              no               none
 *   yes             no              no               see
 *   yes             yes             yes              edit
 *   anything else                                    INCONSISTENT: ...
 *
 * A function without a part leaves it out. delete-region reads the parts twice,
 * on a region the role drew and on one another account drew, and reports `own`
 * when only the first is the role's to delete (decision 0065).
 *
 * Technique, per row, in the data set name: [HAPPY] at a level above none,
 * [NEG] at none, [BVA] where the level differs from the neighbouring role's in
 * the order guest | generic | normal | admin | webmaster, [ECP] for generic,
 * which is its own status class but must land with normal.
 *
 * The guard at the bottom fails, naming the method, when the plugin registers
 * a pwg.persons.* method no row declares.
 */
final class RoleMatrixTest extends TestCase
{
    /** The plugin's method namespace; the guard lists what reflection reports under it. */
    private const METHOD_PREFIX = 'pwg.persons.';

    /** pwg.persons.* methods registered, measured 2026-10-12. Fewer means the list came back empty or cut. */
    private const MIN_METHODS = 7;

    /** A rendered page shorter than this is a login page, a refusal or an error, not the screen. */
    private const MIN_PAGE_BYTES = 2000;

    private const ROLES = array('guest', 'generic', 'normal', 'admin', 'webmaster');

    /** function => the ws methods its probe calls. */
    private const METHODS = array(
        'persons.overlay'       => array('pwg.persons.getRegions'),
        'persons.names-as-tags' => array(),
        'persons.add-region'    => array('pwg.persons.addRegion'),
        'persons.delete-region' => array('pwg.persons.deleteRegion'),
        'persons.name-list'     => array('pwg.persons.getList'),
        'persons.admin-screen'  => array('pwg.persons.rename', 'pwg.persons.rescan', 'pwg.persons.delete'),
        'persons.admin-tagging' => array('pwg.persons.addRegion'),
        );

    /** function => level per role, in ROLES order. The target matrix, typed from the design. */
    private const EXPECTED = array(
        'persons.overlay'       => array('none', 'see', 'see', 'see', 'see'),
        'persons.names-as-tags' => array('none', 'see', 'see', 'see', 'see'),
        'persons.add-region'    => array('none', 'edit', 'edit', 'edit', 'edit'),
        'persons.delete-region' => array('none', 'own', 'own', 'edit', 'edit'),
        'persons.name-list'     => array('none', 'see', 'see', 'see', 'see'),
        'persons.admin-screen'  => array('none', 'none', 'none', 'see', 'edit'),
        'persons.admin-tagging' => array('none', 'none', 'none', 'edit', 'edit'),
        );

    private const SUBJECT = 'Persons Matrix Subject';
    private const OTHER = 'Persons Matrix Other';
    private const ADDED = 'Persons Matrix Added';
    private const DOOMED = 'Persons Matrix Doomed';

    private const BOX_A = array('x' => 0.3, 'y' => 0.3, 'w' => 0.2, 'h' => 0.2);
    private const BOX_B = array('x' => 0.7, 'y' => 0.7, 'w' => 0.2, 'h' => 0.2);
    private const BOX_C = array('x' => 0.3, 'y' => 0.75, 'w' => 0.15, 'h' => 0.15);

    private static FixtureBuilder $albumFixture;
    private static int $album;

    /** @var WsClient[] role => one client for the whole run; the guest's never logs in */
    private static array $clients = array();

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;

    public static function setUpBeforeClass(): void
    {
        self::$albumFixture = new FixtureBuilder(new Db());
        self::$album = self::$albumFixture->createTestAlbum('Persons role matrix fixture');
        self::$albumFixture->invalidateUserCache();

        $credentials = array(
            'generic'   => array(Config::genericUsername(), Config::genericPassword()),
            'normal'    => array(Config::normalUsername(), Config::normalPassword()),
            'admin'     => array(Config::adminUsername(), Config::adminPassword()),
            'webmaster' => array(Config::username(), Config::password()),
            );

        self::$clients = array('guest' => new WsClient());
        foreach ($credentials as $role => $pair)
        {
            $client = new WsClient();
            // A login is a ws call from outside the admin, so it also rebuilds
            // the user cache invalidated above.
            $client->login($pair[0], $pair[1]);
            self::$clients[$role] = $client;
        }
    }

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
        self::$albumFixture->destroyTestAlbums();
        self::$albumFixture->invalidateUserCache();
    }

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);

        if (!$this->fixture->tableExists('piwigo_person_region'))
        {
            $this->fail('the persons plugin is not installed; activate it first');
        }

        $this->image = $this->fixture->createTestImage();
        $this->fixture->attachImage((int)$this->image['id'], self::$album);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyPersons(array(self::SUBJECT, self::OTHER, self::ADDED, self::DOOMED));
    }

    #[PHPUnit\Framework\Attributes\DataProvider('cells')]
    public function testTheRoleGetsItsLevel(string $function, string $role, string $expected): void
    {
        $probe = array(
            'persons.overlay'       => 'probeOverlay',
            'persons.names-as-tags' => 'probeNamesAsTags',
            'persons.add-region'    => 'probeAddRegion',
            'persons.delete-region' => 'probeDeleteRegion',
            'persons.name-list'     => 'probeNameList',
            'persons.admin-screen'  => 'probeAdminScreen',
            'persons.admin-tagging' => 'probeAdminTagging',
            )[$function];

        $this->assertSame($expected, $this->$probe($role), "$function as $role");
    }

    public static function cells(): array
    {
        $cells = array();
        foreach (self::EXPECTED as $function => $levels)
        {
            foreach (self::ROLES as $i => $role)
            {
                $tags = array($levels[$i] === 'none' ? '[NEG]' : '[HAPPY]');
                if (($i > 0 && $levels[$i - 1] !== $levels[$i])
                    or ($i < count(self::ROLES) - 1 && $levels[$i + 1] !== $levels[$i]))
                {
                    $tags[] = '[BVA]';
                }
                if ($role === 'generic')
                {
                    $tags[] = '[ECP]';
                }
                $cells["$function as $role " . implode('', $tags)] = array($function, $role, $levels[$i]);
            }
        }

        if (count($cells) !== count(self::EXPECTED) * count(self::ROLES) or count($cells) < 35)
        {
            throw new RuntimeException('anti-vacuity: the matrix lost rows');
        }

        return $cells;
    }

    /**
     * [NEG] Every pwg.persons.* method the plugin registers is declared by a row,
     * so a new method cannot reach the API without role rows.
     */
    public function testEveryMethodOfThePluginIsDeclaredByARow(): void
    {
        $res = self::$clients['webmaster']->call('reflection.getMethodList');
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $methods = array_values(array_filter($res['json']['result']['methods'],
            fn ($method) => strpos($method, self::METHOD_PREFIX) === 0));
        $this->assertGreaterThanOrEqual(self::MIN_METHODS, count($methods),
            'anti-vacuity: reflection listed fewer ' . self::METHOD_PREFIX . ' methods than measured');

        $declared = array_merge(...array_values(self::METHODS));
        foreach ($methods as $method)
        {
            $this->assertContains($method, $declared, "$method has no row in the role matrix");
        }
    }

    // ── probes ────────────────────────────────────────────────────────────

    /** The boxes on the picture page, and the regions getRegions reads. */
    private function probeOverlay(string $role): string
    {
        $regionId = $this->seedRegion(self::SUBJECT, self::BOX_A);
        $ws = self::$clients[$role];

        $page = $this->picturePage($role);
        $read = $ws->call('pwg.persons.getRegions', array('image_id' => $this->image['id']));
        $readIds = array_column($read['json']['result']['regions'] ?? array(), 'id');

        return self::level(array(
            'content' => array(
                'boxes on the page' => strpos($page, 'data-person-region="' . $regionId . '"') !== false,
                'getRegions' => ($read['json']['stat'] ?? '') === 'ok' && in_array($regionId, $readIds, true),
                ),
            ));
    }

    /** The person's mirrored tag in core's tag row of the picture page. No ws part of its own. */
    private function probeNamesAsTags(string $role): string
    {
        $this->seedRegion(self::SUBJECT, self::BOX_A);
        $tagId = (int)$this->db->scalar(
            "SELECT tag_id FROM piwigo_persons WHERE name = '" . $this->db->escape(self::SUBJECT) . "'");
        $this->assertGreaterThan(0, $tagId, 'anti-vacuity: the person has no mirrored tag');

        $page = $this->picturePage($role);
        $tagRow = preg_match('#<div id="Tags"[^>]*>.*?</div>#s', $page, $m) ? $m[0] : '';

        return self::level(array(
            'content' => array('tag in the tag row' => strpos($tagRow, 'data-tag-id="' . $tagId . '"') !== false),
            ));
    }

    /** The stage and the tag toggle on the picture page, and an addRegion save. */
    private function probeAddRegion(string $role): string
    {
        $ws = self::$clients[$role];
        $page = $this->picturePage($role);

        $save = $ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'], 'name' => self::ADDED,
            'pwg_token' => $this->tokenOf($role),
            ) + self::BOX_A);

        return self::level(array(
            'content' => array('stage' => strpos($page, 'id="persons-stage"') !== false),
            'control' => array('tag toggle' => strpos($page, 'id="persons-tag-toggle"') !== false),
            'write' => array('addRegion' => self::accepted($save)
                && $this->regionIdOf(self::ADDED) !== null),
            ));
    }

    /**
     * A region the role drew and one another account drew: the delete control on
     * each box, then deleteRegion on each, the other's first. The drawer is set
     * in the database, so the row does not depend on the role's own addRegion.
     */
    private function probeDeleteRegion(string $role): string
    {
        $ownId = $this->seedRegion(self::SUBJECT, self::BOX_A);
        $otherId = $this->seedRegion(self::OTHER, self::BOX_B);

        // A guest draws nothing: its "own" stands in as a normal user's region.
        $ownDrawer = $role === 'guest' ? 'normal' : $role;
        $otherDrawer = $role === 'webmaster' ? 'normal' : 'webmaster';
        $this->setDrawer($ownId, $ownDrawer);
        $this->setDrawer($otherId, $otherDrawer);

        $page = $this->picturePage($role);
        $content = strpos($page, 'data-person-region="' . $ownId . '"') !== false
            && strpos($page, 'data-person-region="' . $otherId . '"') !== false;

        $ws = self::$clients[$role];
        $otherDelete = $ws->call('pwg.persons.deleteRegion', array('region_id' => $otherId, 'pwg_token' => $this->tokenOf($role)));
        $ownDelete = $ws->call('pwg.persons.deleteRegion', array('region_id' => $ownId, 'pwg_token' => $this->tokenOf($role)));

        $own = self::level(array(
            'content' => array('boxes' => $content),
            'control' => array('delete on own box' => $this->boxHasDelete($page, $ownId)),
            'write' => array('deleteRegion own' => self::accepted($ownDelete) && $this->regionIdOf(self::SUBJECT) === null),
            ));
        $other = self::level(array(
            'content' => array('boxes' => $content),
            'control' => array("delete on the other's box" => $this->boxHasDelete($page, $otherId)),
            'write' => array('deleteRegion other' => self::accepted($otherDelete) && $this->regionIdOf(self::OTHER) === null),
            ));

        $combined = array(
            'none|none' => 'none',
            'see|see' => 'see',
            'edit|see' => 'own',
            'edit|edit' => 'edit',
            );

        return $combined["$own|$other"] ?? "INCONSISTENT: own region $own, other's region $other";
    }

    /** getList finding a person on a photo the role may see. The picker is JS-built: roles.spec.js. */
    private function probeNameList(string $role): string
    {
        $this->seedRegion(self::SUBJECT, self::BOX_A);

        $res = self::$clients[$role]->call('pwg.persons.getList',
            array('q' => self::SUBJECT, 'per_page' => PERSONS_SEARCH_MAX_RESULTS));
        $names = array_column($res['json']['result']['persons'] ?? array(), 'name');

        return self::level(array(
            'content' => array('getList' => self::accepted($res) && in_array(self::SUBJECT, $names, true)),
            ));
    }

    /**
     * The persons list screen: the list with the person, the controls, and the
     * three methods behind them - rename to the same name and a rescan of the
     * fixture photo change nothing, the delete removes a person of its own.
     */
    private function probeAdminScreen(string $role): string
    {
        $this->seedRegion(self::SUBJECT, self::BOX_A);
        $this->seedRegion(self::DOOMED, self::BOX_B);
        $subjectId = $this->personIdOf(self::SUBJECT);
        $doomedId = $this->personIdOf(self::DOOMED);

        $page = $this->fetch($role, '/admin.php?page=plugin-persons&q=' . urlencode('Persons Matrix'));
        $row = preg_match('~<tr data-person="' . $subjectId . '".*?</tr>~s', $page, $m) ? $m[0] : '';

        $ws = self::$clients[$role];
        $token = $this->tokenOf($role);
        $rename = $ws->call('pwg.persons.rename', array('person_id' => $subjectId, 'name' => self::SUBJECT, 'pwg_token' => $token));
        $rescan = $ws->call('pwg.persons.rescan', array('image_ids' => (string)$this->image['id'], 'pwg_token' => $token));
        $delete = $ws->call('pwg.persons.delete', array('person_id' => $doomedId, 'pwg_token' => $token));

        return self::level(array(
            'content' => array(
                'list' => strpos($page, 'id="persons-table"') !== false,
                'the person\'s row' => $row !== '',
                ),
            'control' => array(
                'rescan and actions' => strpos($page, 'id="persons-admin-actions"') !== false,
                'rename on the row' => strpos($row, 'class="persons-rename"') !== false,
                'delete on the row' => strpos($row, 'class="persons-delete"') !== false,
                ),
            'write' => array(
                'rename' => self::accepted($rename),
                'rescan' => self::accepted($rescan),
                'delete' => self::accepted($delete) && $this->personIdOf(self::DOOMED) === null,
                ),
            ));
    }

    /**
     * The tagging screen for one photo: its stage with the region, its editor,
     * and a save sent from it. The save is addRegion, which normal users may
     * call from the picture page (add-region), so it is only read where the
     * screen was served: a role the screen refuses has no save there to make.
     */
    private function probeAdminTagging(string $role): string
    {
        $regionId = $this->seedRegion(self::SUBJECT, self::BOX_A);
        $path = '/admin.php?page=plugin-persons&image_id=' . $this->image['id'];

        $page = $this->fetch($role, $path);
        $served = strpos($page, 'id="persons-stage"') !== false;

        $parts = array(
            'content' => array(
                'stage' => $served,
                'the region' => strpos($page, 'data-person-region="' . $regionId . '"') !== false,
                ),
            'control' => array('editor' => strpos($page, 'id="persons-editor"') !== false),
            );

        if ($served)
        {
            $save = self::$clients[$role]->callFrom($path, 'pwg.persons.addRegion', array(
                'image_id' => $this->image['id'], 'name' => self::ADDED,
                'pwg_token' => $this->tokenOf($role),
                ) + self::BOX_C);
            $parts['write'] = array('save from the screen' => self::accepted($save)
                && $this->regionIdOf(self::ADDED) !== null);
        }

        return self::level($parts);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Folds the parts a probe read into one level, per the table in the class
     * comment. A part whose checks disagree among themselves is inconsistent
     * on its own.
     *
     * @param array $parts 'content' | 'control' | 'write' => array(check => bool)
     */
    private static function level(array $parts): string
    {
        $seen = array();
        foreach ($parts as $part => $checks)
        {
            $values = array_unique(array_values($checks));
            if (count($values) !== 1)
            {
                return "INCONSISTENT: $part split: " . json_encode($checks);
            }
            $seen[$part] = $values[0];
        }

        $content = $seen['content'];
        $rest = array_diff_key($seen, array('content' => true));

        if (!$content && !in_array(true, $rest, true))
        {
            return 'none';
        }
        if ($content && !in_array(true, $rest, true))
        {
            return 'see';
        }
        if ($content && !in_array(false, $rest, true))
        {
            return 'edit';
        }

        return 'INCONSISTENT: ' . json_encode($parts);
    }

    private static function accepted(array $res): bool
    {
        return ($res['json']['stat'] ?? '') === 'ok';
    }

    /** The token a role's page would carry; a guest has none and sends a stand-in. */
    private function tokenOf(string $role): string
    {
        return $role === 'guest' ? 'anything' : self::$clients[$role]->token();
    }

    private function fetch(string $role, string $path): string
    {
        $res = self::$clients[$role]->fetchPage($path);
        $this->assertGreaterThan(0, strlen((string)$res['body']), "anti-vacuity: $path answered nothing to $role");

        return (string)$res['body'];
    }

    /** The picture page as the role sees it, script blocks removed. */
    private function picturePage(string $role): string
    {
        $markup = (string)preg_replace('#<script\b.*?</script>#is', '',
            $this->fetch($role, '/picture.php?/' . $this->image['id'] . '/category/' . self::$album));

        if ($role !== 'guest')
        {
            $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($markup), "the picture page did not render for $role");
        }

        return $markup;
    }

    /** One region drawn by the webmaster through the API; its region id. */
    private function seedRegion(string $name, array $box): int
    {
        $ws = self::$clients['webmaster'];
        $res = $ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'], 'name' => $name, 'pwg_token' => $ws->token(),
            ) + $box);

        if (!self::accepted($res))
        {
            throw new RuntimeException("could not seed $name: " . $res['body']);
        }

        $id = $this->regionIdOf($name);
        if ($id === null)
        {
            throw new RuntimeException("seeding $name indexed no region");
        }

        return $id;
    }

    private function setDrawer(int $regionId, string $role): void
    {
        $username = array(
            'normal'    => Config::normalUsername(),
            'generic'   => Config::genericUsername(),
            'admin'     => Config::adminUsername(),
            'webmaster' => Config::username(),
            )[$role];
        $userId = (int)$this->db->scalar(
            "SELECT id FROM piwigo_users WHERE username = '" . $this->db->escape($username) . "'");
        $this->db->query("UPDATE piwigo_person_region SET drawn_by = $userId WHERE id = $regionId");

        $this->assertSame($userId,
            (int)$this->db->scalar("SELECT drawn_by FROM piwigo_person_region WHERE id = $regionId"),
            "precondition: region $regionId must be drawn by $username");
    }

    private function regionIdOf(string $name): ?int
    {
        $id = $this->db->scalar(
            'SELECT r.id FROM piwigo_person_region AS r JOIN piwigo_persons AS p ON p.id = r.person_id'
            . ' WHERE r.image_id = ' . (int)$this->image['id']
            . " AND p.name = '" . $this->db->escape($name) . "'");

        return $id === null ? null : (int)$id;
    }

    private function personIdOf(string $name): ?int
    {
        $id = $this->db->scalar("SELECT id FROM piwigo_persons WHERE name = '" . $this->db->escape($name) . "'");

        return $id === null ? null : (int)$id;
    }

    private function boxHasDelete(string $markup, int $regionId): bool
    {
        if (!preg_match('#<div class="person-box[^"]*" data-person-region="' . $regionId . '".*?</div>#s', $markup, $box))
        {
            return false;
        }

        return strpos($box[0], 'class="person-box-delete"') !== false;
    }
}
