<?php
use PHPUnit\Framework\TestCase;

/**
 * photoinfo's part in the caption provenance writes.
 *
 * Requirement: design "One composed caption for info and provenance" - the
 * info text first, a blank line, then the provenance text.
 */
final class CaptionBlocksTest extends TestCase
{
    /** [HAPPY] The info text goes first, ahead of provenance's block. */
    public function testTheInfoTextGoesFirst(): void
    {
        $blocks = photoinfo_caption_blocks(array('provenance' => 'Owner: Anna'), 'Hochzeit');

        $this->assertSame(array(PHOTOINFO_CAPTION_BLOCK, 'provenance'), array_keys($blocks));
        $this->assertSame("Hochzeit\n\nOwner: Anna", provenance_join_caption_blocks($blocks));
    }

    /** [ECP] No info text leaves provenance's caption exactly as it was. */
    public function testNoInfoTextLeavesTheProvenanceCaption(): void
    {
        foreach (array(null, '', "  \n ") as $info)
        {
            $this->assertSame(
                'Owner: Anna',
                provenance_join_caption_blocks(photoinfo_caption_blocks(array('provenance' => 'Owner: Anna'), $info))
            );
        }
    }

    /** [ECP] Only info text: the caption is the info text, with its own line breaks. */
    public function testOnlyTheInfoText(): void
    {
        $this->assertSame(
            "Zeile 1\nZeile 2",
            provenance_join_caption_blocks(photoinfo_caption_blocks(array('provenance' => ''), "Zeile 1\nZeile 2\n"))
        );
    }

    /** [ECP] The caption gets the text without markup; the stored text keeps it. */
    public function testTheCaptionBlockCarriesNoMarkup(): void
    {
        $this->assertSame(
            "Oma und Opa\n\nP",
            provenance_join_caption_blocks(photoinfo_caption_blocks(array('provenance' => 'P'), 'Oma <b>und</b> Opa'))
        );
    }

    /** [ERR] A block already named photoinfo is replaced, not kept twice. Oracle: the implementation. */
    public function testAnEarlierPhotoinfoBlockIsReplaced(): void
    {
        $blocks = photoinfo_caption_blocks(array('provenance' => 'P', PHOTOINFO_CAPTION_BLOCK => 'alt'), 'neu');

        $this->assertSame("neu\n\nP", provenance_join_caption_blocks($blocks));
    }
}
