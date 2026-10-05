<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The turn math: a box (the centre of interest) and the photo's size after
 * quarter turns clockwise, and the angle handed to pwg_image::rotate().
 *
 * The expected boxes are worked out by hand from one requirement: a clockwise
 * quarter turn moves the photo's top-left corner to the top-right.
 */
final class TurnTest extends TestCase
{
    /** An off-centre box near the top-left corner, so every turn moves it somewhere else. */
    private const BOX = array('l' => 0.1, 't' => 0.2, 'r' => 0.3, 'b' => 0.6);

    public static function turnedBoxes(): array
    {
        return array(
            '[ECP] no turn' => array(0, self::BOX),
            '[HAPPY] a quarter turn takes it to the top-right' => array(1, array('l' => 0.4, 't' => 0.1, 'r' => 0.8, 'b' => 0.3)),
            '[ECP] a half turn takes it to the bottom-right' => array(2, array('l' => 0.7, 't' => 0.4, 'r' => 0.9, 'b' => 0.8)),
            '[ECP] three quarters take it to the bottom-left' => array(3, array('l' => 0.2, 't' => 0.7, 'r' => 0.6, 'b' => 0.9)),
            '[BVA] four turns are none' => array(4, self::BOX),
            '[ERR] a negative turn is the same as its complement' => array(-1, array('l' => 0.2, 't' => 0.7, 'r' => 0.6, 'b' => 0.9)),
            );
    }

    #[DataProvider('turnedBoxes')]
    public function testABoxTurnsWithThePhoto(int $turns, array $expected): void
    {
        $turned = photoedit_turn_box(self::BOX, $turns);

        foreach ($expected as $edge => $value)
        {
            $this->assertEqualsWithDelta($value, $turned[$edge], 1e-9, "edge $edge after $turns turns");
        }
    }

    /** [HAPPY] A turned box keeps its area: the sides swap, they do not change. */
    public function testATurnedBoxKeepsItsSides(): void
    {
        $turned = photoedit_turn_box(self::BOX, 1);

        $this->assertEqualsWithDelta(self::BOX['b'] - self::BOX['t'], $turned['r'] - $turned['l'], 1e-9);
        $this->assertEqualsWithDelta(self::BOX['r'] - self::BOX['l'], $turned['b'] - $turned['t'], 1e-9);
    }

    public static function sizes(): array
    {
        return array(
            '[ECP] even turns keep the size' => array(2, array(300, 200)),
            '[ECP] odd turns swap it' => array(1, array(200, 300)),
            '[ECP] three turns swap it' => array(3, array(200, 300)),
            '[BVA] no turn' => array(0, array(300, 200)),
            );
    }

    #[DataProvider('sizes')]
    public function testThePhotosSizeFollowsTheTurn(int $turns, array $expected): void
    {
        $this->assertSame($expected, photoedit_turned_size(300, 200, $turns));
    }

    public static function angles(): array
    {
        return array(
            '[BVA] none' => array(0, 0),
            '[HAPPY] a clockwise quarter is 270 counter-clockwise' => array(1, 270),
            '[ECP] half' => array(2, 180),
            '[ECP] three quarters' => array(3, 90),
            );
    }

    #[DataProvider('angles')]
    public function testTheRotateAngleIsCounterClockwise(int $turns, int $expected): void
    {
        $this->assertSame($expected, photoedit_rotate_angle($turns));
    }

    public static function cois(): array
    {
        return array(
            // l 0.2, t 0, r 0.4, b 0.36 -> l 1 - b, t l, r 1 - t, b r
            '[HAPPY] a quarter turn' => array('fakj', 1, 'qfzk'),
            '[ECP] no turn' => array('fakj', 0, 'fakj'),
            '[BVA] the whole photo stays the whole photo' => array('aazz', 3, 'aazz'),
            '[NEG] none set' => array(null, 1, null),
            '[NEG] empty' => array('', 1, null),
            );
    }

    /** The stored centre of interest (admin/picture_coi.php's a..z per edge) turns with the photo. */
    #[DataProvider('cois')]
    public function testTheCentreOfInterestTurns(?string $coi, int $turns, ?string $expected): void
    {
        $this->assertSame($expected, photoedit_turn_coi($coi, $turns));
    }

    public static function versionedUrls(): array
    {
        return array(
            '[HAPPY] a direct URL gets the version' => array('/_data/i/upload/a-me.png', 'abc123', '/_data/i/upload/a-me.png?v=abc123'),
            '[NEG] an unedited photo keeps its URL' => array('/_data/i/upload/a-me.png', '', '/_data/i/upload/a-me.png'),
            '[NEG] a URL with a query is left alone (i.php?/...)' => array('/i.php?/upload/a-me.png', 'abc123', '/i.php?/upload/a-me.png'),
            );
    }

    /** A changed file gets a changed URL, so no browser reuses the old one. */
    #[DataProvider('versionedUrls')]
    public function testAnEditedPhotosUrlCarriesItsVersion(string $url, string $version, string $expected): void
    {
        $this->assertSame($expected, photoedit_versioned_url($url, $version));
    }

    public static function versionRows(): array
    {
        return array(
            '[HAPPY] a stored row' => array('{"12":"abc123"}', array(12 => 'abc123')),
            '[NEG] no row' => array(null, array()),
            '[NEG] not JSON' => array('a:1:{}', array()),
            '[NEG] JSON, not a map' => array('"abc"', array()),
            );
    }

    #[DataProvider('versionRows')]
    public function testTheVersionsRowIsDecodedOrEmpty(?string $json, array $expected): void
    {
        $this->assertSame($expected, photoedit_decode_versions($json));
    }
}
