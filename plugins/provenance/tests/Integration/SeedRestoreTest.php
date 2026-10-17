<?php
use PHPUnit\Framework\TestCase;

/**
 * The E2E seed's --restore removes every album a seed created, also after a
 * seed that was never restored or that could not record what it made.
 *
 * Twenty "provenance E2E tree ..." albums outlived task 35's runs. seed.php
 * runs as its own process here, as the specs run it, because what is under test
 * is the snapshot file passed from one process to the next.
 *
 * The comparison is against the whole album table as it was before the test, so
 * albums an earlier run left behind (and the real ones) are neither required
 * nor allowed to change.
 */
final class SeedRestoreTest extends TestCase
{
    private const SEED_SCRIPT = __DIR__ . '/../e2e/support/seed.php';
    private const STATE_DIR = __DIR__ . '/../e2e/.state';
    private const SNAPSHOT_FILE = self::STATE_DIR . '/snapshot.json';
    private const SEEDED_ALBUM_PATTERN = 'provenance E2E tree %';
    private const ALBUMS_PER_TREE_SEED = 4;
    private const REAL_ALBUM_MAX_ID = 5;

    private Db $db;
    private array $before;
    private bool $mayRestore = false;

    protected function setUp(): void
    {
        $this->db = new Db();
        if (is_file(self::SNAPSHOT_FILE))
        {
            $this->fail('an E2E seed is still outstanding; run `php ' . self::SEED_SCRIPT . ' --restore` first');
        }
        $this->before = $this->albums();
        $this->assertGreaterThanOrEqual(1, count($this->before), 'anti-vacuity: the album table read empty');
        $this->mayRestore = true;
    }

    protected function tearDown(): void
    {
        chmod(self::STATE_DIR, 0755);
        if ($this->mayRestore)
        {
            $restore = $this->seed(array('--restore'));
            $this->assertSame(0, $restore['status'], 'restore failed: ' . $restore['output']);
        }
    }

    /** [NEG] A seed over an unrestored one does not orphan the first seed's albums. */
    public function testOneRestoreRemovesTwoTreeSeedsInARow(): void
    {
        $first = $this->seedTree();
        $second = $this->seedTree();
        $this->assertNotSame($first['album_id'], $second['album_id'], 'anti-vacuity: the second seed made nothing new');
        $this->assertSame(
            self::ALBUMS_PER_TREE_SEED * 2,
            count($this->albums()) - count($this->before),
            'anti-vacuity: the seeds did not create the albums this test expects'
        );

        $this->assertSame(0, $this->seed(array('--restore'))['status']);

        $this->assertSame($this->before, $this->albums());
    }

    /** [NEG] A seed that cannot write its snapshot creates nothing, or leaves what --restore can still remove. */
    public function testASeedThatCannotRecordItselfLeavesNoAlbumBehind(): void
    {
        chmod(self::STATE_DIR, 0555);
        $this->assertFalse(is_writable(self::STATE_DIR), 'anti-vacuity: the state directory is still writable');

        $run = $this->seed(array('--scenario=album-tree'));
        chmod(self::STATE_DIR, 0755);

        $this->assertSame(0, $this->seed(array('--restore'))['status']);
        $this->assertSame($this->before, $this->albums(), 'seed output: ' . $run['output']);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function seedTree(): array
    {
        $run = $this->seed(array('--scenario=album-tree'));
        $this->assertSame(0, $run['status'], 'seed failed: ' . $run['output']);
        $decoded = json_decode($run['output'], true);
        $this->assertIsArray($decoded, 'seed printed no JSON: ' . $run['output']);
        $this->assertGreaterThan(self::REAL_ALBUM_MAX_ID, $decoded['album_id'], 'a seeded album took a real album\'s id');

        return $decoded;
    }

    /** @return array status and combined output of one seed.php process */
    private function seed(array $args): array
    {
        $output = array();
        $status = 1;
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::SEED_SCRIPT) . ' '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1',
            $output,
            $status
        );

        return array('status' => $status, 'output' => implode("\n", $output));
    }

    /** Every album row's id, name and place in the tree. */
    private function albums(): array
    {
        $rows = array();
        $result = $this->db->query('SELECT id, name, id_uppercat, uppercats FROM piwigo_categories ORDER BY id');
        while ($row = $result->fetch_assoc())
        {
            $rows[] = $row;
        }

        return $rows;
    }
}
