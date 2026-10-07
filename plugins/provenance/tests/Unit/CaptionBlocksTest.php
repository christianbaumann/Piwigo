<?php
use PHPUnit\Framework\TestCase;

/**
 * The caption's blocks - another plugin's text and provenance's - joined into
 * one text, and the value files a multi-line text travels in.
 *
 * Requirement: design "One composed caption for info and provenance" - the
 * info text, a blank line, then the provenance text.
 */
final class CaptionBlocksTest extends TestCase
{
    /** [HAPPY] Blocks are joined in the order given, with one blank line between. */
    public function testBlocksAreJoinedWithABlankLine(): void
    {
        $this->assertSame(
            "Hochzeit von Anna\n\nOwner: Anna",
            provenance_join_caption_blocks(array('photoinfo' => 'Hochzeit von Anna', 'provenance' => 'Owner: Anna'))
        );
    }

    /** [ECP] An empty or whitespace block leaves no blank line at either end. */
    public function testEmptyBlocksAreDropped(): void
    {
        $this->assertSame('Owner: Anna', provenance_join_caption_blocks(array('photoinfo' => '  ', 'provenance' => 'Owner: Anna')));
        $this->assertSame('Info', provenance_join_caption_blocks(array('photoinfo' => 'Info', 'provenance' => '')));
        $this->assertSame('', provenance_join_caption_blocks(array()));
    }

    /** [ECP] A block's own line breaks are kept, in one form. */
    public function testLineBreaksInsideABlockAreKeptAsNewlines(): void
    {
        $this->assertSame("a\nb\n\nc", provenance_join_caption_blocks(array('x' => "a\r\nb", 'y' => "c\r")));
    }

    /** [HAPPY] Provenance's own block is one line, as its caption always was. */
    public function testProvenanceBlockCollapsesANoteTypedOverSeveralLines(): void
    {
        $this->assertSame(
            'Owner: Anna | Note: erste zweite',
            provenance_caption_block(
                array('provenance_owner' => 'Anna', 'provenance_note' => "erste\nzweite"),
                array('provenance_owner' => 'Owner', 'provenance_note' => 'Note')
            )
        );
    }

    /**
     * [DT] A value goes on the line unless it has a line break, in either form;
     * then the line names its value file.
     */
    public function testArgfileLineNamesAValueFileOnlyForALineBreak(): void
    {
        $this->assertSame('-XMP-dc:Description=a $b @c', provenance_argfile_line('XMP-dc:Description', 'a $b @c', '/f'));
        $this->assertSame('-XMP-dc:Description<=/f', provenance_argfile_line('XMP-dc:Description', "a\nb", '/f'));
        $this->assertSame('-XMP-dc:Description<=/f', provenance_argfile_line('XMP-dc:Description', "a\rb", '/f'));
        $this->assertSame('-XMP-dc:Description=', provenance_argfile_line('XMP-dc:Description', '', '/f'));
    }

    /** [HAPPY] Only the multi-line values become files, each under its own name. */
    public function testValueFilesListOnlyMultiLineValues(): void
    {
        $this->assertSame(
            array('/ops/3-caption.txt' => "a\n\nb", '/ops/3-caption-iptc.txt' => "a\n\nb"),
            provenance_argfile_value_files(provenance_caption_values("a\n\nb"), '/ops/3-')
        );
        $this->assertSame(array(), provenance_argfile_value_files(array('caption.txt' => 'a'), '/ops/3-'));
    }

    /** [BVA] The IPTC slot's file carries the caption cut to its byte budget. */
    public function testTheIptcValueIsTruncated(): void
    {
        $caption = "a\n\n" . str_repeat('b', PROVENANCE_IPTC_MAX_BYTES);
        $values = provenance_caption_values($caption);

        $this->assertSame($caption, $values['caption.txt']);
        $this->assertLessThanOrEqual(PROVENANCE_IPTC_MAX_BYTES, strlen($values['caption-iptc.txt']));
        $this->assertStringStartsWith("a\n\n", $values['caption-iptc.txt']);
    }
}
