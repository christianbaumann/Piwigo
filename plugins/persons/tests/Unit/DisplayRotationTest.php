<?php
use PHPUnit\Framework\TestCase;

require_once PERSONS_PATH . 'include/render.inc.php';

/**
 * persons_display_box() against the turn core actually shows.
 *
 * images.rotation counts quarter turns counter-clockwise: a JPEG with EXIF
 * Orientation 6 gets code 3, and the page shows its raw file turned 90 degrees
 * clockwise (measured 2026-10-05, docs/agents/decisions/0035-images-rotation-counts-counter-clockwise.md).
 * persons reads the code as clockwise turns, so for codes 1 and 3 its boxes
 * land half a turn away from the face.
 */
final class DisplayRotationTest extends TestCase
{
    /**
     * A box in the raw file's top-left corner, on a photo with code 3: the page
     * shows the raw file turned a quarter clockwise, so the box is top-right.
     */
    public function testABoxOnAnOrientation6PhotoIsShownWhereCoreShowsTheFace(): void
    {
        $this->markTestSkipped(
            'Known bug, recorded not fixed: persons reads images.rotation as clockwise turns. '
            . 'See docs/agents/decisions/0035-images-rotation-counts-counter-clockwise.md and docs/backlog.md.'
        );

        $box = persons_display_box(array(
            'id' => 1, 'name' => 'Anna', 'region_type' => 'Face',
            'area_x' => 0.1, 'area_y' => 0.15, 'area_w' => 0.1, 'area_h' => 0.1,
            ), 3);

        // raw centre (0.1, 0.15) turned a quarter clockwise: (1 - 0.15, 0.1); the box is 0.1 x 0.1
        $this->assertSame(persons_percent(0.85 - 0.05), $box['LEFT']);
        $this->assertSame(persons_percent(0.1 - 0.05), $box['TOP']);
    }
}
