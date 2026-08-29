<?php
use PHPUnit\Framework\TestCase;

/**
 * provenance_compose_caption() turns the five provenance values, already
 * labelled by the caller, into the single line of text that goes into every
 * caption slot of the image file. It is the only place that decides field
 * order and separator, so every other layer reads those from here.
 */
final class ComposeCaptionTest extends TestCase
{
    /** Labelled parts in the canonical order, one per provenance field. */
    private function allParts(): array
    {
        return array(
            'provenance_physical_album' => 'Album: Oma Mueller, blaues Album',
            'provenance_owner'          => 'Owner: Anna Mueller',
            'provenance_scanned_on'     => 'Scanned: 2026-04-19',
            'provenance_album_note'     => 'Album note: Rueckseiten beschriftet',
            'provenance_note'           => 'Photo note: Ecke abgerissen',
        );
    }

    /** [HAPPY] All five parts join in provenance_field_order() order. */
    public function testJoinsAllPartsInFieldOrder(): void
    {
        $parts = $this->allParts();
        $this->assertSame(
            implode(PROVENANCE_CAPTION_SEPARATOR, array_values($parts)),
            provenance_compose_caption($parts)
        );
    }

    /**
     * [HAPPY] The order is the declared field order, not the caller's array order.
     *
     * Kills a reversed or re-sorted provenance_field_order(): the same values
     * handed over shuffled must still compose identically.
     */
    public function testOrderComesFromTheFieldOrderNotTheInputOrder(): void
    {
        $parts = $this->allParts();

        $this->assertSame(
            provenance_compose_caption($parts),
            provenance_compose_caption(array_reverse($parts, true))
        );
        $this->assertStringStartsWith('Album: ', provenance_compose_caption(array_reverse($parts, true)));
    }

    /** [ECP] An empty part is dropped; no doubled separator is left behind. */
    public function testEmptyPartIsOmitted(): void
    {
        $parts = $this->allParts();
        $parts['provenance_owner'] = '';

        $caption = provenance_compose_caption($parts);

        $this->assertStringNotContainsString(
            PROVENANCE_CAPTION_SEPARATOR . PROVENANCE_CAPTION_SEPARATOR,
            $caption
        );
        $this->assertSame(4, substr_count($caption, PROVENANCE_CAPTION_SEPARATOR) + 1);
    }

    /** [ECP] A whitespace-only part is dropped, exactly like an empty one. */
    public function testWhitespaceOnlyPartIsOmitted(): void
    {
        $parts = $this->allParts();
        $parts['provenance_owner'] = "  \t\n ";

        $this->assertSame(
            provenance_compose_caption(array_diff_key($parts, array('provenance_owner' => 1))),
            provenance_compose_caption($parts)
        );
    }

    /** [ECP] A missing key is dropped, exactly like an empty one. */
    public function testMissingKeyIsOmitted(): void
    {
        $parts = $this->allParts();
        unset($parts['provenance_note']);

        $caption = provenance_compose_caption($parts);

        $this->assertStringEndsWith('Album note: Rueckseiten beschriftet', $caption);
        $this->assertSame(3, substr_count($caption, PROVENANCE_CAPTION_SEPARATOR));
    }

    /** [BVA] All parts empty returns the empty string, never a bare separator. */
    public function testAllPartsEmptyReturnsEmptyString(): void
    {
        $this->assertSame('', provenance_compose_caption(array(
            'provenance_physical_album' => '',
            'provenance_owner'          => '   ',
            'provenance_scanned_on'     => '',
            'provenance_album_note'     => '',
            'provenance_note'           => '',
        )));
    }

    /** [BVA] No input at all returns the empty string. */
    public function testNoPartsReturnsEmptyString(): void
    {
        $this->assertSame('', provenance_compose_caption(array()));
    }

    /** [BVA] Exactly one part carries no separator anywhere. */
    public function testSinglePartCarriesNoSeparator(): void
    {
        $caption = provenance_compose_caption(array('provenance_owner' => 'Owner: Anna Mueller'));

        $this->assertSame('Owner: Anna Mueller', $caption);
        $this->assertStringNotContainsString(PROVENANCE_CAPTION_SEPARATOR, $caption);
    }

    /** [ECP] Each part is trimmed, so a stray space never reaches the file. */
    public function testEachPartIsTrimmed(): void
    {
        $this->assertSame(
            'Owner: Anna' . PROVENANCE_CAPTION_SEPARATOR . 'Photo note: x',
            provenance_compose_caption(array(
                'provenance_owner' => "  Owner: Anna \n",
                'provenance_note'  => "\tPhoto note: x  ",
            ))
        );
    }

    /**
     * [ERR] A part that itself contains the separator passes through unchanged.
     *
     * Characterization: no requirement says what should happen to free text that
     * happens to contain " | ". This records that it is not escaped or re-split.
     */
    public function testPartContainingTheSeparatorPassesThrough(): void
    {
        $this->assertSame(
            'Owner: a' . PROVENANCE_CAPTION_SEPARATOR . 'b',
            provenance_compose_caption(array('provenance_owner' => 'Owner: a' . PROVENANCE_CAPTION_SEPARATOR . 'b'))
        );
    }

    /** [HAPPY] The field order is the five image provenance columns. */
    public function testFieldOrderIsTheFiveImageColumns(): void
    {
        $this->assertSame(array_keys(provenance_image_columns()), provenance_field_order());
    }
}
