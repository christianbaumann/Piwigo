<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once PERSONS_PATH . 'include/render.inc.php';

/**
 * persons_display_box() against the turn core actually shows.
 *
 * images.rotation counts quarter turns counter-clockwise: a JPEG with EXIF
 * Orientation 6 gets code 3, and the page shows its raw file turned 90 degrees
 * clockwise (measured 2026-10-05, docs/agents/decisions/0035-images-rotation-counts-counter-clockwise.md).
 *
 * Every case puts one 0.1 x 0.1 box in the raw file's top-left corner, centred
 * at (0.1, 0.15), and asserts where the page shows it.
 */
final class DisplayRotationTest extends TestCase
{
    private const BOX = array(
        'id' => 1, 'name' => 'Anna', 'region_type' => 'Face',
        'area_x' => 0.1, 'area_y' => 0.15, 'area_w' => 0.1, 'area_h' => 0.1,
        );

    /**
     * [ECP] Code 3 (Orientation 6): the page shows the raw file turned a quarter
     * clockwise, so the box is top-right.
     */
    public function testABoxOnAnOrientation6PhotoIsShownWhereCoreShowsTheFace(): void
    {
        $box = persons_display_box(self::BOX, 3);

        // raw centre (0.1, 0.15) turned a quarter clockwise: (1 - 0.15, 0.1)
        $this->assertSame(persons_percent(0.85 - 0.05), $box['LEFT']);
        $this->assertSame(persons_percent(0.1 - 0.05), $box['TOP']);
    }

    /**
     * [ECP] Code 1 (Orientation 8): the page shows the raw file turned a quarter
     * counter-clockwise, so the box is bottom-left.
     */
    public function testABoxOnAnOrientation8PhotoIsShownWhereCoreShowsTheFace(): void
    {
        $box = persons_display_box(self::BOX, 1);

        // raw centre (0.1, 0.15) turned a quarter counter-clockwise: (0.15, 1 - 0.1)
        $this->assertSame(persons_percent(0.15 - 0.05), $box['LEFT']);
        $this->assertSame(persons_percent(0.9 - 0.05), $box['TOP']);
    }

    /** [ECP] Code 0: shown where the raw file has it. */
    public function testABoxOnAnUnturnedPhotoStaysPut(): void
    {
        $box = persons_display_box(self::BOX, 0);

        $this->assertSame(persons_percent(0.1 - 0.05), $box['LEFT']);
        $this->assertSame(persons_percent(0.15 - 0.05), $box['TOP']);
    }

    /** [ECP] Code 2: a half turn is the same either way round, bottom-right. */
    public function testABoxOnAHalfTurnedPhotoIsMirroredOnBothAxes(): void
    {
        $box = persons_display_box(self::BOX, 2);

        $this->assertSame(persons_percent(0.9 - 0.05), $box['LEFT']);
        $this->assertSame(persons_percent(0.85 - 0.05), $box['TOP']);
    }

    /**
     * [ECP] [BVA] [NEG] The clockwise turns the page shows, per code: core takes
     * the code modulo 4, and never writes a negative one, which is read as none.
     */
    #[DataProvider('displayTurns')]
    public function testTheDisplayTurnsAreCoresCodeCountedTheOtherWayRound(int $code, int $turns): void
    {
        $this->assertSame($turns, persons_display_turns($code));
    }

    public static function displayTurns(): array
    {
        return array(
            'code 0' => array(0, 0),
            'code 1 (Orientation 8)' => array(1, 3),
            'code 2' => array(2, 2),
            'code 3 (Orientation 6)' => array(3, 1),
            'code 4 is a full turn' => array(4, 0),
            'code 5 is code 1' => array(5, 3),
            'a negative code is none' => array(-1, 0),
            );
    }
}
