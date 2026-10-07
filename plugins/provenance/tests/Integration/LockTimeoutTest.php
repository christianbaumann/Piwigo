<?php
use PHPUnit\Framework\TestCase;

/**
 * provenance_exiftool_run() never writes a file whose lock another writer holds.
 *
 * Runs the runner in-process against a photo this suite created. The other
 * writer is a second open file on the same lock path: flock(2) excludes per
 * open file description, so that is what another request looks like even from
 * inside this process.
 */
final class LockTimeoutTest extends TestCase
{
    private Db $db;
    private FixtureBuilder $fixture;
    /** @var array id, db_path, file */
    private array $image;
    private string $argfile;

    public static function setUpBeforeClass(): void
    {
        PiwigoRuntime::boot();

        if (!defined('PHPWG_ROOT_PATH'))
        {
            define('PHPWG_ROOT_PATH', PIWIGO_ROOT);
        }
        if (!defined('PROVENANCE_XMP_CONFIG'))
        {
            define('PROVENANCE_XMP_CONFIG', PROVENANCE_PATH . 'exiftool/pwgprov.config');
        }
        require_once PROVENANCE_PATH . 'include/exiftool.inc.php';
    }

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->image = $this->fixture->createTestImage();

        $this->argfile = tempnam(sys_get_temp_dir(), 'provenance-lock-');
        file_put_contents($this->argfile, implode("\n", provenance_build_argfile(
            array('provenance_owner' => 'Anna Müller'),
            'Owner: Anna Müller'
        )) . "\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->argfile);
        $this->fixture->destroyTestImages();
    }

    /**
     * [ERR] With the lock held elsewhere, the runner gives up after
     * PROVENANCE_LOCK_TIMEOUT_SECONDS, reports a failure and leaves the file
     * as it was. Oracle: the current implementation - the research measured
     * that two concurrent writers destroy a file, but no requirement fixes
     * how a waiting writer reports giving up.
     *
     * Slow on purpose: it lasts PROVENANCE_LOCK_TIMEOUT_SECONDS.
     */
    public function testARunWaitsForTheLockAndGivesUpWithoutWriting(): void
    {
        $before = $this->fileState();

        provenance_make_dir(PROVENANCE_LOCK_DIR);
        $held = fopen(provenance_lock_path($this->image['db_path']), 'c');
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB), 'the test could not take the lock itself');

        try
        {
            $result = provenance_exiftool_run($this->argfile, $this->image['file'], $this->image['db_path']);
        }
        finally
        {
            flock($held, LOCK_UN);
            fclose($held);
        }

        $this->assertFalse($result['ok'], 'the runner wrote while another writer held the lock');
        $this->assertNotSame('', $result['message']);
        $this->assertSame($before, $this->fileState(), 'the file changed although the lock was held');
        $this->assertFileDoesNotExist($this->image['file'] . '_original');
    }

    /**
     * [HAPPY] Anti-vacuity for the case above: with the lock free, the same
     * argfile does change the file, so an unchanged file there means the lock
     * stopped it rather than the argfile being inert.
     */
    public function testARunWithTheLockFreeWritesTheFile(): void
    {
        $before = $this->fileState();

        $result = provenance_exiftool_run($this->argfile, $this->image['file'], $this->image['db_path']);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertNotSame($before, $this->fileState());
    }

    /** Everything about the file a write would change. */
    private function fileState(): array
    {
        clearstatcache(true, $this->image['file']);

        return array(filemtime($this->image['file']), filesize($this->image['file']), hash_file('sha256', $this->image['file']));
    }
}
