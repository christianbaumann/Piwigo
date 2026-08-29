<?php
use PHPUnit\Framework\TestCase;

/**
 * The writer stages one argfile per photo under a per-operation directory it
 * removes again in a finally. The path is a named helper so the writer and the
 * test that asserts the directory is gone cannot disagree about where it was.
 */
final class OperationDirTest extends TestCase
{
    /** [HAPPY] Two operations never share a directory. */
    public function testDistinctOperationsGetDistinctDirectories(): void
    {
        $this->assertNotSame(provenance_operation_dir('a1b2'), provenance_operation_dir('c3d4'));
    }

    /** [HAPPY] The same id maps to the same directory, so cleanup finds it. */
    public function testTheSameIdIsDeterministic(): void
    {
        $this->assertSame(provenance_operation_dir('a1b2'), provenance_operation_dir('a1b2'));
    }

    /** [NEG] The directory always sits under the plugin's own working area. */
    public function testTheDirectoryStaysUnderTheArgsDirectory(): void
    {
        $dir = provenance_operation_dir('a1b2');

        $this->assertStringStartsWith(PROVENANCE_ARGS_DIR, $dir);
        $this->assertStringEndsWith('/', $dir, 'callers concatenate a file name onto it');
    }

    /** [NEG] The working area is inside _data, never inside the plugin or the gallery. */
    public function testTheArgsAndLockDirectoriesAreUnderData(): void
    {
        foreach (array(PROVENANCE_ARGS_DIR, PROVENANCE_LOCK_DIR) as $dir)
        {
            $this->assertStringContainsString('/_data/provenance/', $dir);
        }
        $this->assertNotSame(PROVENANCE_ARGS_DIR, PROVENANCE_LOCK_DIR);
    }

    /** [HAPPY] A generated id is filesystem-safe, so no sanitising is needed downstream. */
    public function testAGeneratedIdIsFilesystemSafe(): void
    {
        $id = provenance_operation_id();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
        $this->assertNotSame($id, provenance_operation_id());
    }
}
