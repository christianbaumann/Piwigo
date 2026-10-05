<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The crop math: a frame drawn as fractions of the turned photo, in pixels of
 * the turned file; what a request turns and crops; and the centre of interest
 * cut down to the crop.
 *
 * The photo is 300x200 throughout, the size of the integration suite's marked
 * fixture. Every expected value is worked out by hand from the fractions.
 */
final class CropTest extends TestCase
{
    private const WIDTH = 300;
    private const HEIGHT = 200;

    private static function box(float $l, float $t, float $r, float $b): array
    {
        return array('l' => $l, 't' => $t, 'r' => $r, 'b' => $b);
    }

    public static function rects(): array
    {
        return array(
            '[HAPPY] the left half' => array(self::box(0, 0, 0.5, 1), array('x' => 0, 'y' => 0, 'w' => 150, 'h' => 200)),
            '[ECP] an inner frame' => array(self::box(0.1, 0.25, 0.9, 0.75), array('x' => 30, 'y' => 50, 'w' => 240, 'h' => 100)),
            // 0.1234 * 300 = 37.02 -> 37; 0.8766 * 300 = 262.98 -> 263
            '[ECP] each edge rounds to the nearest pixel' => array(self::box(0.1234, 0, 0.8766, 1), array('x' => 37, 'y' => 0, 'w' => 226, 'h' => 200)),
            '[BVA] the whole photo is no crop' => array(self::box(0, 0, 1, 1), null),
            // 0.0016 * 300 = 0.48 -> 0: rounds to the whole photo
            '[BVA] less than half a pixel in is no crop' => array(self::box(0.0016, 0, 1, 1), null),
            '[BVA] one pixel in is a crop' => array(self::box(1 / 300, 0, 1, 1), array('x' => 1, 'y' => 0, 'w' => 299, 'h' => 200)),
            '[BVA] the minimum width' => array(self::box(0, 0, PHOTOEDIT_MIN_CROP_PX / 300, 1), array('x' => 0, 'y' => 0, 'w' => PHOTOEDIT_MIN_CROP_PX, 'h' => 200)),
            '[BVA] the minimum height' => array(self::box(0, 0, 1, PHOTOEDIT_MIN_CROP_PX / 200), array('x' => 0, 'y' => 0, 'w' => 300, 'h' => PHOTOEDIT_MIN_CROP_PX)),
            );
    }

    #[DataProvider('rects')]
    public function testAFrameBecomesWholePixels(array $box, ?array $expected): void
    {
        $result = photoedit_crop_rect($box, self::WIDTH, self::HEIGHT);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame($expected, $result['rect']);
    }

    public static function tooSmall(): array
    {
        return array(
            '[BVA] one pixel wide' => array(self::box(0, 0, 1 / 300, 1)),
            '[BVA] one below the minimum width' => array(self::box(0, 0, (PHOTOEDIT_MIN_CROP_PX - 1) / 300, 1)),
            '[BVA] one below the minimum height' => array(self::box(0, 0, 1, (PHOTOEDIT_MIN_CROP_PX - 1) / 200)),
            // 0.5 * 300 = 150 and 0.501 * 300 = 150.3 -> 150: a frame that rounds to nothing
            '[BVA] a frame narrower than a pixel' => array(self::box(0.5, 0, 0.501, 1)),
            );
    }

    #[DataProvider('tooSmall')]
    public function testAFrameBelowTheMinimumIsRefused(array $box): void
    {
        $result = photoedit_crop_rect($box, self::WIDTH, self::HEIGHT);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString((string)PHOTOEDIT_MIN_CROP_PX, $result['error']);
    }

    /**
     * Decision table, turn x crop. The crop is always drawn on the turned
     * photo, so after one turn the 300x200 file is a 200x300 view.
     */
    public static function plans(): array
    {
        return array(
            '[DT] no turn, crop' => array(0, self::box(0, 0, 0.5, 1),
                array('x' => 0, 'y' => 0, 'w' => 150, 'h' => 200), 150, 200),
            '[DT] turn, no crop' => array(1, null, null, 200, 300),
            '[DT] turn, a whole-photo frame' => array(1, self::box(0, 0, 1, 1), null, 200, 300),
            '[DT] turn, crop of the turned view' => array(1, self::box(0, 0, 0.5, 1),
                array('x' => 0, 'y' => 0, 'w' => 100, 'h' => 300), 100, 300),
            '[DT] half turn, crop keeps the sides' => array(2, self::box(0, 0, 1, 0.5),
                array('x' => 0, 'y' => 0, 'w' => 300, 'h' => 100), 300, 100),
            );
    }

    #[DataProvider('plans')]
    public function testARequestTurnsFirstThenCrops(int $turns, ?array $box, ?array $rect, int $width, int $height): void
    {
        $result = photoedit_plan_edit(self::WIDTH, self::HEIGHT, $turns, $box);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame(array(
            'turns' => $turns,
            'crop_px' => $rect,
            'width_before' => self::WIDTH,
            'height_before' => self::HEIGHT,
            'width_after' => $width,
            'height_after' => $height,
            ), $result['transform']);
    }

    public static function nothingToDo(): array
    {
        return array(
            '[DT] no turn, no crop' => array(null),
            '[BVA] no turn, a whole-photo frame' => array(self::box(0, 0, 1, 1)),
            '[BVA] no turn, a frame that rounds to the whole photo' => array(self::box(0.0016, 0, 1, 1)),
            );
    }

    #[DataProvider('nothingToDo')]
    public function testAnEditThatChangesNothingIsRefused(?array $box): void
    {
        $result = photoedit_plan_edit(self::WIDTH, self::HEIGHT, 0, $box);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('nothing to do', $result['error']);
    }

    /** [NEG] A frame below the minimum refuses the whole edit, turn or not. */
    public function testATooSmallFrameRefusesTheEdit(): void
    {
        $result = photoedit_plan_edit(self::WIDTH, self::HEIGHT, 1, self::box(0, 0, 1 / 200, 1));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString((string)PHOTOEDIT_MIN_CROP_PX, $result['error']);
    }

    /** The right two thirds of the photo: x 100 to 300. */
    private const RIGHT_PART = array('x' => 100, 'y' => 0, 'w' => 200, 'h' => 200);

    public static function croppedBoxes(): array
    {
        return array(
            // x 150..180 of 100..300 -> 0.25..0.4; y unchanged by a full-height crop
            '[HAPPY] inside the crop it moves' => array(self::box(0.5, 0.25, 0.6, 0.5), self::box(0.25, 0.25, 0.4, 0.5)),
            // x 60..150 -> 100..150
            '[ECP] partly outside it is cut off' => array(self::box(0.2, 0, 0.5, 0.5), self::box(0, 0, 0.25, 0.5)),
            '[ECP] wholly outside it is gone' => array(self::box(0.1, 0, 0.3, 0.5), null),
            '[ECP] the whole photo becomes the whole crop' => array(self::box(0, 0, 1, 1), self::box(0, 0, 1, 1)),
            );
    }

    #[DataProvider('croppedBoxes')]
    public function testABoxIsCutToTheCrop(array $box, ?array $expected): void
    {
        $cropped = photoedit_crop_box($box, self::RIGHT_PART, self::WIDTH, self::HEIGHT);

        if ($expected === null)
        {
            $this->assertNull($cropped);
            return;
        }
        $this->assertNotNull($cropped);
        foreach ($expected as $edge => $value)
        {
            $this->assertEqualsWithDelta($value, $cropped[$edge], 1e-9, "edge $edge");
        }
    }

    /** [BVA] A box that only touches the crop - x 0..150 against a crop from 150 - has nothing inside it. */
    public function testABoxEndingOnTheCropEdgeIsOutside(): void
    {
        $rect = array('x' => 150, 'y' => 0, 'w' => 150, 'h' => 200);

        $this->assertNull(photoedit_crop_box(self::box(0, 0, 0.5, 1), $rect, self::WIDTH, self::HEIGHT));
        $this->assertNotNull(photoedit_crop_box(self::box(0, 0, 0.51, 1), $rect, self::WIDTH, self::HEIGHT),
            'a box reaching 3 px into the crop is inside');
    }

    private static function transform(int $turns, ?array $rect): array
    {
        return array('turns' => $turns, 'crop_px' => $rect, 'width_before' => 300, 'height_before' => 200);
    }

    public static function cois(): array
    {
        // 'fakj' is l 0.2, t 0, r 0.4, b 0.36: x 60..120, y 0..72 of 300x200
        return array(
            // x 60..120 of 0..150 -> 0.4 'k' .. 0.8 'u'
            '[HAPPY] inside the left half it moves' => array(0, array('x' => 0, 'y' => 0, 'w' => 150, 'h' => 200), 'kauj'),
            // x 90..120 of 90..300 -> 0 'a' .. 0.1429 (3.57 -> 'e')
            '[ECP] partly outside it is cut off' => array(0, array('x' => 90, 'y' => 0, 'w' => 210, 'h' => 200), 'aaej'),
            '[ECP] outside the right half it is dropped' => array(0, array('x' => 150, 'y' => 0, 'w' => 150, 'h' => 200), null),
            // turned: 'qfzk' of 200x300 is x 128..200, y 60..120; crop x 100..200, y 0..150
            // -> l 0.28 'h', t 0.4 'k', r 1 'z', b 0.8 'u'
            '[DT] turned, then cropped' => array(1, array('x' => 100, 'y' => 0, 'w' => 100, 'h' => 150), 'hkzu'),
            );
    }

    #[DataProvider('cois')]
    public function testTheCentreOfInterestIsCutToTheCrop(int $turns, array $rect, ?string $expected): void
    {
        $this->assertSame($expected, photoedit_transform_coi('fakj', self::transform($turns, $rect)));
    }
}
