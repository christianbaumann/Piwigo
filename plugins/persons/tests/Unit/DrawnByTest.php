<?php
use PHPUnit\Framework\TestCase;

/**
 * Who drew a region, and who may therefore delete it (decision 0065).
 *
 * The file carries no author, so the index records one only when a user adds a
 * region through this gallery, and a normal user may delete only those.
 */
final class DrawnByTest extends TestCase
{
    private const JANE = 7;
    private const JOHN = 8;
    private const DRAWER = 12;
    private const OTHER = 13;

    /** A 300x200 photo cropped to its left half, as photoedit hands the transform over. */
    private const LEFT_HALF = array(
        'raw_turns' => 0,
        'crop_px' => array('x' => 0, 'y' => 0, 'w' => 150, 'h' => 200),
        'width_before' => 300,
        'height_before' => 200,
    );

    /** [HAPPY] A region the caller just added, new to the index, is theirs. */
    public function testANewlyAddedRegionIsClaimed(): void
    {
        $rows = persons_claim_drawn_regions(
            array(),
            array($this->carried(null, self::JANE, 0.3)),
            array($this->drawn(self::JANE, 0.3))
        );

        $this->assertSame(array(self::DRAWER), array_column($rows, 'drawn_by'));
    }

    /** [ECP] A new region the caller did not add - found in the file - is nobody's. */
    public function testANewRegionTheCallerDidNotAddIsNotClaimed(): void
    {
        $rows = persons_claim_drawn_regions(
            array(),
            array(
                $this->carried(null, self::JANE, 0.3),
                $this->carried(null, self::JOHN, 0.7),
            ),
            array($this->drawn(self::JANE, 0.3))
        );

        $this->assertSame(array(self::DRAWER, null), array_column($rows, 'drawn_by'));
    }

    /** [ECP] Each written region brings its own drawer: a moved region keeps whoever drew it. */
    public function testEachRegionIsClaimedForItsOwnDrawer(): void
    {
        $rows = persons_claim_drawn_regions(
            array(),
            array(
                $this->carried(null, self::JANE, 0.3),
                $this->carried(null, self::JOHN, 0.7),
            ),
            array(
                $this->drawn(self::JOHN, 0.7, 'Face', self::OTHER),
                $this->drawn(self::JANE, 0.3),
            )
        );

        $this->assertSame(array(self::DRAWER, self::OTHER), array_column($rows, 'drawn_by'));
    }

    /** [NEG] A written region drawn by nobody leaves the row nobody's. */
    public function testAWrittenRegionDrawnByNobodyClaimsNothing(): void
    {
        $rows = persons_claim_drawn_regions(
            array(),
            array($this->carried(null, self::JANE, 0.3)),
            array($this->drawn(self::JANE, 0.3, 'Face', null))
        );

        $this->assertCount(1, $rows, 'anti-vacuity: the row itself must survive');
        $this->assertSame(array(null), array_column($rows, 'drawn_by'));
    }

    /** [HAPPY] A region read from the file gets the drawer of its index row. */
    public function testAttachGivesAFileRegionItsIndexedDrawer(): void
    {
        $regions = persons_attach_drawn_by(
            array($this->fileRegion('Jane', 0.3), $this->fileRegion('John', 0.7)),
            array($this->indexed('John', 0.7, (string)self::OTHER), $this->indexed('Jane', 0.3, null))
        );

        $this->assertSame(array(null, self::OTHER), array_column($regions, 'drawn_by'));
    }

    /** [ECP] Name, type and box must all agree, as for the carry. */
    #[PHPUnit\Framework\Attributes\DataProvider('attachMismatches')]
    public function testAttachMatchesOnlyTheSameRegion(string $key, $value): void
    {
        $region = $this->fileRegion('Jane', 0.3);
        $region[$key] = $value;

        $regions = persons_attach_drawn_by(array($region), array($this->indexed('Jane', 0.3, (string)self::DRAWER)));

        $this->assertSame(array(null), array_column($regions, 'drawn_by'));
    }

    public static function attachMismatches(): array
    {
        return array(
            'another name' => array('name', 'John'),
            'another type' => array('type', 'Pet'),
            'x beyond the tolerance' => array('x', 0.3 + PERSONS_REGION_MATCH_EPSILON * 2),
            'y beyond the tolerance' => array('y', 0.4 + PERSONS_REGION_MATCH_EPSILON * 2),
            'w beyond the tolerance' => array('w', 0.1 + PERSONS_REGION_MATCH_EPSILON * 2),
            'h beyond the tolerance' => array('h', 0.2 + PERSONS_REGION_MATCH_EPSILON * 2),
        );
    }

    /** [BVA] Within the tolerance it is the same region. */
    public function testAttachWithinTheTolerance(): void
    {
        $region = $this->fileRegion('Jane', 0.3 + PERSONS_REGION_MATCH_EPSILON / 2);

        $regions = persons_attach_drawn_by(array($region), array($this->indexed('Jane', 0.3, (string)self::DRAWER)));

        $this->assertSame(array(self::DRAWER), array_column($regions, 'drawn_by'));
    }

    /** [ECP] Two identical boxes take one index row each. */
    public function testAttachUsesEachIndexRowOnce(): void
    {
        $regions = persons_attach_drawn_by(
            array($this->fileRegion('Jane', 0.3), $this->fileRegion('Jane', 0.3)),
            array($this->indexed('Jane', 0.3, (string)self::DRAWER), $this->indexed('Jane', 0.3, (string)self::OTHER))
        );

        $this->assertSame(array(self::DRAWER, self::OTHER), array_column($regions, 'drawn_by'));
    }

    /** [NEG] Re-adding a box that already existed keeps it its drawer's, not the caller's. */
    public function testACarriedRegionIsNotClaimed(): void
    {
        $rows = persons_claim_drawn_regions(
            array($this->previous(41, self::JANE, 0.3, 'Face', self::OTHER)),
            array($this->carried(41, self::JANE, 0.3, self::OTHER)),
            array($this->drawn(self::JANE, 0.3))
        );

        $this->assertSame(array(self::OTHER), array_column($rows, 'drawn_by'));
    }

    /** [NEG] Re-adding nobody's box under another type does not hand it to the caller. */
    public function testARetypedExistingBoxIsNotClaimed(): void
    {
        $rows = persons_claim_drawn_regions(
            array($this->previous(41, self::JANE, 0.3, 'Face', null)),
            array($this->carried(null, self::JANE, 0.3, null, 'Pet')),
            array($this->drawn(self::JANE, 0.3, 'Pet'))
        );

        $this->assertSame(array(null), array_column($rows, 'drawn_by'));
    }

    /** [ECP] The same box under another person is a different region. */
    public function testAnAddedBoxClaimsOnlyItsOwnPerson(): void
    {
        $rows = persons_claim_drawn_regions(
            array(),
            array($this->carried(null, self::JOHN, 0.3)),
            array($this->drawn(self::JANE, 0.3))
        );

        $this->assertSame(array(null), array_column($rows, 'drawn_by'));
    }

    /** [BVA] The box read back from the file may differ from the one sent by the tolerance, no more. */
    public function testTheMatchToleranceBoundary(): void
    {
        $inside = $this->drawn(self::JANE, 0.3);
        $inside['area_x'] += PERSONS_REGION_MATCH_EPSILON / 2;
        $outside = $this->drawn(self::JANE, 0.3);
        $outside['area_x'] += PERSONS_REGION_MATCH_EPSILON * 2;

        $rows = array($this->carried(null, self::JANE, 0.3));

        $this->assertSame(array(self::DRAWER),
            array_column(persons_claim_drawn_regions(array(), $rows, array($inside)), 'drawn_by'));
        $this->assertSame(array(null),
            array_column(persons_claim_drawn_regions(array(), $rows, array($outside)), 'drawn_by'));
    }

    /**
     * [DT] Who may delete: administrator or not, by drawn_by.
     *
     * @param int|string|null $drawnBy
     */
    #[PHPUnit\Framework\Attributes\DataProvider('deleteDecisions')]
    public function testWhoMayDelete($drawnBy, bool $isAdmin, bool $expected): void
    {
        $this->assertSame($expected, persons_may_delete_region($drawnBy, self::DRAWER, $isAdmin));
    }

    public static function deleteDecisions(): array
    {
        return array(
            'normal, own region'                 => array((string)self::DRAWER, false, true),
            'normal, own region as int'          => array(self::DRAWER, false, true),
            "normal, another user's region"      => array((string)self::OTHER, false, false),
            "normal, nobody's region"            => array(null, false, false),
            'normal, an empty string'            => array('', false, false),
            'normal, zero'                       => array('0', false, false),
            "admin, another user's region"       => array((string)self::OTHER, true, true),
            "admin, nobody's region"             => array(null, true, true),
            'admin, own region'                  => array((string)self::DRAWER, true, true),
        );
    }

    /**
     * [DT] Which regions a crop to the left half refuses to cut away: only one
     * the crop removes and the user may not delete.
     *
     * @param int|null $drawnBy
     */
    #[PHPUnit\Framework\Attributes\DataProvider('cutDecisions')]
    public function testWhichCutRegionsRefuseACrop(float $x, $drawnBy, bool $isAdmin, array $expected): void
    {
        $region = $this->fileRegion('Jane', $x) + array('drawn_by' => $drawnBy);

        $this->assertSame($expected,
            persons_regions_cut_away_from_others(array($region), self::LEFT_HALF, self::DRAWER, $isAdmin));
    }

    public static function cutDecisions(): array
    {
        return array(
            "cut, nobody's, normal"         => array(0.8, null, false, array('Jane')),
            "cut, another user's, normal"   => array(0.8, self::OTHER, false, array('Jane')),
            'cut, own, normal'              => array(0.8, self::DRAWER, false, array()),
            "cut, nobody's, admin"          => array(0.8, null, true, array()),
            "kept, nobody's, normal"        => array(0.3, null, false, array()),
        );
    }

    /** [BVA] A centre just inside the crop keeps the region; just outside cuts it. */
    public function testTheCropEdgeDecidesWhatIsCut(): void
    {
        $justInside = $this->fileRegion('Inside', 0.49) + array('drawn_by' => null);
        $justOutside = $this->fileRegion('Outside', 0.51) + array('drawn_by' => null);

        $this->assertSame(array('Outside'), persons_regions_cut_away_from_others(
            array($justInside, $justOutside), self::LEFT_HALF, self::DRAWER, false));
    }

    /** [ECP] A turn cuts nothing away, so it refuses nothing. */
    public function testATurnCutsNobody(): void
    {
        $region = $this->fileRegion('Jane', 0.8) + array('drawn_by' => null);
        $turn = array('raw_turns' => 1, 'crop_px' => null, 'width_before' => 300, 'height_before' => 200);

        $this->assertSame(array(), persons_regions_cut_away_from_others(array($region), $turn, self::DRAWER, false));
    }

    /** [ECP] Two cut regions of one person name them once. */
    public function testACutPersonIsNamedOnce(): void
    {
        $regions = array(
            $this->fileRegion('Jane', 0.8) + array('drawn_by' => null),
            $this->fileRegion('Jane', 0.9) + array('drawn_by' => null),
        );

        $this->assertSame(array('Jane'), persons_regions_cut_away_from_others($regions, self::LEFT_HALF, self::DRAWER, false));
    }

    /** [BVA] A user whose id is 0 does not own the regions nobody drew. */
    public function testUserZeroOwnsNothing(): void
    {
        $this->assertFalse(persons_may_delete_region(null, 0, false));
        $this->assertFalse(persons_may_delete_region('0', 0, false));
    }

    /** A region as persons_parse_regioninfo() reads it from the file. */
    private function fileRegion(string $name, float $x): array
    {
        return array('name' => $name, 'type' => 'Face', 'source' => 'piwigo',
            'x' => $x, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2);
    }

    /** A persons_indexed_regions() row, as the database answers. */
    private function indexed(string $name, float $x, ?string $drawnBy): array
    {
        return array('name' => $name, 'region_type' => 'Face', 'drawn_by' => $drawnBy,
            'area_x' => (string)$x, 'area_y' => '0.4', 'area_w' => '0.1', 'area_h' => '0.2');
    }

    private function previous(int $id, int $personId, float $x, string $type, ?int $drawnBy): array
    {
        return array(
            'id'          => (string)$id,
            'person_id'   => (string)$personId,
            'region_type' => $type,
            'area_x'      => (string)$x,
            'area_y'      => '0.4',
            'area_w'      => '0.1',
            'area_h'      => '0.2',
            'drawn_by'    => $drawnBy === null ? null : (string)$drawnBy,
        );
    }

    /** A row as persons_carry_region_ids() hands it on. */
    private function carried(?int $id, int $personId, float $x, ?int $drawnBy = null, string $type = 'Face'): array
    {
        return array(
            'image_id'    => 5,
            'person_id'   => $personId,
            'area_x'      => $x,
            'area_y'      => 0.4,
            'area_w'      => 0.1,
            'area_h'      => 0.2,
            'region_type' => $type,
            'source'      => 'piwigo',
            'id'          => $id,
            'drawn_by'    => $drawnBy,
        );
    }

    /** A written region as persons_drawn_rows() shapes it, drawn by DRAWER unless said otherwise. */
    private function drawn(int $personId, float $x, string $type = 'Face', ?int $drawnBy = self::DRAWER): array
    {
        return array(
            'drawn_by'    => $drawnBy,
            'person_id'   => $personId,
            'region_type' => $type,
            'area_x'      => $x,
            'area_y'      => 0.4,
            'area_w'      => 0.1,
            'area_h'      => 0.2,
        );
    }
}
