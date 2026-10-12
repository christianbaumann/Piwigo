<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The executable copy of photoinfo's rows of the role matrix
 * (.agents/changes/2026-10-12-role-test-coverage/design.md, "The target
 * matrix"): one row per function and role, each comparing the level a probe
 * observes against the level the matrix sets.
 *
 * The oracle is the matrix the owner set on 2026-10-11, not the code. A probe
 * sends the function's real requests as one role and reads three parts: the
 * content on the page, the control that edits it, and the ws methods behind
 * the control. An accepted call counts only when its effect reached the
 * database and the file; a refused one only when nothing changed. Parts that
 * disagree are INCONSISTENT, never a pass.
 *
 * Guests: the matrix assumes guest_access off (decision 0055), so a guest's
 * page request lands on the login page and its ws call is refused. The cases
 * that switch guest_access on stay in SetInfoTest and PicturePageSourceTest.
 */
final class RoleMatrixTest extends TestCase
{
    /** The prefix of the methods the guard holds to a row. */
    private const GUARD_PREFIX = 'pwg.photoinfo.';
    /** Methods under the prefix, measured 2026-10-12: setInfo, setDate, rescan, pruneTags. */
    private const MIN_GUARDED_METHODS = 4;
    /** Every page answer is longer than this, the 401 page (867 bytes) included. */
    private const MIN_RESPONSE_BYTES = 500;

    private const NONE = 'none';
    private const SEE = 'see';
    private const EDIT = 'edit';
    private const GUEST = 'guest';

    /** The matrix's columns, in its order. */
    private const ROLES = array(self::GUEST, TestUsers::GENERIC, TestUsers::NORMAL, TestUsers::ADMIN, TestUsers::WEBMASTER);

    /** function => expected level per role, in ROLES order: guest, generic, normal, admin, webmaster. */
    private const MATRIX = array(
        'photoinfo.rows' => array(self::NONE, self::SEE, self::SEE, self::SEE, self::SEE),
        'photoinfo.edit-date' => array(self::NONE, self::EDIT, self::EDIT, self::EDIT, self::EDIT),
        'photoinfo.edit-info' => array(self::NONE, self::EDIT, self::EDIT, self::EDIT, self::EDIT),
        'photoinfo.core-screens' => array(self::NONE, self::NONE, self::NONE, self::EDIT, self::EDIT),
        'photoinfo.tag-keywords' => array(self::NONE, self::EDIT, self::EDIT, self::EDIT, self::EDIT),
        'photoinfo.rescan-prune' => array(self::NONE, self::NONE, self::NONE, self::EDIT, self::EDIT),
        );

    /**
     * function => the ws methods its probe calls. The wrapped core and typetags
     * methods are declared too, but the guard holds only GUARD_PREFIX to a row.
     */
    private const METHODS = array(
        'photoinfo.rows' => array(),
        'photoinfo.edit-date' => array('pwg.photoinfo.setDate'),
        'photoinfo.edit-info' => array('pwg.photoinfo.setInfo'),
        'photoinfo.core-screens' => array('pwg.images.setInfo', 'pwg.tags.rename'),
        'photoinfo.tag-keywords' => array('typetags.image.addTag', 'typetags.image.removeTag', 'typetags.image.addNewTag'),
        'photoinfo.rescan-prune' => array('pwg.photoinfo.rescan', 'pwg.photoinfo.pruneTags'),
        );

    private const COMMENT = 'Die Taufe im Garten';
    private const DATE_SHOWN = 'März 1965';
    private const DATE_ROW = 'id="PhotoDate"';
    private const INFO_ROW = 'id="PhotoInfo"';
    private const DATE_FORM = 'id="photoinfo-date-form"';
    private const INFO_FORM = 'id="photoinfo-info-form"';
    private const NEW_TAG_FORM = 'id="typetags-new-tag"';
    private const LOGIN_FORM = 'name="login_form"';

    /** @var WsClient[] one logged-in client per role, kept for the whole run */
    private static array $clients = array();
    /** @var string[] each client's pwg_token */
    private static array $tokens = array();

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private int $albumId;
    private string $suffix;
    /** @var string[] names the server may create while a case runs, adopted in tearDown */
    private array $typedNames = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');
        $this->fixture->assertPluginActive('typetags');
        $this->assertNull($this->db->scalar("SELECT value FROM piwigo_config WHERE param = 'guest_access'"),
            'a guest_access config row exists; the matrix assumes guests locked out. Delete it if a killed run left it');

        $this->suffix = bin2hex(random_bytes(4));
        $this->image = $this->fixture->createTestImage();
        FixtureBuilder::stripRegionsAndKeywords($this->image['file']);
        $this->albumId = $this->fixture->placeInPublicAlbum($this->image);
    }

    protected function tearDown(): void
    {
        foreach ($this->typedNames as $name)
        {
            $this->fixture->trackTagsNamed($name);
        }
        $this->fixture->destroyTestTags();
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    public static function cells(): array
    {
        $cells = array();
        foreach (self::MATRIX as $function => $levels)
        {
            foreach (self::ROLES as $i => $role)
            {
                $label = sprintf('%s as %s %s', $function, $role, self::technique($levels, $i));
                $cells[$label] = array($function, $role, $levels[$i]);
            }
        }
        return $cells;
    }

    /**
     * Each role gets exactly the level the matrix sets for the function. The
     * dataset name carries the techniques: [NEG] a role with no access, [ECP]
     * generic as normal's twin, [BVA] a role whose level differs from a
     * neighbouring column's, [HAPPY] none of these.
     */
    #[DataProvider('cells')]
    public function testTheRoleGetsItsLevel(string $function, string $role, string $expected): void
    {
        $probe = 'probe' . str_replace(' ', '', ucwords(str_replace(array('photoinfo.', '-'), array('', ' '), $function)));

        $this->assertSame($expected, $this->$probe($role), "$function as $role");
    }

    /**
     * [NEG] Every ws method under the prefix is called by some row, so a new
     * method cannot ship without its role rows; and every declared method exists.
     */
    public function testEveryMethodHasARow(): void
    {
        $res = self::client(TestUsers::WEBMASTER)->call('reflection.getMethodList');
        $all = $res['json']['result']['methods'] ?? array();
        $this->assertNotEmpty($all, $res['body']);

        $guarded = array_values(array_filter($all, fn ($method) => strpos($method, self::GUARD_PREFIX) === 0));
        $this->assertGreaterThanOrEqual(self::MIN_GUARDED_METHODS, count($guarded), 'anti-vacuity: too few ' . self::GUARD_PREFIX . ' methods');

        $declared = array_merge(...array_values(self::METHODS));
        foreach ($guarded as $method)
        {
            $this->assertContains($method, $declared, "no RoleMatrixTest row declares $method");
        }
        foreach ($declared as $method)
        {
            $this->assertContains($method, $all, "a row declares $method, which the install does not offer");
        }
    }

    // ── probes ────────────────────────────────────────────────────────────

    /** The Datum and Info rows: read-only content, no part of their own to edit. */
    private function probeRows(string $role): string
    {
        $this->fixture->setDate($this->image['id'], '1965-03-01 00:00:00', 'month');
        $this->fixture->setComment($this->image['id'], self::COMMENT);

        $page = $this->picturePage($role);

        return $this->observe(array(
            'content' => $this->containsAll($page, array(self::DATE_ROW, self::DATE_SHOWN, self::INFO_ROW, self::COMMENT)),
            ));
    }

    private function probeEditDate(string $role): string
    {
        $this->fixture->setDate($this->image['id'], '1965-03-01 00:00:00', 'month');
        $page = $this->picturePage($role);
        $before = $this->fileHash();

        $res = $this->call($role, 'pwg.photoinfo.setDate', array('image_id' => $this->image['id'], 'year' => '1971'));
        $row = $this->fixture->imageRow($this->image['id'])['date_creation'];

        return $this->observe(array(
            'content' => $this->containsAll($page, array(self::DATE_ROW, self::DATE_SHOWN)),
            'control' => $this->containsAll($page, array(self::DATE_FORM)),
            'pwg.photoinfo.setDate' => $this->outcome($res,
                $row === '1971-01-01 00:00:00' and FixtureBuilder::readDateTags($this->image['file'])['XMP-pwginfo:DateEDTF'] === '1971',
                $row === '1965-03-01 00:00:00' and $this->fileHash() === $before),
            ));
    }

    private function probeEditInfo(string $role): string
    {
        $this->fixture->setComment($this->image['id'], self::COMMENT);
        $page = $this->picturePage($role);
        $before = $this->fileHash();

        $res = $this->call($role, 'pwg.photoinfo.setInfo', array('image_id' => $this->image['id'], 'info' => 'Neu'));
        $row = $this->fixture->imageRow($this->image['id'])['comment'];

        return $this->observe(array(
            'content' => $this->containsAll($page, array(self::INFO_ROW, self::COMMENT)),
            'control' => $this->containsAll($page, array(self::INFO_FORM)),
            'pwg.photoinfo.setInfo' => $this->outcome($res,
                $row === 'Neu' and FixtureBuilder::readFileTags($this->image['file'])['XMP-pwginfo:Info'] === 'Neu',
                $row === self::COMMENT and $this->fileHash() === $before),
            ));
    }

    /** Core's photo properties screen, and the two kinds of core method photoinfo wraps. */
    private function probeCoreScreens(string $role): string
    {
        $this->fixture->setComment($this->image['id'], self::COMMENT);
        $tag = $this->fixture->createTag($this->tagName('Kirmes'));
        $this->fixture->linkTag($this->image['id'], $tag);
        $page = $this->page($role, '/admin.php?page=photo-' . $this->image['id'] . '-properties');
        $before = $this->fileHash();

        $setInfo = $this->call($role, 'pwg.images.setInfo',
            array('image_id' => $this->image['id'], 'comment' => 'Neu', 'single_value_mode' => 'replace'));
        $comment = $this->fixture->imageRow($this->image['id'])['comment'];
        $infoInFile = FixtureBuilder::readFileTags($this->image['file'])['XMP-pwginfo:Info'];
        $afterSetInfo = $this->fileHash();

        $renamed = $this->tagName('Kirmes neu');
        $rename = $this->call($role, 'pwg.tags.rename', array('tag_id' => $tag, 'new_name' => $renamed));
        $name = (string)$this->db->scalar("SELECT name FROM piwigo_tags WHERE id = $tag");

        return $this->observe(array(
            'content' => $this->containsAll($page, array('name="comment"', self::COMMENT)),
            'control' => $this->containsAll($page, array('name="date_creation"', 'name="submit"')),
            'pwg.images.setInfo' => $this->outcome($setInfo,
                $comment === 'Neu' and $infoInFile === 'Neu',
                $comment === self::COMMENT and $afterSetInfo === $before),
            'pwg.tags.rename' => $this->outcome($rename,
                $name === $renamed and in_array($renamed, FixtureBuilder::readKeywords($this->image['file'])['XMP-dc:Subject'], true),
                $name === $this->tagName('Kirmes') and $this->fileHash() === $afterSetInfo),
            ));
    }

    /** typetags' badges and field on the picture page, and the keywords photoinfo writes for them. */
    private function probeTagKeywords(string $role): string
    {
        $groupName = 'Feste ' . $this->suffix;
        $group = $this->fixture->createGroup($groupName);
        $shown = $this->fixture->createTag($this->tagName('Anna'), $group);
        $this->fixture->linkTag($this->image['id'], $shown);
        $added = $this->fixture->createTag($this->tagName('Kirmes'), $group);
        $page = $this->picturePage($role);

        $image = array('image_id' => $this->image['id']);
        $add = $this->call($role, 'typetags.image.addTag', $image + array('tag_id' => $added));
        $afterAdd = FixtureBuilder::readKeywords($this->image['file']);
        $tagsAfterAdd = $this->fixture->tagIdsOf($this->image['id']);

        $remove = $this->call($role, 'typetags.image.removeTag', $image + array('tag_id' => $shown));
        $afterRemove = FixtureBuilder::readKeywords($this->image['file']);
        $tagsAfterRemove = $this->fixture->tagIdsOf($this->image['id']);

        $typed = $this->tagName('Neu');
        $addNew = $this->call($role, 'typetags.image.addNewTag', $image + array('tag_name' => $typed));
        $afterAddNew = FixtureBuilder::readKeywords($this->image['file']);
        $typedExists = $this->db->scalar("SELECT id FROM piwigo_tags WHERE name = '" . $this->db->escape($typed) . "'") !== null;

        $both = array($this->tagName('Anna'), $this->tagName('Kirmes'));
        sort($both, SORT_STRING);
        $unwritten = ($afterAddNew['XMP-pwginfo:TagsWritten'] === null && $afterAddNew['XMP-dc:Subject'] === array());

        return $this->observe(array(
            'content' => $this->containsAll($page, array($this->tagName('Anna'))),
            'control' => $this->containsAll($page, array(self::NEW_TAG_FORM, 'typetag-add" data-tag-id="' . $added . '"')),
            'typetags.image.addTag' => $this->outcome($add,
                $this->keywordsAre($afterAdd, $both, $groupName),
                $tagsAfterAdd === array($shown) and $unwritten),
            'typetags.image.removeTag' => $this->outcome($remove,
                $this->keywordsAre($afterRemove, array($this->tagName('Kirmes')), $groupName),
                in_array($shown, $tagsAfterRemove, true) and $unwritten),
            'typetags.image.addNewTag' => $this->outcome($addNew,
                in_array($typed, $afterAddNew['XMP-dc:Subject'], true) and in_array($typed, $afterAddNew['IPTC:Keywords'], true),
                !$typedExists and $unwritten),
            ));
    }

    /** The deploy's two methods: no page part. */
    private function probeRescanPrune(string $role): string
    {
        FixtureBuilder::run('exiftool -config ' . escapeshellarg(PHOTOINFO_PATH . 'exiftool/pwginfo.config') . ' -q -overwrite_original '
            . escapeshellarg('-XMP-pwginfo:DateEDTF=1971') . ' ' . escapeshellarg($this->image['file']));
        $this->assertSame('1971', FixtureBuilder::readDateTags($this->image['file'])['XMP-pwginfo:DateEDTF'], 'the fixture date was not written');
        $tag = $this->fixture->createTag($this->tagName('Zug'));
        $this->fixture->linkTag($this->image['id'], $tag);
        FixtureBuilder::writeKeywords($this->image['file'], array(), array(), true);

        $ids = array('image_ids' => (string)$this->image['id']);
        $rescan = $this->call($role, 'pwg.photoinfo.rescan', $ids);
        $date = $this->fixture->imageRow($this->image['id'])['date_creation'];
        $prune = $this->call($role, 'pwg.photoinfo.pruneTags', $ids);
        $tags = $this->fixture->tagIdsOf($this->image['id']);

        return $this->observe(array(
            'pwg.photoinfo.rescan' => $this->outcome($rescan, $date === '1971-01-01 00:00:00', $date === null),
            'pwg.photoinfo.pruneTags' => $this->outcome($prune, $tags === array(), $tags === array($tag)),
            ));
    }

    // ── observing ─────────────────────────────────────────────────────────

    /**
     * The level the parts add up to: none when nothing shows or answers, see
     * when only the content shows, edit when every part does.
     *
     * @param array $parts name => true/false, or a string describing a ws
     *   answer that contradicts its own effect
     */
    private function observe(array $parts): string
    {
        $this->assertNotEmpty($parts);
        foreach ($parts as $name => $value)
        {
            if (is_string($value))
            {
                return "INCONSISTENT: $name $value";
            }
        }

        $shown = array_keys(array_filter($parts));
        if (count($shown) == 0)
        {
            return self::NONE;
        }
        if ($shown === array('content'))
        {
            return self::SEE;
        }
        if (count($shown) == count($parts))
        {
            return self::EDIT;
        }

        return 'INCONSISTENT: shown or accepted only ' . implode(', ', $shown) . ' of ' . implode(', ', array_keys($parts));
    }

    /**
     * true for a call that was accepted and took effect, false for one refused
     * that changed nothing, a description otherwise.
     *
     * @return bool|string
     */
    private function outcome(array $res, bool $effect, bool $untouched)
    {
        $ok = ($res['json']['stat'] ?? null) === 'ok';
        if ($ok and $effect)
        {
            return true;
        }
        if (!$ok and $untouched and isset($res['json']['err']))
        {
            return false;
        }

        return sprintf('answered %s but %s: %s', $ok ? 'ok' : 'an error',
            $effect ? 'took effect' : ($untouched ? 'changed nothing' : 'changed something else'), substr($res['body'], 0, 300));
    }

    /** The three keyword fields hold exactly these names, in the group, under the marker. */
    private function keywordsAre(array $keywords, array $names, string $group): bool
    {
        sort($names, SORT_STRING);
        $hierarchy = array_map(fn ($name) => $group . '|' . $name, $names);

        return $keywords['XMP-pwginfo:TagsWritten'] === '1'
            and $keywords['XMP-dc:Subject'] === $names
            and $keywords['IPTC:Keywords'] === $names
            and $keywords['XMP-lr:HierarchicalSubject'] === $hierarchy;
    }

    private function containsAll(string $page, array $needles): bool
    {
        foreach ($needles as $needle)
        {
            if (strpos($page, $needle) === false)
            {
                return false;
            }
        }
        return true;
    }

    // ── requests ──────────────────────────────────────────────────────────

    private function picturePage(string $role): string
    {
        $page = $this->page($role, '/picture.php?/' . $this->image['id'] . '/category/' . $this->albumId);
        if ($role !== self::GUEST)
        {
            $this->assertStringContainsString('id="theMainImage"', $page, "anti-vacuity: $role got no picture page");
        }
        return $page;
    }

    private function page(string $role, string $path): string
    {
        $page = self::client($role)->fetchPage($path);
        $this->assertGreaterThan(self::MIN_RESPONSE_BYTES, strlen($page), "anti-vacuity: $path answered nothing to $role");
        if ($role === self::GUEST)
        {
            $this->assertStringContainsString(self::LOGIN_FORM, $page, 'a guest was not sent to the login page; is guest_access on?');
        }
        return $page;
    }

    private function call(string $role, string $method, array $params): array
    {
        return self::client($role)->call($method, $params + array('pwg_token' => self::token($role)));
    }

    private static function client(string $role): WsClient
    {
        if (!isset(self::$clients[$role]))
        {
            $client = new WsClient();
            if ($role !== self::GUEST)
            {
                list($username, $password) = Config::credentials($role);
                $client->login($username, $password);
            }
            self::$clients[$role] = $client;
        }
        return self::$clients[$role];
    }

    /** A guest has no token worth asking for: core refuses its call before the token matters. */
    private static function token(string $role): string
    {
        if ($role === self::GUEST)
        {
            return 'none';
        }
        if (!isset(self::$tokens[$role]))
        {
            self::$tokens[$role] = self::client($role)->token();
        }
        return self::$tokens[$role];
    }

    private function fileHash(): string
    {
        clearstatcache();
        return md5_file($this->image['file']);
    }

    /** A tag name of this case's own, removed in tearDown even if the server created it. */
    private function tagName(string $base): string
    {
        $name = $base . ' ' . $this->suffix;
        $this->typedNames[] = $name;
        return $name;
    }

    /** @param string[] $levels the function's row in ROLES order */
    private static function technique(array $levels, int $i): string
    {
        $tags = array();
        if ($levels[$i] === self::NONE)
        {
            $tags[] = '[NEG]';
        }
        if (self::ROLES[$i] === TestUsers::GENERIC)
        {
            $tags[] = '[ECP]';
        }
        foreach (array($i - 1, $i + 1) as $neighbour)
        {
            if (isset($levels[$neighbour]) and $levels[$neighbour] !== $levels[$i])
            {
                $tags[] = '[BVA]';
                break;
            }
        }
        return count($tags) ? implode(' ', $tags) : '[HAPPY]';
    }
}
