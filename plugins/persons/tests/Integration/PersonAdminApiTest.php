<?php
use PHPUnit\Framework\TestCase;

/**
 * The three administrator methods doing their work: rename, delete and rescan.
 *
 * Each one reaches past a single photo, which is why they are admin_only, and
 * which is why every assertion here checks the image file as well as the index -
 * a rename that moved only the database row would leave the gallery and the
 * files disagreeing until the next rescan.
 */
final class PersonAdminApiTest extends TestCase
{
    private const OLD_NAME = 'Persons Admin Jane Before';
    private const NEW_NAME = 'Persons Admin Jane After';
    private const OTHER = 'Persons Admin John';

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $admin;
    private array $image;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        PiwigoRuntime::boot();

        if (!$this->fixture->tableExists('piwigo_person_region'))
        {
            $this->markTestSkipped('the persons plugin is not installed; activate it first');
        }

        $this->image = $this->fixture->createTestImage();
        $album = $this->fixture->createTestAlbum('Persons admin fixture');
        $this->fixture->attachImage((int)$this->image['id'], $album);
        $this->fixture->invalidateUserCache();

        $this->admin = new WsClient();
        $this->admin->login(Config::username(), Config::password());
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPersons(array(self::OLD_NAME, self::NEW_NAME, self::OTHER));
    }

    /** [HAPPY] A rename reaches the person row, the mirrored tag and the file. */
    public function testRenamingAPersonUpdatesTheTagTheIndexAndTheFile(): void
    {
        $this->add(self::OLD_NAME);
        $personId = $this->personId(self::OLD_NAME);
        $this->assertNotNull($personId, 'anti-vacuity: nothing was created to rename');

        $res = $this->admin->call('pwg.persons.rename', array(
            'person_id' => $personId,
            'name' => self::NEW_NAME,
            'pwg_token' => $this->admin->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(1, (int)$res['json']['result']['photos']);
        $this->assertSame(array(), $res['json']['result']['failed']);

        $this->assertSame($personId, $this->personId(self::NEW_NAME), 'the same row must be renamed, not a new one');
        $this->assertNull($this->personId(self::OLD_NAME));

        $this->assertSame(self::NEW_NAME, $this->tagName($personId));
        $this->assertSame(array(self::NEW_NAME), $this->namesInFile());
    }

    /** [NEG] A rename onto a name another person already holds is refused. */
    public function testRenamingOntoAnExistingPersonIsRefused(): void
    {
        $this->add(self::OLD_NAME);
        $this->add(self::OTHER, 0.75, 0.75);

        $res = $this->admin->call('pwg.persons.rename', array(
            'person_id' => $this->personId(self::OLD_NAME),
            'name' => self::OTHER,
            'pwg_token' => $this->admin->token(),
            ));

        $this->assertSame(WS_ERR_INVALID_PARAM, (int)$res['json']['err'], $res['body']);
        $this->assertNotNull($this->personId(self::OLD_NAME), 'the refused rename went through anyway');
    }

    /** [NEG] A name with nothing usable left in it is refused. */
    public function testRenamingToAWhitespaceOnlyNameIsRefused(): void
    {
        $this->add(self::OLD_NAME);

        $res = $this->admin->call('pwg.persons.rename', array(
            'person_id' => $this->personId(self::OLD_NAME),
            'name' => '   ',
            'pwg_token' => $this->admin->token(),
            ));

        $this->assertSame(WS_ERR_INVALID_PARAM, (int)$res['json']['err'], $res['body']);
        $this->assertNotNull($this->personId(self::OLD_NAME));
    }

    /** [HAPPY] Deleting a person removes the regions from the files and the index. */
    public function testDeletingAPersonRemovesTheRegionsFromTheFileAndTheIndex(): void
    {
        $this->add(self::OLD_NAME);
        $this->add(self::OTHER, 0.75, 0.75);
        $personId = $this->personId(self::OLD_NAME);

        $res = $this->admin->call('pwg.persons.delete', array(
            'person_id' => $personId,
            'pwg_token' => $this->admin->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(1, (int)$res['json']['result']['photos']);

        $this->assertNull($this->personId(self::OLD_NAME));
        $this->assertSame(array(self::OTHER), $this->namesInFile(), 'the other person was deleted too');
        $this->assertSame(0, (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_person_region WHERE person_id = ' . $personId
            ));
    }

    /** [HAPPY] A rescan rebuilds the index from the file it is pointed at. */
    public function testRescanRebuildsTheIndexFromTheFile(): void
    {
        $this->add(self::OLD_NAME);
        $imageId = (int)$this->image['id'];

        // Thrown away behind the plugin's back: only a real re-read of the file
        // can put it back, so a rescan that did nothing would be visible.
        $this->db->query('DELETE FROM piwigo_person_region WHERE image_id = ' . $imageId);
        $this->assertSame(0, $this->regionCount());

        $res = $this->admin->call('pwg.persons.rescan', array(
            'image_ids' => (string)$imageId,
            'pwg_token' => $this->admin->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(1, (int)$res['json']['result']['scanned']);
        $this->assertSame(array(), $res['json']['result']['failed']);
        $this->assertSame(1, $this->regionCount());
    }

    /** [BVA] [NEG] More ids than one chunk allows is refused, not silently truncated. */
    public function testMoreIdsThanOneChunkIsRefused(): void
    {
        $ids = implode(',', range(1, PERSONS_WRITEBACK_MAX_CHUNK + 1));

        $res = $this->admin->call('pwg.persons.rescan', array(
            'image_ids' => $ids,
            'pwg_token' => $this->admin->token(),
            ));

        $this->assertSame(WS_ERR_INVALID_PARAM, (int)$res['json']['err'], $res['body']);
    }

    /** [NEG] A wrong CSRF token is refused even for an administrator. */
    public function testBadTokenIsRejected(): void
    {
        $this->add(self::OLD_NAME);

        $res = $this->admin->call('pwg.persons.rename', array(
            'person_id' => $this->personId(self::OLD_NAME),
            'name' => self::NEW_NAME,
            'pwg_token' => 'not-the-token',
            ));

        $this->assertSame(403, (int)$res['json']['err'], $res['body']);
        $this->assertNotNull($this->personId(self::OLD_NAME));
    }

    private function add(string $name, float $x = 0.25, float $y = 0.25): void
    {
        $res = $this->admin->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'],
            'name' => $name,
            'x' => $x, 'y' => $y, 'w' => 0.2, 'h' => 0.2,
            'pwg_token' => $this->admin->token(),
            ));

        if (($res['json']['stat'] ?? '') !== 'ok')
        {
            throw new RuntimeException("could not seed $name: " . $res['body']);
        }
    }

    private function personId(string $name): ?int
    {
        $id = $this->db->scalar(
            "SELECT id FROM piwigo_persons WHERE name = '" . $this->db->escape($name) . "'"
        );
        return $id === null ? null : (int)$id;
    }

    private function tagName(int $personId): ?string
    {
        $name = $this->db->scalar(
            'SELECT t.name FROM piwigo_tags AS t'
            . ' JOIN piwigo_persons AS p ON p.tag_id = t.id WHERE p.id = ' . $personId
        );
        return $name === null ? null : (string)$name;
    }

    private function regionCount(): int
    {
        return (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . (int)$this->image['id']
        );
    }

    /** @return string[] the names the file itself carries */
    private function namesInFile(): array
    {
        $output = array();
        exec('exiftool -json -struct -XMP-mwg-rs:RegionInfo '
            . escapeshellarg($this->image['file']) . ' 2>/dev/null', $output);

        $json = implode('', $output);
        $this->assertGreaterThan(2, strlen($json), 'exiftool returned nothing to read');

        $decoded = json_decode($json, true);
        $names = array();
        foreach ($decoded[0]['RegionInfo']['RegionList'] ?? array() as $region)
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
