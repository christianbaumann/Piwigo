<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An edit and the other two plugins' writes exclude each other.
 *
 * persons and provenance take their own lock on photoedit_begin and give it
 * back on photoedit_end. Whether a lock is held is asked from a separate
 * process with flock(1): flock(2) excludes per open file description, so the
 * holder's own process could take it again.
 *
 * The lock paths come from the plugins' own path functions, loaded with their
 * handlers; this suite writes none down.
 */
final class ExclusionTest extends TestCase
{
    /** flock(1)'s exit status for "held by someone else" when asked with -E. */
    private const HELD = 9;

    /** How long the outside writer keeps the lock in the HTTP case. */
    private const HOLD_SECONDS = 2;

    /** Attempts, 100 ms apart, at seeing the outside writer's lock taken. */
    private const HOLD_POLLS = 50;

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private ?string $marker = null;

    public static function setUpBeforeClass(): void
    {
        PiwigoRuntime::boot();

        if (!defined('PERSONS_PATH'))
        {
            define('PERSONS_PATH', PIWIGO_ROOT . 'plugins/persons/');
        }
        require_once PERSONS_PATH . 'include/functions.inc.php';
        require_once PERSONS_PATH . 'include/events_photoedit.inc.php';

        if (!defined('PROVENANCE_PATH'))
        {
            define('PROVENANCE_PATH', PIWIGO_ROOT . 'plugins/provenance/');
        }
        require_once PROVENANCE_PATH . 'include/functions.inc.php';
        require_once PROVENANCE_PATH . 'include/exiftool.inc.php';
        require_once PROVENANCE_PATH . 'include/events_photoedit.inc.php';
    }

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive();
        $this->fixture->assertPluginActive('persons');
        $this->fixture->assertPluginActive('provenance');

        $this->image = $this->fixture->createMarkedImage();
    }

    protected function tearDown(): void
    {
        if ($this->marker !== null)
        {
            @unlink($this->marker);
        }
        $this->fixture->destroyTestImages();
    }

    /** The row fields the handlers read. */
    private function row(): array
    {
        return array('id' => $this->image['id'], 'path' => $this->image['db_path']);
    }

    /** flock(1)'s exit status trying the lock without waiting: 0 free, HELD taken. */
    private function tryLock(string $path): int
    {
        $output = array();
        $status = -1;
        exec('flock -n -E ' . self::HELD . ' ' . escapeshellarg($path) . ' true 2>&1', $output, $status);
        return $status;
    }

    public static function plugins(): array
    {
        return array(
            '[ST] persons' => array('persons_lock_path', 'persons_photoedit_begin', 'persons_photoedit_end'),
            '[ST] provenance' => array('provenance_lock_path', 'provenance_photoedit_begin', 'provenance_photoedit_end'),
            );
    }

    /**
     * [ST] From photoedit_begin to photoedit_end the plugin's lock is taken, so
     * its own writer on the photo waits; afterwards it is free again.
     */
    #[DataProvider('plugins')]
    public function testThePluginsLockIsHeldFromBeginToEnd(string $lockPath, string $begin, string $end): void
    {
        $path = $lockPath($this->image['db_path']);
        $this->assertSame(0, $this->tryLock($path), 'anti-vacuity: the lock is taken before the edit began');

        $begin($this->row());
        try
        {
            $this->assertSame(self::HELD, $this->tryLock($path));
        }
        finally
        {
            // ok = false: nothing was edited, only the lock is given back.
            $end($this->row(), array(), false);
        }

        $this->assertSame(0, $this->tryLock($path));
    }

    public static function lockPaths(): array
    {
        return array(
            '[ST] a persons write running' => array('persons_lock_path'),
            '[ST] a provenance write-back running' => array('provenance_lock_path'),
            );
    }

    /**
     * [ST] An edit started while the plugin's own writer holds the photo waits
     * for it: when the edit's response arrives, the writer has finished.
     */
    #[DataProvider('lockPaths')]
    public function testAnEditWaitsForAWriteAlreadyRunning(string $lockPath): void
    {
        $path = $lockPath($this->image['db_path']);
        $this->marker = sys_get_temp_dir() . '/photoedit-exclusion-' . bin2hex(random_bytes(4));

        exec(sprintf(
            'flock %s sh -c %s > /dev/null 2>&1 &',
            escapeshellarg($path),
            escapeshellarg('sleep ' . self::HOLD_SECONDS . '; touch ' . escapeshellarg($this->marker))
        ));
        $polls = 0;
        while ($this->tryLock($path) !== self::HELD and ++$polls < self::HOLD_POLLS)
        {
            usleep(100000);
        }
        $this->assertSame(self::HELD, $this->tryLock($path), 'anti-vacuity: the outside writer never took the lock');

        $client = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $client->login($username, $password);
        $res = $client->call('pwg.photoedit.apply', array(
            'image_id' => $this->image['id'],
            'turns' => 1,
            'pwg_token' => $client->token(),
            ));

        $this->assertFileExists($this->marker, 'the edit finished while the other write was still running');
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }
}
