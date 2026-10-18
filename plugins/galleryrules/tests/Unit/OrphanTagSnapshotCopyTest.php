<?php
use PHPUnit\Framework\TestCase;

/**
 * galleryrules and provenance each carry a copy of OrphanTagSnapshot (plugins
 * share no code) and both write the same file. Diverging copies would disagree
 * about its format, so the two must stay byte-identical.
 */
final class OrphanTagSnapshotCopyTest extends TestCase
{
    private const MIN_BYTES = 500;

    /** [HAPPY] The provenance copy equals this plugin's. */
    public function testTheTwoCopiesAreIdentical(): void
    {
        $own = (string)file_get_contents(GALLERYRULES_PATH . 'tests/Support/OrphanTagSnapshot.php');
        $other = (string)file_get_contents(GALLERYRULES_PATH . '../provenance/tests/Support/OrphanTagSnapshot.php');

        $this->assertGreaterThan(self::MIN_BYTES, strlen($own));
        $this->assertSame($own, $other);
    }

    /** [ECP] Only names of the form <fixture prefix>-<6 hex> are fixtures. */
    public function testFixtureNamesAreRecognisedByPrefixAndSuffix(): void
    {
        $this->assertTrue(OrphanTagSnapshot::isFixtureName('plain-0a1b2c'));
        $this->assertTrue(OrphanTagSnapshot::isFixtureName('listed-ffffff'));
        $this->assertFalse(OrphanTagSnapshot::isFixtureName('Name ?'));
        $this->assertFalse(OrphanTagSnapshot::isFixtureName('plain-0a1b2'));
        $this->assertFalse(OrphanTagSnapshot::isFixtureName('plain-0a1b2cd'));
        $this->assertFalse(OrphanTagSnapshot::isFixtureName('my plain-0a1b2c'));
    }
}
