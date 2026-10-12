<?php
use PHPUnit\Framework\TestCase;

/**
 * The three methods that reach beyond one photo are the webmaster's.
 *
 * admin_only keeps a normal user out; the handlers refuse an administrator who
 * is not the webmaster (decision 0057). The webmaster's own happy paths are
 * PersonAdminApiTest's.
 *
 * Renaming or deleting a person rewrites every file carrying them, and a rescan
 * walks whatever it is handed, so none of the three is a photo-level edit the
 * per-image visibility gate could bound.
 *
 * The refusal code is core's, not this plugin's: WS::invoke() answers
 * PwgError(401) for an admin_only method (include/ws_core.inc.php:515). [ERR] -
 * recorded here because the plan expected 403 and core does not do that.
 */
final class AdminOnlyTest extends TestCase
{
    private const ADMIN_PROBE_NAME = 'Persons Admin Gate Probe';


    private FixtureBuilder $fixture;
    private WsClient $normal;

    /** @var array method => the parameters it needs to get past the dispatcher */
    private const GATED = array(
        'pwg.persons.rename' => array('person_id' => 1, 'name' => 'Persons Gate Probe'),
        'pwg.persons.delete' => array('person_id' => 1),
        'pwg.persons.rescan' => array('image_ids' => '1'),
        );

    protected function setUp(): void
    {
        $this->fixture = new FixtureBuilder(new Db());
        PiwigoRuntime::boot();

        if (!$this->fixture->tableExists('piwigo_person_region'))
        {
            $this->markTestSkipped('the persons plugin is not installed; activate it first');
        }

        $this->normal = new WsClient();
        $this->normal->login(Config::normalUsername(), Config::normalPassword());
    }

    /**
     * [NEG] An authenticated non-admin is refused by all three, with a valid
     * token - so what is proved is the role gate, not the CSRF gate.
     *
     */
    #[PHPUnit\Framework\Attributes\DataProvider('gatedMethods')]
    public function testANormalUserCannotRenameDeleteOrRescan(string $method): void
    {
        $res = $this->normal->call($method,
            self::GATED[$method] + array('pwg_token' => $this->normal->token()));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(401, (int)$res['json']['err'], $res['body']);
    }

    /**
     * [NEG] An administrator who is not the webmaster is refused by all three,
     * with a valid token, on a real person - and the index and the file stay
     * exactly as they were.
     */
    #[PHPUnit\Framework\Attributes\DataProvider('gatedMethods')]
    public function testAnAdministratorCannotRenameDeleteOrRescan(string $method): void
    {
        $db = new Db();
        $image = $this->fixture->createTestImage();
        $album = $this->fixture->createTestAlbum('Persons admin gate fixture');
        $this->fixture->attachImage((int)$image['id'], $album);
        $this->fixture->invalidateUserCache();

        $webmaster = new WsClient();
        $webmaster->login(Config::username(), Config::password());
        $seeded = $webmaster->call('pwg.persons.addRegion', array(
            'image_id' => (int)$image['id'], 'name' => self::ADMIN_PROBE_NAME,
            'x' => 0.25, 'y' => 0.25, 'w' => 0.2, 'h' => 0.2,
            'pwg_token' => $webmaster->token(),
            ));

        try
        {
            $this->assertSame('ok', $seeded['json']['stat'] ?? null, 'could not seed a region: ' . $seeded['body']);
            $personId = (int)$db->scalar("SELECT id FROM piwigo_persons WHERE name = '" . self::ADMIN_PROBE_NAME . "'");
            $this->assertGreaterThan(0, $personId, 'anti-vacuity: no person to act on');
            $regionsBefore = (int)$db->scalar("SELECT COUNT(*) FROM piwigo_person_region WHERE person_id = $personId");
            $this->assertSame(1, $regionsBefore);
            clearstatcache();
            $fileBefore = md5_file($image['file']);

            $params = array(
                'pwg.persons.rename' => array('person_id' => $personId, 'name' => self::ADMIN_PROBE_NAME . ' Renamed'),
                'pwg.persons.delete' => array('person_id' => $personId),
                'pwg.persons.rescan' => array('image_ids' => (string)$image['id']),
                )[$method];

            $admin = new WsClient();
            $admin->login(Config::adminUsername(), Config::adminPassword());
            $res = $admin->call($method, $params + array('pwg_token' => $admin->token()));

            $this->assertSame('fail', $res['json']['stat'], $res['body']);
            $this->assertSame(401, (int)$res['json']['err'], $res['body']);
            // Core's admin_only refusal is a 401 too; only this message says the
            // caller got past it and was stopped by the webmaster check.
            $this->assertSame(PERSONS_WEBMASTER_ONLY_MESSAGE, $res['json']['message'] ?? null, $res['body']);
            $this->assertSame(self::ADMIN_PROBE_NAME,
                $db->scalar("SELECT name FROM piwigo_persons WHERE id = $personId"));
            $this->assertSame($regionsBefore,
                (int)$db->scalar("SELECT COUNT(*) FROM piwigo_person_region WHERE person_id = $personId"));
            clearstatcache();
            $this->assertSame($fileBefore, md5_file($image['file']), 'the file was rewritten');
        }
        finally
        {
            $this->fixture->destroyTestImages();
            $this->fixture->destroyTestAlbums();
            $this->fixture->destroyPersons(array(self::ADMIN_PROBE_NAME, self::ADMIN_PROBE_NAME . ' Renamed'));
        }
    }

    /** [NEG] Nor may a guest. */
    public function testAGuestCannotRenameDeleteOrRescan(): void
    {
        $guest = new WsClient();

        foreach (self::GATED as $method => $params)
        {
            $res = $guest->call($method, $params + array('pwg_token' => 'anything'));
            $this->assertSame(401, (int)$res['json']['err'], "$method: " . $res['body']);
        }
    }

    public static function gatedMethods(): array
    {
        $cases = array();
        foreach (array_keys(self::GATED) as $method)
        {
            $cases[$method] = array($method);
        }

        if (count($cases) !== 3)
        {
            throw new RuntimeException('anti-vacuity: the gated-method table lost entries');
        }

        return $cases;
    }
}
