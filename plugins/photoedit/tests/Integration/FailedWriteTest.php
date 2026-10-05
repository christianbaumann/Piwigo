<?php
use PHPUnit\Framework\TestCase;

/**
 * The write pipeline in-process, where its events can be listened to.
 *
 * The failure is forced inside the write, after photoedit_begin: the backups
 * directory is made read-only, so the backup cannot be made and nothing may be
 * written after it.
 */
final class FailedWriteTest extends TestCase
{
    private const READ_ONLY = 0555;
    private const WRITABLE = 0755;

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private array $events = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        PiwigoRuntime::loadPipeline();

        $this->image = $this->fixture->createMarkedImage();

        global $pwg_event_handlers;
        unset($pwg_event_handlers['photoedit_begin'], $pwg_event_handlers['photoedit_end']);
        add_event_handler('photoedit_begin', function ($image)
        {
            $this->events[] = array('begin', (int)$image['id']);
        });
        add_event_handler('photoedit_end', function ($image, $transform, $ok)
        {
            $this->events[] = array('end', (int)$image['id'], $transform['turns'], $ok);
        });
    }

    private const ORIGINALS_DIR = PIWIGO_ROOT . '_data/photoedit/originals';

    protected function tearDown(): void
    {
        chmod(self::ORIGINALS_DIR, self::WRITABLE);
        chmod(dirname($this->image['file']), self::WRITABLE);
        $this->fixture->destroyTestImages();
    }

    /** [ERR] A write that cannot replace the file leaves file and row as they were, and says so in photoedit_end. */
    public function testAFailedWriteLeavesTheOriginalAndEndsNotOk(): void
    {
        $id = (int)$this->image['id'];
        $before = $this->fixture->imageRow($id);

        if (!is_dir(self::ORIGINALS_DIR))
        {
            mkdir(self::ORIGINALS_DIR, self::WRITABLE, true);
        }
        chmod(self::ORIGINALS_DIR, self::READ_ONLY);
        clearstatcache();
        $this->assertFalse(is_writable(self::ORIGINALS_DIR),
            'anti-vacuity: the directory is still writable (running as root?)');

        $result = photoedit_apply($id, 1, null, false);

        $this->assertFalse($result['ok']);
        $this->assertSame('write_failed', $result['code']);
        $this->assertSame($this->image['md5'], md5_file($this->image['file']));
        $this->assertSame($before, $this->fixture->imageRow($id));
        $this->assertSame(array(array('begin', $id), array('end', $id, 1, false)), $this->events);
        $this->assertSame(array(), glob(PIWIGO_ROOT . '_data/photoedit/work/' . $id . '-*'), 'the work directory was left behind');

        chmod(self::ORIGINALS_DIR, self::WRITABLE);

        // The lock was given back: another handle takes it without waiting.
        $handle = fopen(PIWIGO_ROOT . '_data/photoedit/locks/' . sha1($this->image['db_path']) . '.lock', 'c');
        $this->assertNotFalse($handle, 'anti-vacuity: the edit took no lock at all');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB), 'the lock is still held');
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** [NEG] A photo whose folder is read-only is refused before anything starts: the rename needs the folder. */
    public function testAReadOnlyFolderIsRefusedUpFront(): void
    {
        chmod(dirname($this->image['file']), self::READ_ONLY);
        clearstatcache();
        $this->assertTrue(is_writable($this->image['file']), 'anti-vacuity: the file itself must stay writable');

        $result = photoedit_apply((int)$this->image['id'], 1, null, false);

        $this->assertFalse($result['ok']);
        $this->assertSame('write_failed', $result['code']);
        $this->assertSame(array(), $this->events);
        $this->assertSame($this->image['md5'], md5_file($this->image['file']));
    }

    /** [HAPPY] The control: the same call with a writable directory ends ok. */
    public function testASuccessfulWriteEndsOk(): void
    {
        $id = (int)$this->image['id'];

        $result = photoedit_apply($id, 1, null, false);

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertNotSame($this->image['md5'], md5_file($this->image['file']));
        $this->assertSame(array(array('begin', $id), array('end', $id, 1, true)), $this->events);
    }

    /** [HAPPY] A dry run fires neither event. */
    public function testADryRunFiresNoWriteEvents(): void
    {
        $result = photoedit_apply((int)$this->image['id'], 1, null, true);

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $this->events);
    }
}
