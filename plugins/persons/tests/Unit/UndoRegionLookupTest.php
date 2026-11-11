<?php
use PHPUnit\Framework\TestCase;

/**
 * Which index row an undo of a region entry acts on: the person's name and the
 * box within PERSONS_REGION_MATCH_EPSILON, nothing else.
 */
final class UndoRegionLookupTest extends TestCase
{
    private const BOX = array(0.25, 0.5, 0.2, 0.2);

    private function row(string $name, float $dx = 0.0, $drawnBy = '7'): array
    {
        return array('name' => $name, 'area_x' => (string)(self::BOX[0] + $dx), 'area_y' => '0.5',
            'area_w' => '0.2', 'area_h' => '0.2', 'drawn_by' => $drawnBy);
    }

    private function region(string $name = 'Anna', array $box = self::BOX): array
    {
        return array('name' => $name, 'box' => $box, 'drawn_by' => 7);
    }

    /** [HAPPY] The row with the same name and box is found, with its drawer. */
    public function testTheMatchingRowIsFound(): void
    {
        $found = persons_find_region_row(array($this->row('Bert'), $this->row('Anna')), $this->region());

        $this->assertSame('Anna', $found['name']);
        $this->assertSame('7', $found['drawn_by']);
    }

    /** [BVA] A coordinate off by half the tolerance is the same box, by twice it a moved one. */
    public function testTheToleranceBoundary(): void
    {
        $this->assertNotNull(persons_find_region_row(array($this->row('Anna', PERSONS_REGION_MATCH_EPSILON / 2)), $this->region()));
        $this->assertNull(persons_find_region_row(array($this->row('Anna', PERSONS_REGION_MATCH_EPSILON * 2)), $this->region()));
    }

    /** [NEG] Another name, or an empty index, finds nothing. */
    public function testAnotherNameOrNoRowsFindsNothing(): void
    {
        $this->assertNull(persons_find_region_row(array($this->row('Anna')), $this->region('Berta')));
        $this->assertNull(persons_find_region_row(array(), $this->region()));
    }

    /** [ECP] A box of the wrong length finds nothing instead of matching on a name alone. */
    public function testAMalformedBoxFindsNothing(): void
    {
        $this->assertNull(persons_find_region_row(array($this->row('Anna')), $this->region('Anna', array(0.25))));
    }
}
