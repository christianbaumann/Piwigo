<?php
use PHPUnit\Framework\TestCase;

/**
 * The three methods that reach beyond one photo are administrator-only.
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
