<?php
use PHPUnit\Framework\TestCase;

/**
 * provenance_lock_path() names the file the writer flocks before invoking
 * exiftool. It must never be the image itself: exiftool replaces the image by
 * rename, so a lock held on the old inode would exclude nothing from the second
 * writer onwards - the measured data-loss mode this whole discipline exists for.
 */
final class LockPathTest extends TestCase
{
    /** [HAPPY] Different images get different locks, or one lock serializes the gallery. */
    public function testDifferentImagesGetDifferentLocks(): void
    {
        $this->assertNotSame(
            provenance_lock_path('upload/2026/04/19/a.png'),
            provenance_lock_path('upload/2026/04/19/b.png')
        );
    }

    /** [HAPPY] The same path always yields the same lock - two processes must agree. */
    public function testSamePathIsDeterministic(): void
    {
        $this->assertSame(
            provenance_lock_path('upload/2026/04/19/a.png'),
            provenance_lock_path('upload/2026/04/19/a.png')
        );
    }

    /** [NEG] The lock is never the image file. */
    public function testLockPathIsNeverTheImagePath(): void
    {
        $image = 'upload/2026/04/19/a.png';

        $this->assertNotSame($image, provenance_lock_path($image));
        $this->assertStringNotContainsString(basename($image), provenance_lock_path($image));
    }

    /** [HAPPY] The lock lives under the plugin's working area inside _data. */
    public function testLockLivesInTheProvenanceWorkingArea(): void
    {
        $path = provenance_lock_path('upload/2026/04/19/a.png');

        $this->assertStringStartsWith(PROVENANCE_LOCK_DIR, $path);
        $this->assertStringEndsWith('.lock', $path);
        $this->assertStringContainsString('/_data/provenance/locks/', $path);
    }

    /**
     * [BVA] Paths differing only in a character a filesystem-safe name would
     * have to mangle still get distinct locks, because the name is a hash.
     */
    public function testPathsDifferingOnlyInSeparatorsGetDistinctLocks(): void
    {
        $this->assertNotSame(
            provenance_lock_path('upload/a/b.png'),
            provenance_lock_path('upload/a_b.png')
        );
    }

    /** [HAPPY] The lock file name is filesystem-safe whatever the image path holds. */
    public function testLockFileNameIsFilesystemSafe(): void
    {
        $name = basename(provenance_lock_path("upload/Łódź/Müller ä/a b.png"));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}\.lock$/', $name);
    }
}
