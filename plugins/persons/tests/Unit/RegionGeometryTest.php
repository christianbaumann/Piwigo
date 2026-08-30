<?php
use PHPUnit\Framework\TestCase;

/**
 * The coordinate contract, which is the one piece of this plugin a silent
 * regression would be worst in: a box that lands a few percent off is wrong in
 * a way nobody notices until they open the file somewhere else.
 *
 * Pure math, so everything here is unit-level and no fixture is needed.
 */
final class RegionGeometryTest extends TestCase
{
    /** Coordinates are doubles; comparisons carry a tolerance, not an equality. */
    private const EPSILON = 1e-9;

    /** [HAPPY] A centred box converts to the corner MWG readers expect. */
    public function testCenterToCornerConvertsACenteredBox(): void
    {
        $corner = persons_center_to_corner(0.5, 0.5, 0.2, 0.2);

        $this->assertEqualsWithDelta(0.4, $corner['left'], self::EPSILON);
        $this->assertEqualsWithDelta(0.4, $corner['top'], self::EPSILON);
        $this->assertEqualsWithDelta(0.2, $corner['w'], self::EPSILON);
        $this->assertEqualsWithDelta(0.2, $corner['h'], self::EPSILON);
    }

    /** [HAPPY] The two conversions are inverses over a table of real boxes. */
    public function testCornerToCenterIsTheInverseOfCenterToCorner(): void
    {
        $boxes = array(
            array(0.5, 0.5, 0.2, 0.2),
            array(0.1, 0.9, 0.05, 0.3),
            array(0.25, 0.75, 1.0, 0.02),
            array(0.0, 1.0, 0.4, 0.4),
        );
        $this->assertGreaterThan(0, count($boxes), 'anti-vacuity: an empty table would pass trivially');

        foreach ($boxes as $box)
        {
            list($x, $y, $w, $h) = $box;
            $corner = persons_center_to_corner($x, $y, $w, $h);
            $back = persons_corner_to_center($corner['left'], $corner['top'], $corner['w'], $corner['h']);

            $this->assertEqualsWithDelta($x, $back['x'], self::EPSILON);
            $this->assertEqualsWithDelta($y, $back['y'], self::EPSILON);
            $this->assertEqualsWithDelta($w, $back['w'], self::EPSILON);
            $this->assertEqualsWithDelta($h, $back['h'], self::EPSILON);
        }
    }

    /** [BVA] A centre on the left edge is inside the image, so it is kept. */
    public function testACenterAtExactlyZeroIsKept(): void
    {
        $clipped = persons_clip_region(array('x' => 0.0, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2));

        $this->assertNotNull($clipped);
    }

    /** [BVA] So is one on the right edge. */
    public function testACenterAtExactlyOneIsKept(): void
    {
        $clipped = persons_clip_region(array('x' => 1.0, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2));

        $this->assertNotNull($clipped);
    }

    /** [BVA] [NEG] A centre off the left of the image describes nothing in it. */
    public function testACenterBelowZeroIsDropped(): void
    {
        $this->assertNull(persons_clip_region(array('x' => -0.01, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2)));
    }

    /** [BVA] [NEG] Nor off the right. */
    public function testACenterAboveOneIsDropped(): void
    {
        $this->assertNull(persons_clip_region(array('x' => 0.5, 'y' => 1.01, 'w' => 0.2, 'h' => 0.2)));
    }

    /**
     * [BVA] A box that fits inside the frame comes back with exactly the
     * coordinates it went in with - not merely close ones.
     *
     * assertSame, not assertEqualsWithDelta, and that is the point. Clipping a
     * region that needs no clipping used to round-trip it through the corner
     * form and back, and 0.5 +/- 0.1/2 does not come out of that arithmetic as
     * 0.1 again: the merge wrote 0.10000000000000003 into every file, for every
     * box anybody drew. Found 2026-08-30 by the ImageMagick reader in
     * WriteRegionsTest, which reads the packet as text and so cannot be talked
     * out of it by a tolerance.
     */
    public function testARegionInsideTheFrameIsReturnedByteForByteUnchanged(): void
    {
        $clipped = persons_clip_region(array('x' => 0.5, 'y' => 0.4, 'w' => 0.1, 'h' => 0.2));

        $this->assertNotNull($clipped);
        $this->assertSame(0.5, $clipped['x']);
        $this->assertSame(0.4, $clipped['y']);
        $this->assertSame(0.1, $clipped['w']);
        $this->assertSame(0.2, $clipped['h']);
    }

    /** [BVA] A box touching an edge exactly is still not perturbed. */
    public function testABoxFlushWithAnEdgeIsReturnedUnchanged(): void
    {
        $clipped = persons_clip_region(array('x' => 0.05, 'y' => 0.5, 'w' => 0.1, 'h' => 0.2));

        $this->assertNotNull($clipped);
        $this->assertSame(0.05, $clipped['x']);
        $this->assertSame(0.1, $clipped['w']);
    }

    /**
     * [BVA] A box whose centre is inside but which overruns an edge is clipped,
     * per MWG - the subject is in the picture, the recorded box was just larger
     * than the frame.
     */
    public function testABoxOverrunningTheLeftEdgeIsClippedNotDropped(): void
    {
        $clipped = persons_clip_region(array('x' => 0.1, 'y' => 0.5, 'w' => 0.4, 'h' => 0.2));

        $this->assertNotNull($clipped);
        $corner = persons_center_to_corner($clipped['x'], $clipped['y'], $clipped['w'], $clipped['h']);
        $this->assertEqualsWithDelta(0.0, $corner['left'], self::EPSILON);
        $this->assertEqualsWithDelta(0.3, $clipped['w'], self::EPSILON, 'the overrunning tenth is cut, the rest kept');
    }

    /** [BVA] Two overrunning edges are both cut, not just the first found. */
    public function testABoxOverrunningTwoEdgesIsClippedOnBoth(): void
    {
        $clipped = persons_clip_region(array('x' => 0.5, 'y' => 0.5, 'w' => 2.0, 'h' => 2.0));

        $this->assertNotNull($clipped);
        $this->assertEqualsWithDelta(1.0, $clipped['w'], self::EPSILON);
        $this->assertEqualsWithDelta(1.0, $clipped['h'], self::EPSILON);
        $this->assertEqualsWithDelta(0.5, $clipped['x'], self::EPSILON);
        $this->assertEqualsWithDelta(0.5, $clipped['y'], self::EPSILON);
    }

    /** [BVA] [NEG] A box with no area is not a region. */
    public function testAZeroWidthBoxIsRejected(): void
    {
        $this->assertNull(persons_clip_region(array('x' => 0.5, 'y' => 0.5, 'w' => 0.0, 'h' => 0.2)));
    }

    /** [BVA] A width of exactly 1 is the whole image and is a legal region. */
    public function testAWidthOfExactlyOneCoversTheWholeImage(): void
    {
        $clipped = persons_clip_region(array('x' => 0.5, 'y' => 0.5, 'w' => 1.0, 'h' => 1.0));

        $this->assertNotNull($clipped);
        $this->assertEqualsWithDelta(1.0, $clipped['w'], self::EPSILON);
    }

    /** [BVA] The minimum is inclusive: a box at exactly the floor is accepted. */
    public function testABoxAtExactlyTheMinimumFractionIsAccepted(): void
    {
        $this->assertTrue(persons_minimum_box_ok(PERSONS_MIN_BOX_FRACTION, PERSONS_MIN_BOX_FRACTION));
    }

    /** [BVA] [NEG] One step under it is not. */
    public function testABoxOneEpsilonBelowTheMinimumIsRejected(): void
    {
        $this->assertFalse(persons_minimum_box_ok(
            PERSONS_MIN_BOX_FRACTION - self::EPSILON,
            PERSONS_MIN_BOX_FRACTION
        ));
    }

    /** [NEG] Both axes must clear the floor, not either one. */
    public function testABoxTooSmallOnOneAxisOnlyIsRejected(): void
    {
        $this->assertFalse(persons_minimum_box_ok(0.5, PERSONS_MIN_BOX_FRACTION / 2));
        $this->assertFalse(persons_minimum_box_ok(PERSONS_MIN_BOX_FRACTION / 2, 0.5));
    }

    /** [ECP] Rotation code 0 is the identity. */
    public function testRotationCodeZeroLeavesTheRegionUnchanged(): void
    {
        $region = array('x' => 0.25, 'y' => 0.75, 'w' => 0.2, 'h' => 0.4);

        $this->assertEquals($region, persons_rotate_region($region, 0));
    }

    /** [ECP] One quarter turn clockwise swaps the axes. */
    public function testRotationCodeOneSwapsTheAxes(): void
    {
        $rotated = persons_rotate_region(array('x' => 0.25, 'y' => 0.75, 'w' => 0.2, 'h' => 0.4), 1);

        $this->assertEqualsWithDelta(0.25, $rotated['x'], self::EPSILON);
        $this->assertEqualsWithDelta(0.25, $rotated['y'], self::EPSILON);
        $this->assertEqualsWithDelta(0.4, $rotated['w'], self::EPSILON);
        $this->assertEqualsWithDelta(0.2, $rotated['h'], self::EPSILON);
    }

    /** [ECP] A half turn mirrors both axes and leaves the box shape alone. */
    public function testRotationCodeTwoMirrorsBothAxes(): void
    {
        $rotated = persons_rotate_region(array('x' => 0.25, 'y' => 0.75, 'w' => 0.2, 'h' => 0.4), 2);

        $this->assertEqualsWithDelta(0.75, $rotated['x'], self::EPSILON);
        $this->assertEqualsWithDelta(0.25, $rotated['y'], self::EPSILON);
        $this->assertEqualsWithDelta(0.2, $rotated['w'], self::EPSILON);
        $this->assertEqualsWithDelta(0.4, $rotated['h'], self::EPSILON);
    }

    /** [ECP] Three quarter turns swap the axes the other way. */
    public function testRotationCodeThreeSwapsTheAxesTheOtherWay(): void
    {
        $rotated = persons_rotate_region(array('x' => 0.25, 'y' => 0.75, 'w' => 0.2, 'h' => 0.4), 3);

        $this->assertEqualsWithDelta(0.75, $rotated['x'], self::EPSILON);
        $this->assertEqualsWithDelta(0.75, $rotated['y'], self::EPSILON);
        $this->assertEqualsWithDelta(0.4, $rotated['w'], self::EPSILON);
        $this->assertEqualsWithDelta(0.2, $rotated['h'], self::EPSILON);
    }

    /** [BVA] Code 4 is a full turn, taken modulo 4 the way core reads it. */
    public function testRotationCodeFourIsTreatedAsZero(): void
    {
        $region = array('x' => 0.25, 'y' => 0.75, 'w' => 0.2, 'h' => 0.4);

        $this->assertEquals(
            persons_rotate_region($region, 0),
            persons_rotate_region($region, 4)
        );
    }

    /**
     * [NEG] Core never writes a negative code, so there is no direction to
     * infer for one; it is treated as no rotation rather than guessed at.
     */
    public function testANegativeRotationCodeIsTreatedAsZero(): void
    {
        $region = array('x' => 0.25, 'y' => 0.75, 'w' => 0.2, 'h' => 0.4);

        $this->assertEquals($region, persons_rotate_region($region, -1));
    }

    /** [ECP] Four quarter turns are the identity - the property, not one case. */
    public function testFourSuccessiveRotationsReturnTheOriginalRegion(): void
    {
        $region = array('x' => 0.25, 'y' => 0.75, 'w' => 0.2, 'h' => 0.4);

        $turned = $region;
        for ($i = 0; $i < 4; $i++)
        {
            $turned = persons_rotate_region($turned, 1);
        }

        foreach (array('x', 'y', 'w', 'h') as $key)
        {
            $this->assertEqualsWithDelta($region[$key], $turned[$key], self::EPSILON, "axis $key drifted");
        }
    }

    /** [ECP] Keys the region carries beyond the geometry survive a rotation. */
    public function testRotationPreservesTheRegionsOtherFields(): void
    {
        $rotated = persons_rotate_region(
            array('x' => 0.5, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2, 'name' => 'Jane Doe'),
            1
        );

        $this->assertSame('Jane Doe', $rotated['name']);
    }

    /** [HAPPY] Same dimensions, nothing to flag. */
    public function testIdenticalDimensionsAreNotStale(): void
    {
        $this->assertFalse(persons_region_is_stale(4000, 3000, 4000, 3000));
    }

    /** [ECP] A proportional downscale leaves every region correct. */
    public function testAProportionalResizeIsNotStale(): void
    {
        $this->assertFalse(persons_region_is_stale(4000, 3000, 2000, 1500));
    }

    /** [ECP] A crop moves every region and must be flagged. */
    public function testACropIsStale(): void
    {
        $this->assertTrue(persons_region_is_stale(4000, 3000, 4000, 2000));
    }

    /**
     * [BVA] A ratio difference just inside the tolerance is not stale.
     *
     * The pair below straddles the tolerance rather than sitting on it: no
     * integer pixel dimensions produce a ratio difference exactly equal to the
     * tolerance as a double (searched to 200,000 on 2026-08-30), because
     * 1.0 + 0.02 minus 1.0 is 0.020000000000000018, not the double 0.02. The
     * exact boundary is therefore unreachable from any real image, and a
     * `>` -> `>=` mutant on the comparison is not killable by any input the
     * function can actually receive.
     */
    public function testARatioDifferenceJustInsideToleranceIsNotStale(): void
    {
        $height = 1000 / (1 + PERSONS_STALE_RATIO_TOLERANCE * 0.9);

        $this->assertFalse(persons_region_is_stale(1000, 1000, 1000, $height));
    }

    /** [BVA] And one just outside it is. */
    public function testARatioDifferenceJustOutsideToleranceIsStale(): void
    {
        $height = 1000 / (1 + PERSONS_STALE_RATIO_TOLERANCE * 1.1);

        $this->assertTrue(persons_region_is_stale(1000, 1000, 1000, $height));
    }

    /**
     * [ERR] [NEG] digiKam omits AppliedToDimensions entirely (KDE bug 429219).
     * Treating absent as wrong would flag every file it ever wrote, so unknown
     * is unknown - not stale. No requirement says this; it records the choice.
     */
    public function testUnknownAppliedDimensionsAreNotReportedStale(): void
    {
        $this->assertFalse(persons_region_is_stale(null, null, 4000, 3000));
    }

    /** [BVA] [NEG] A zero dimension must not reach the division. */
    public function testZeroAppliedDimensionsDoNotDivideByZero(): void
    {
        $this->assertFalse(persons_region_is_stale(0, 0, 4000, 3000));
        $this->assertFalse(persons_region_is_stale(4000, 3000, 0, 0));
    }

    /** [HAPPY] Nothing changed, nothing to correct. */
    public function testAnUnchangedRotationYieldsNoDelta(): void
    {
        $this->assertSame(0, persons_rotation_delta(0, 0, 4000, 3000, 4000, 3000));
    }

    /**
     * [DT] images.rotation changed but the file's dimensions did not: only the
     * display transform moved. MWG stores regions prior to Exif Orientation, so
     * the stored regions are still right and rewriting them would break them.
     */
    public function testADisplayOnlyRotationChangeYieldsNoDelta(): void
    {
        $this->assertSame(0, persons_rotation_delta(0, 1, 4000, 3000, 4000, 3000));
    }

    /** [DT] The file's dimensions are transposed: something rotated the bytes. */
    public function testAPhysicalRotationYieldsTheDelta(): void
    {
        $this->assertSame(1, persons_rotation_delta(0, 1, 4000, 3000, 3000, 4000));
    }

    /** [DT] And the other way round gives the opposite quarter turn. */
    public function testAPhysicalRotationTheOtherWayYieldsTheOppositeDelta(): void
    {
        $this->assertSame(3, persons_rotation_delta(1, 0, 4000, 3000, 3000, 4000));
    }

    /**
     * [BVA] [NEG] A square image transposes onto itself, so a physical rotation
     * of one cannot be told from none. Reporting a delta would be a guess, and
     * a wrong guess rewrites correct data.
     */
    public function testASquareImageIsNeverReportedPhysicallyRotated(): void
    {
        $this->assertSame(0, persons_rotation_delta(0, 1, 3000, 3000, 3000, 3000));
    }

    /** [DT] Dimensions that differ without being a transpose are a crop. */
    public function testACropYieldsNoDelta(): void
    {
        $this->assertSame(0, persons_rotation_delta(0, 1, 4000, 3000, 4000, 2000));
    }

    /** [NEG] Without AppliedToDimensions there is nothing to compare against. */
    public function testUnknownAppliedDimensionsYieldNoDelta(): void
    {
        $this->assertSame(0, persons_rotation_delta(0, 1, null, null, 3000, 4000));
    }

    // ── fraction to CSS percentage ────────────────────────────────────────

    /** [HAPPY] A fraction becomes a percentage with a unit CSS accepts. */
    public function testAFractionBecomesACssPercentage(): void
    {
        $this->assertSame('25.0000%', persons_percent(0.25));
    }

    /** [BVA] Zero and one are the ends of the range and keep the unit. */
    public function testZeroAndOneKeepTheUnit(): void
    {
        $this->assertSame('0.0000%', persons_percent(0));
        $this->assertSame('100.0000%', persons_percent(1));
    }

    /**
     * [ERR] The float artefact this helper exists for.
     *
     * 0.4 - 0.2 / 2 is 0.30000000000000004 in binary floating point, and PHP's
     * own float-to-string would put all of it into the style attribute. Fixed
     * precision is what keeps the markup readable and the page source stable
     * enough to assert on.
     */
    public function testAFloatArtefactIsRoundedAway(): void
    {
        $corner = persons_center_to_corner(0.4, 0.5, 0.2, 0.2);

        $this->assertSame('30.0000%', persons_percent($corner['left']));
    }

    /**
     * [BVA] Four decimals of a percent is a hundredth of a pixel on a 1000px
     * photo - two boxes a pixel apart still round to different percentages.
     */
    public function testTwoFractionsOnePixelApartDoNotRoundTogether(): void
    {
        $this->assertNotSame(persons_percent(0.5), persons_percent(0.5 + 1 / 1000));
    }
}
