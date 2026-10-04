<?php
use PHPUnit\Framework\TestCase;

/**
 * Which index rows keep their id across a reindex.
 *
 * Every write re-reads the file and replaces the photo's rows. A page that is
 * already open holds the ids it was rendered with, so a region the write did
 * not touch has to come back under the same id - or the next delete from that
 * page names a row that no longer exists.
 */
final class RegionIdCarryTest extends TestCase
{
    private const JANE = 7;
    private const JOHN = 8;

    /** [HAPPY] An unchanged region keeps its id. */
    public function testAnUnchangedRegionKeepsItsId(): void
    {
        $rows = persons_carry_region_ids(
            array($this->previous(41, self::JANE, 0.3, 0.4, 0.1, 0.2)),
            array($this->row(self::JANE, 0.3, 0.4, 0.1, 0.2))
        );

        $this->assertSame(array(41), array_column($rows, 'id'));
    }

    /** [ECP] A region the previous index did not hold gets no id, so the database assigns one. */
    public function testANewRegionGetsNoId(): void
    {
        $rows = persons_carry_region_ids(
            array($this->previous(41, self::JANE, 0.3, 0.4, 0.1, 0.2)),
            array(
                $this->row(self::JANE, 0.3, 0.4, 0.1, 0.2),
                $this->row(self::JOHN, 0.7, 0.4, 0.1, 0.2),
            )
        );

        $this->assertSame(array(41, null), array_column($rows, 'id'));
    }

    /** [ECP] A first index has nothing to carry. */
    public function testAFirstIndexAssignsNoIds(): void
    {
        $rows = persons_carry_region_ids(
            array(),
            array($this->row(self::JANE, 0.3, 0.4, 0.1, 0.2))
        );

        $this->assertCount(1, $rows, 'anti-vacuity: the row itself must survive');
        $this->assertSame(array(null), array_column($rows, 'id'));
    }

    /** [ECP] The same box under another person is another region. */
    public function testTheSameBoxForAnotherPersonGetsNoId(): void
    {
        $rows = persons_carry_region_ids(
            array($this->previous(41, self::JANE, 0.3, 0.4, 0.1, 0.2)),
            array($this->row(self::JOHN, 0.3, 0.4, 0.1, 0.2))
        );

        $this->assertSame(array(null), array_column($rows, 'id'));
    }

    /** [ECP] The same box of another type is another region. */
    public function testTheSameBoxOfAnotherTypeGetsNoId(): void
    {
        $rows = persons_carry_region_ids(
            array($this->previous(41, self::JANE, 0.3, 0.4, 0.1, 0.2, 'Pet')),
            array($this->row(self::JANE, 0.3, 0.4, 0.1, 0.2))
        );

        $this->assertSame(array(null), array_column($rows, 'id'));
    }

    /** [BVA] A coordinate within PERSONS_REGION_MATCH_EPSILON is the same box. */
    #[PHPUnit\Framework\Attributes\DataProvider('coordinates')]
    public function testACoordinateJustInsideTheToleranceKeepsTheId(string $column): void
    {
        $rows = persons_carry_region_ids(
            array($this->previous(41, self::JANE, 0.3, 0.4, 0.1, 0.2)),
            array($this->shifted($column, PERSONS_REGION_MATCH_EPSILON / 2))
        );

        $this->assertSame(array(41), array_column($rows, 'id'));
    }

    /** [BVA] A coordinate just beyond PERSONS_REGION_MATCH_EPSILON is a moved box. */
    #[PHPUnit\Framework\Attributes\DataProvider('coordinates')]
    public function testACoordinateJustOutsideTheToleranceGetsNoId(string $column): void
    {
        $rows = persons_carry_region_ids(
            array($this->previous(41, self::JANE, 0.3, 0.4, 0.1, 0.2)),
            array($this->shifted($column, PERSONS_REGION_MATCH_EPSILON * 2))
        );

        $this->assertSame(array(null), array_column($rows, 'id'));
    }

    /** Every coordinate is compared, so every one gets its own boundary pair. */
    public static function coordinates(): array
    {
        return array(
            'x' => array('area_x'),
            'y' => array('area_y'),
            'w' => array('area_w'),
            'h' => array('area_h'),
        );
    }

    /** [ECP] Two identical boxes take one id each, never the same one twice. */
    public function testEachPreviousIdIsUsedOnce(): void
    {
        $rows = persons_carry_region_ids(
            array(
                $this->previous(41, self::JANE, 0.3, 0.4, 0.1, 0.2),
                $this->previous(42, self::JANE, 0.3, 0.4, 0.1, 0.2),
            ),
            array(
                $this->row(self::JANE, 0.3, 0.4, 0.1, 0.2),
                $this->row(self::JANE, 0.3, 0.4, 0.1, 0.2),
                $this->row(self::JANE, 0.3, 0.4, 0.1, 0.2),
            )
        );

        $this->assertSame(array(41, 42, null), array_column($rows, 'id'));
    }

    /** [NEG] A removed region's id is not handed to anybody else. */
    public function testARemovedRegionsIdIsNotReused(): void
    {
        $rows = persons_carry_region_ids(
            array(
                $this->previous(41, self::JANE, 0.3, 0.4, 0.1, 0.2),
                $this->previous(42, self::JOHN, 0.7, 0.4, 0.1, 0.2),
            ),
            array($this->row(self::JOHN, 0.7, 0.4, 0.1, 0.2))
        );

        $this->assertSame(array(42), array_column($rows, 'id'));
    }

    /** [HAPPY] Everything else in a row passes through untouched. */
    public function testTheRowsAreOtherwiseUnchanged(): void
    {
        $row = $this->row(self::JANE, 0.3, 0.4, 0.1, 0.2);

        $rows = persons_carry_region_ids(array(), array($row));

        $this->assertSame($row + array('id' => null), $rows[0]);
    }

    private function previous(int $id, int $personId, float $x, float $y, float $w, float $h, string $type = 'Face'): array
    {
        // As the database answers: every column a string.
        return array(
            'id'          => (string)$id,
            'person_id'   => (string)$personId,
            'region_type' => $type,
            'area_x'      => (string)$x,
            'area_y'      => (string)$y,
            'area_w'      => (string)$w,
            'area_h'      => (string)$h,
        );
    }

    /** JANE's box from previous(), with one coordinate moved by $delta. */
    private function shifted(string $column, float $delta): array
    {
        $row = $this->row(self::JANE, 0.3, 0.4, 0.1, 0.2);
        $row[$column] += $delta;

        return $row;
    }

    private function row(int $personId, float $x, float $y, float $w, float $h): array
    {
        return array(
            'image_id'    => 5,
            'person_id'   => $personId,
            'area_x'      => $x,
            'area_y'      => $y,
            'area_w'      => $w,
            'area_h'      => $h,
            'region_type' => 'Face',
            'source'      => 'piwigo',
        );
    }
}
