<?php
use PHPUnit\Framework\TestCase;

/**
 * Who drew a region, and who may delete it (decision 0065).
 *
 * A normal user deletes only the regions they drew; an administrator or the
 * webmaster deletes any. A region found in the file, which no account drew
 * here, is nobody's. Every check of the file is a plain exiftool run of this
 * test's own.
 */
final class RegionDrawerTest extends TestCase
{
    /** A rendered picture page shorter than this is an error page, not the photo screen. */
    private const MIN_PAGE_BYTES = 2000;

    private const JANE = 'Persons Drawer Jane';
    private const JOHN = 'Persons Drawer John';

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private int $catId;

    /** @var WsClient[] role => a logged-in client */
    private array $clients = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        PiwigoRuntime::loadPlugin();
        PiwigoRuntime::resetRequestCaches();

        if (!$this->fixture->columnExists('piwigo_person_region', 'drawn_by'))
        {
            $this->fail('piwigo_person_region has no drawn_by column; load any page so the plugin update runs');
        }

        $this->image = $this->fixture->createTestImage();
        $this->catId = $this->fixture->createTestAlbum('Persons drawer fixture');
        $this->fixture->attachImage((int)$this->image['id'], $this->catId);
        $this->fixture->invalidateUserCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client)
        {
            $client->logout();
        }

        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPersons(array(self::JANE, self::JOHN));
    }

    /** [HAPPY] addRegion records the caller as the region's drawer. */
    public function testAddingARegionRecordsWhoDrewIt(): void
    {
        $this->add('normal', self::JANE, 0.3);

        $this->assertSame($this->userId(Config::normalUsername()), $this->drawnBy(self::JANE));
    }

    /** [HAPPY] A normal user deletes a region they drew. */
    public function testANormalUserDeletesTheirOwnRegion(): void
    {
        $this->add('normal', self::JANE, 0.3);

        $res = $this->delete('normal', self::JANE);

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(array(), $this->namesInFile());
    }

    /** [NEG] A normal user may not delete a region another user drew, and the file keeps it. */
    public function testANormalUserCannotDeleteAnotherUsersRegion(): void
    {
        $this->add('webmaster', self::JANE, 0.3);

        $res = $this->delete('normal', self::JANE);

        $this->assertSame(403, (int)($res['json']['err'] ?? 0), $res['body']);
        $this->assertSame(array(self::JANE), $this->namesInFile());
    }

    /** [NEG] A region found in the file is nobody's, so a normal user may not delete it. */
    public function testANormalUserCannotDeleteNobodysRegion(): void
    {
        $this->seedNobodys();

        $res = $this->delete('normal', self::JANE);

        $this->assertSame(403, (int)($res['json']['err'] ?? 0), $res['body']);
        $this->assertSame(array(self::JANE), $this->namesInFile());
    }

    /**
     * [DT] An administrator and the webmaster delete any region: another user's
     * and nobody's.
     */
    #[PHPUnit\Framework\Attributes\DataProvider('privilegedRoles')]
    public function testAPrivilegedUserDeletesAnyRegion(string $role): void
    {
        $this->seedNobodys();
        $this->add('normal', self::JOHN, 0.7);
        $this->assertSame(array(self::JANE, self::JOHN), $this->namesInFile(), 'anti-vacuity: both regions must be there');

        foreach (array(self::JANE, self::JOHN) as $name)
        {
            $res = $this->delete($role, $name);
            $this->assertSame('ok', $res['json']['stat'], $res['body']);
        }

        $this->assertSame(array(), $this->namesInFile());
    }

    public static function privilegedRoles(): array
    {
        return array('administrator' => array('admin'), 'webmaster' => array('webmaster'));
    }

    /** [ST] A write that reindexes the photo keeps the drawer of every region it left alone. */
    public function testAnotherWriteKeepsWhoDrewAnUnchangedRegion(): void
    {
        $this->add('normal', self::JANE, 0.3);
        $janeId = $this->regionId(self::JANE);

        $this->add('webmaster', self::JOHN, 0.7);

        $this->assertSame($janeId, $this->regionId(self::JANE), 'anti-vacuity: the region must be the same row');
        $this->assertSame($this->userId(Config::normalUsername()), $this->drawnBy(self::JANE));
        $this->assertSame($this->userId(Config::username()), $this->drawnBy(self::JOHN));
    }

    /** [NEG] Re-adding another user's box does not make it the caller's. */
    public function testReAddingAnExistingBoxDoesNotTakeItOver(): void
    {
        $this->add('webmaster', self::JANE, 0.3);
        $this->add('normal', self::JANE, 0.3);

        $this->assertSame($this->userId(Config::username()), $this->drawnBy(self::JANE));
    }

    /**
     * [HAPPY]/[NEG] The picture page offers a normal user the delete control on
     * their own region only.
     */
    public function testThePageOffersANormalUserDeleteOnlyOnTheirOwnRegion(): void
    {
        $this->seedNobodys();
        $this->add('normal', self::JOHN, 0.7);

        $markup = $this->pictureMarkup('normal');

        $this->assertTrue($this->boxHasDelete($markup, $this->regionId(self::JOHN)), 'own region');
        $this->assertFalse($this->boxHasDelete($markup, $this->regionId(self::JANE)), "nobody's region");
    }

    /** [HAPPY] The page offers an administrator the delete control on every region. */
    public function testThePageOffersAnAdministratorDeleteOnEveryRegion(): void
    {
        $this->seedNobodys();
        $this->add('normal', self::JOHN, 0.7);

        $markup = $this->pictureMarkup('admin');

        $this->assertTrue($this->boxHasDelete($markup, $this->regionId(self::JOHN)), "another user's region");
        $this->assertTrue($this->boxHasDelete($markup, $this->regionId(self::JANE)), "nobody's region");
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function client(string $role): WsClient
    {
        if (!isset($this->clients[$role]))
        {
            $credentials = array(
                'normal'    => array(Config::normalUsername(), Config::normalPassword()),
                'admin'     => array(Config::adminUsername(), Config::adminPassword()),
                'webmaster' => array(Config::username(), Config::password()),
                );

            $client = new WsClient();
            $client->login($credentials[$role][0], $credentials[$role][1]);
            $this->clients[$role] = $client;
        }

        return $this->clients[$role];
    }

    private function add(string $role, string $name, float $x): void
    {
        $ws = $this->client($role);
        $res = $ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'],
            'name' => $name,
            'x' => $x, 'y' => 0.4, 'w' => 0.2, 'h' => 0.2,
            'pwg_token' => $ws->token(),
            ));

        if (($res['json']['stat'] ?? '') !== 'ok')
        {
            throw new RuntimeException("could not seed $name as $role: " . $res['body']);
        }
    }

    private function delete(string $role, string $name): array
    {
        $ws = $this->client($role);

        return $ws->call('pwg.persons.deleteRegion', array(
            'region_id' => $this->regionId($name),
            'pwg_token' => $ws->token(),
            ));
    }

    /** JANE written into the file by a plain exiftool call and indexed: drawn by nobody. */
    private function seedNobodys(): void
    {
        $this->fixture->writeRegionsWithExiftool($this->image,
            array(array('name' => self::JANE, 'x' => 0.3, 'y' => 0.4, 'w' => 0.2, 'h' => 0.2)),
            (int)$this->image['width'], (int)$this->image['height']);

        $outcome = persons_reindex_image((int)$this->image['id'], $this->image['file']);
        if (!$outcome['ok'])
        {
            throw new RuntimeException('fixture reindex failed: ' . $outcome['message']);
        }

        $this->assertNull($this->drawnBy(self::JANE), 'precondition: a region found in the file is nobody\'s');
    }

    private function regionId(string $name): int
    {
        return (int)$this->regionRow($name)['id'];
    }

    private function drawnBy(string $name): ?int
    {
        $drawnBy = $this->regionRow($name)['drawn_by'];

        return $drawnBy === null ? null : (int)$drawnBy;
    }

    private function regionRow(string $name): array
    {
        $result = $this->db->query(
            'SELECT r.id, r.drawn_by FROM piwigo_person_region AS r'
            . ' JOIN piwigo_persons AS p ON p.id = r.person_id'
            . ' WHERE r.image_id = ' . (int)$this->image['id']
            . " AND p.name = '" . $this->db->escape($name) . "'"
        );

        $rows = array();
        while ($row = $result->fetch_assoc())
        {
            $rows[] = $row;
        }

        if (count($rows) !== 1)
        {
            throw new RuntimeException('expected one indexed region for ' . $name . ', found ' . count($rows));
        }

        return $rows[0];
    }

    private function userId(string $username): int
    {
        $id = $this->db->scalar(
            "SELECT id FROM piwigo_users WHERE username = '" . $this->db->escape($username) . "'"
        );

        if ($id === null)
        {
            throw new RuntimeException("no account $username; run create-test-users.php");
        }

        return (int)$id;
    }

    /** The picture page as $role sees it, with every script block removed. */
    private function pictureMarkup(string $role): string
    {
        $res = $this->client($role)->fetchPage(
            '/picture.php?/' . $this->image['id'] . '/category/' . $this->catId
        );
        $this->assertSame(200, $res['http_code']);

        $markup = (string)preg_replace('#<script\b.*?</script>#is', '', (string)$res['body']);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($markup), 'the picture page did not render');

        return $markup;
    }

    private function boxHasDelete(string $markup, int $regionId): bool
    {
        $found = preg_match(
            '#<div class="person-box[^"]*" data-person-region="' . $regionId . '".*?</div>#s',
            $markup,
            $box
        );
        $this->assertSame(1, $found, "the page rendered no box for region $regionId");

        return strpos($box[0], 'class="person-box-delete"') !== false;
    }

    /** @return string[] the names the file itself carries, in file order */
    private function namesInFile(): array
    {
        $output = array();
        exec('exiftool -json -struct -XMP-mwg-rs:RegionInfo '
            . escapeshellarg($this->image['file']) . ' 2>/dev/null', $output);

        $json = implode('', $output);
        $this->assertGreaterThan(2, strlen($json), 'exiftool returned nothing to read');

        $names = array();
        foreach (json_decode($json, true)[0]['RegionInfo']['RegionList'] ?? array() as $region)
        {
            if (isset($region['Name']))
            {
                $names[] = $region['Name'];
            }
        }
        sort($names);

        return $names;
    }
}
