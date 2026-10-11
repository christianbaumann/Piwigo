<?php
use PHPUnit\Framework\TestCase;

/**
 * The E2E seed's --restore removes everything a seed created, also after a
 * seed that was never restored or that failed halfway.
 *
 * Both are what a killed or failing spec leaves: five albums and their copied
 * photos were orphaned that way by 2026-08-31 (research note
 * docs/agents/research/2026-10-11-persons-test-leak.md). seed.php runs as its
 * own process here, as the specs run it, because what is under test is the
 * snapshot file passed from one process to the next.
 */
final class SeedRestoreTest extends TestCase
{
    private const SEED_SCRIPT = __DIR__ . '/../e2e/support/seed.php';
    private const SNAPSHOT_FILE = __DIR__ . '/../e2e/.state/snapshot.json';
    private const ALBUM_PATTERN = 'Persons E2E %';
    private const IMAGE_PATTERN = './upload/persons-test/%';
    private const IMAGE_DIR = PIWIGO_ROOT . 'upload/persons-test/';
    private const NO_EXIFTOOL_PATH = '/nonexistent-persons-seed-test';

    private Db $db;
    private array $before;

    /** Set once the precondition held: tearDown must not restore a seed this test did not make. */
    private bool $mayRestore = false;

    protected function setUp(): void
    {
        $this->db = new Db();
        $fixture = new FixtureBuilder($this->db);

        if (!$fixture->tableExists('piwigo_person_region'))
        {
            $this->markTestSkipped('the persons plugin is not installed; activate it first');
        }
        if (is_file(self::SNAPSHOT_FILE))
        {
            $this->fail('an E2E seed is still outstanding; run `php ' . self::SEED_SCRIPT . ' --restore` first');
        }

        $this->before = $this->state();
        $this->mayRestore = true;
    }

    protected function tearDown(): void
    {
        if ($this->mayRestore)
        {
            $restore = $this->seed(array('--restore'));
            $this->assertSame(0, $restore['status'], 'restore failed: ' . $restore['output']);
        }
    }

    /** [NEG] A seed over an unrestored one does not orphan the first seed's photo and album. */
    public function testOneRestoreRemovesTwoSeedsInARow(): void
    {
        $first = $this->seedScenario('empty');
        $second = $this->seedScenario('empty');
        $this->assertNotSame($first['photo_id'], $second['photo_id'], 'anti-vacuity: the second seed made nothing new');
        $this->assertNotSame($this->before, $this->state(), 'anti-vacuity: state() does not see what was seeded');

        $this->assertSame(0, $this->seed(array('--restore'))['status']);

        $this->assertSame($this->before, $this->state());
    }

    /** [NEG] A seed that fails after creating its photo and album leaves nothing --restore misses. */
    public function testRestoreRemovesWhatAFailedSeedCreated(): void
    {
        // 'marked' draws its photo with GD, so without exiftool on the PATH the
        // seed creates the photo and the album and then fails writing regions.
        $failed = $this->seed(array('--scenario=marked'), self::NO_EXIFTOOL_PATH);
        $this->assertNotSame(0, $failed['status'], 'the seed was expected to fail writing regions: ' . $failed['output']);
        $this->assertStringContainsString('seeding regions failed', $failed['output']);
        $this->assertNotSame($this->before, $this->state(), 'anti-vacuity: the failed seed created nothing');

        $this->assertSame(0, $this->seed(array('--restore'))['status']);

        $this->assertSame($this->before, $this->state());
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function seedScenario(string $scenario): array
    {
        $run = $this->seed(array('--scenario=' . $scenario));
        $this->assertSame(0, $run['status'], 'seed failed: ' . $run['output']);

        $decoded = json_decode($run['output'], true);
        $this->assertIsArray($decoded, 'seed printed no JSON: ' . $run['output']);

        return $decoded;
    }

    /** @return array status and combined output of one seed.php process */
    private function seed(array $args, ?string $path = null): array
    {
        $output = array();
        $status = 1;
        exec(
            ($path === null ? '' : 'PATH=' . escapeshellarg($path) . ' ')
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::SEED_SCRIPT) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1',
            $output,
            $status
        );

        return array('status' => $status, 'output' => implode("\n", $output));
    }

    /** The seeded albums, photo rows and copied files, as one comparable value. */
    private function state(): array
    {
        $albums = array();
        $result = $this->db->query(
            "SELECT id FROM piwigo_categories WHERE name LIKE '" . self::ALBUM_PATTERN . "' ORDER BY id"
        );
        while ($row = $result->fetch_assoc())
        {
            $albums[] = (int)$row['id'];
        }

        $images = array();
        $result = $this->db->query(
            "SELECT id FROM piwigo_images WHERE path LIKE '" . self::IMAGE_PATTERN . "' ORDER BY id"
        );
        while ($row = $result->fetch_assoc())
        {
            $images[] = (int)$row['id'];
        }

        clearstatcache();
        $files = is_dir(self::IMAGE_DIR) ? array_values(array_diff(scandir(self::IMAGE_DIR), array('.', '..'))) : array();

        return array('albums' => $albums, 'images' => $images, 'files' => $files);
    }
}
