<?php
use PHPUnit\Framework\TestCase;

require_once GALLERYRULES_PATH . 'include/addsimple.inc.php';

/**
 * The refusal of an identical file names the photo it is identical to
 * (design: "addSimple refuses a file identical to an existing photo").
 *
 * Technique notes: boundary value not applicable, the input is a list of photos
 * with no ordering; decision table and state transition not applicable, one
 * input and one outcome per class, no state.
 */
final class IdenticalFileMessageTest extends TestCase
{
    /** [HAPPY] One photo: its id, title and file name. */
    public function testNamesThePhoto(): void
    {
        $this->assertSame(
            'The file is identical to photo #12 "Kirmes 1954" (scan-12.png), which was left unchanged.',
            galleryrules_identical_file_message(array(array('id' => 12, 'name' => 'Kirmes 1954', 'file' => 'scan-12.png')))
        );
    }

    /** [ECP] A photo without a title is named by its id and file alone. */
    public function testAPhotoWithoutTitle(): void
    {
        $this->assertSame(
            'The file is identical to photo #3 (a.png), which was left unchanged.',
            galleryrules_identical_file_message(array(array('id' => 3, 'name' => null, 'file' => 'a.png')))
        );
    }

    /** [ECP] Several identical photos are all named. */
    public function testSeveralPhotos(): void
    {
        $this->assertSame(
            'The file is identical to photos #3 (a.png), #4 "B" (b.png), which were left unchanged.',
            galleryrules_identical_file_message(array(
                array('id' => 3, 'name' => '', 'file' => 'a.png'),
                array('id' => 4, 'name' => 'B', 'file' => 'b.png'),
                ))
        );
    }

    /** [ERR] The message becomes the HTTP status line, so a line break in a title cannot reach it. */
    public function testLineBreaksAreRemoved(): void
    {
        $message = galleryrules_identical_file_message(array(array('id' => 5, 'name' => "a\r\nX-Injected: 1", 'file' => "f\n.png")));

        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1f\x7f]/', $message);
        $this->assertStringContainsString('#5 "a X-Injected: 1" (f .png)', $message);
    }
}
