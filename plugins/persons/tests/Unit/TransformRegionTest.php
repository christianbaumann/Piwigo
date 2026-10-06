<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * persons_transform_region(): a region follows plugins/photoedit's turn and
 * crop of its file.
 *
 * The file is 300x200 throughout. Expected values are worked out by hand in
 * pixels: a region's centre and size times the file's sides, moved, divided by
 * the new sides.
 */
final class TransformRegionTest extends TestCase
{
    private const DELTA = 1e-9;

    private static function region(float $x, float $y, float $w, float $h, string $name = 'Anna'): array
    {
        return array('name' => $name, 'type' => 'Face', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h);
    }

    /** @param int $turns quarter turns clockwise of the raw file, as photoedit hands them over */
    private static function transform(int $turns, ?array $crop): array
    {
        return array(
            'raw_turns' => $turns,
            'crop_px' => $crop,
            'width_before' => 300,
            'height_before' => 200,
            );
    }

    private static function rect(int $x, int $y, int $w, int $h): array
    {
        return array('x' => $x, 'y' => $y, 'w' => $w, 'h' => $h);
    }

    public static function moved(): array
    {
        $face = self::region(0.25, 0.5, 0.2, 0.3);
        return array(
            '[HAPPY] a quarter turn moves it, as persons_rotate_region() does' => array(
                $face, self::transform(1, null), array(0.5, 0.25, 0.3, 0.2)),
            '[ECP] a half turn of the raw file' => array(
                $face, self::transform(2, null), array(0.75, 0.5, 0.2, 0.3)),
            // x 75 px of 150 -> 0.5; w 60 px of 150 -> 0.4
            '[HAPPY] inside the left half it is moved and scaled' => array(
                $face, self::transform(0, self::rect(0, 0, 150, 200)), array(0.5, 0.5, 0.4, 0.3)),
            // x 135 of 150 = 0.9, w 0.4: 0.7..1.1 clipped to 0.7..1
            '[ECP] partly outside it is clipped' => array(
                self::region(0.45, 0.5, 0.2, 0.3), self::transform(0, self::rect(0, 0, 150, 200)), array(0.85, 0.5, 0.3, 0.3)),
            // y 100 - 50 = 50 of 100 -> 0.5; h 60 of 100 -> 0.6
            '[ECP] a crop from the top shifts it vertically' => array(
                self::region(0.5, 0.5, 0.2, 0.3), self::transform(0, self::rect(0, 50, 300, 100)), array(0.5, 0.5, 0.2, 0.6)),
            // a half turn keeps 300x200: centre (225, 100), 60x60 px; crop from x 150, 150x200
            '[DT] a half turn, then cropped' => array(
                $face, self::transform(2, self::rect(150, 0, 150, 200)), array(0.5, 0.5, 0.4, 0.3)),
            // turned 200x300: centre (100, 75), 60x60 px; crop from x 50, 150x150
            '[DT] turned, then cropped' => array(
                $face, self::transform(1, self::rect(50, 0, 150, 150)), array(1 / 3, 0.5, 0.4, 0.4)),
            );
    }

    #[DataProvider('moved')]
    public function testARegionFollowsTheEdit(array $region, array $transform, array $expected): void
    {
        $moved = persons_transform_region($region, $transform);

        $this->assertNotNull($moved);
        foreach (array('x', 'y', 'w', 'h') as $i => $key)
        {
            $this->assertEqualsWithDelta($expected[$i], $moved[$key], self::DELTA, $key);
        }
        $this->assertSame('Anna', $moved['name']);
        $this->assertSame('Face', $moved['type']);
    }

    public static function removed(): array
    {
        return array(
            // x 240 of 150 = 1.6
            '[ECP] wholly outside the crop' => array(self::region(0.8, 0.5, 0.1, 0.2), self::rect(0, 0, 150, 200)),
            // MWG: the centre (x 165 of 150 = 1.1) is outside, though the box reaches in
            '[ECP] centre outside, box partly inside' => array(self::region(0.55, 0.5, 0.2, 0.3), self::rect(0, 0, 150, 200)),
            // x 269.85 of 270, w 3.6 px: clipped to 1.95 px = 0.0072 < PERSONS_MIN_BOX_FRACTION
            '[BVA] clipped below the minimum box' => array(self::region(0.8995, 0.5, 0.012, 0.3), self::rect(0, 0, 270, 200)),
            );
    }

    #[DataProvider('removed')]
    public function testARegionTheCropCutsAwayIsRemoved(array $region, array $crop): void
    {
        $this->assertNull(persons_transform_region($region, self::transform(0, $crop)));
    }

    /** [ECP] A turn alone never removes a region, even one at the edge. */
    public function testATurnRemovesNothing(): void
    {
        $this->assertNotNull(persons_transform_region(self::region(1.0, 0.0, 0.02, 0.02), self::transform(3, null)));
    }

    /** [HAPPY] The list version keeps what survives and names what does not, once per region. */
    public function testTheLostRegionsAreNamed(): void
    {
        $outcome = persons_transform_regions(array(
            self::region(0.25, 0.5, 0.2, 0.3, 'Anna'),
            self::region(0.8, 0.5, 0.1, 0.2, 'Josef'),
            self::region(0.9, 0.2, 0.1, 0.2, 'Josef'),
            ), self::transform(0, self::rect(0, 0, 150, 200)));

        $this->assertSame(array('Josef', 'Josef'), $outcome['lost']);
        $this->assertCount(1, $outcome['kept']);
        $this->assertSame('Anna', $outcome['kept'][0]['name']);
    }
}
