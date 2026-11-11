<?php
use PHPUnit\Framework\TestCase;

/**
 * The name and box an announced region carries when the index row is read back.
 */
final class StoredRegionFallbackTest extends TestCase
{
    private const REQUESTED_BOX = array(0.1, 0.2, 0.3, 0.4);

    /** [HAPPY] The stored row wins: the box was clipped to the photo. */
    public function testTheIndexRowIsPreferred(): void
    {
        $row = array('name' => 'Anna', 'area_x' => '0.1', 'area_y' => '0.2', 'area_w' => '0.25', 'area_h' => '0.4');

        $stored = persons_stored_region_from_row($row, 'anna', self::REQUESTED_BOX);

        $this->assertSame('Anna', $stored['name']);
        $this->assertSame(array(0.1, 0.2, 0.25, 0.4), $stored['box']);
    }

    /** [NEG] No index row (query returned false or null): the request's values, not zeros. */
    public function testWithoutARowTheRequestValuesAreUsed(): void
    {
        foreach (array(false, null) as $row)
        {
            $stored = persons_stored_region_from_row($row, 'Anna', self::REQUESTED_BOX);

            $this->assertSame('Anna', $stored['name']);
            $this->assertSame(self::REQUESTED_BOX, $stored['box']);
        }
    }
}
