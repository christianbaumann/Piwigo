<?php
use PHPUnit\Framework\TestCase;

/**
 * provenance_caption_parts() labels the five provenance values before
 * provenance_compose_caption() joins them. It exists so the labels - which come
 * from l10n() at the call site - never enter the pure layer, and so the caption
 * a file receives is testable without Piwigo's language layer.
 */
final class CaptionPartsTest extends TestCase
{
    private function labels(): array
    {
        return array(
            'provenance_physical_album' => 'Physical album',
            'provenance_owner'          => 'Owner',
            'provenance_scanned_on'     => 'Scanned on',
            'provenance_album_note'     => 'Album note',
            'provenance_note'           => 'Note',
        );
    }

    private function values(): array
    {
        return array(
            'provenance_physical_album' => 'Oma Müllers Fotoalbum',
            'provenance_owner'          => 'Anna Müller',
            'provenance_scanned_on'     => '2026-04-19',
            'provenance_album_note'     => 'Rückseiten beschriftet',
            'provenance_note'           => 'Ecke abgerissen',
        );
    }

    /** [HAPPY] Every populated value becomes one "Label: value" part, in field order. */
    public function testEveryValueBecomesALabelledPart(): void
    {
        $this->assertSame(
            array(
                'provenance_physical_album' => 'Physical album: Oma Müllers Fotoalbum',
                'provenance_owner'          => 'Owner: Anna Müller',
                'provenance_scanned_on'     => 'Scanned on: 2026-04-19',
                'provenance_album_note'     => 'Album note: Rückseiten beschriftet',
                'provenance_note'           => 'Note: Ecke abgerissen',
            ),
            provenance_caption_parts($this->values(), $this->labels())
        );
    }

    /** [HAPPY] The result feeds straight into the composer, in the declared order. */
    public function testTheResultComposesInFieldOrder(): void
    {
        $caption = provenance_compose_caption(provenance_caption_parts($this->values(), $this->labels()));

        $this->assertGreaterThan(20, strlen($caption), 'anti-vacuity: an empty caption would make the order check trivial');
        $this->assertSame(
            'Physical album: Oma Müllers Fotoalbum | Owner: Anna Müller | Scanned on: 2026-04-19'
            . ' | Album note: Rückseiten beschriftet | Note: Ecke abgerissen',
            $caption
        );
    }

    /** [ECP] An empty, whitespace-only or missing value contributes no part at all. */
    public function testEmptyValuesContributeNoPart(): void
    {
        $values = $this->values();
        $values['provenance_owner'] = '';
        $values['provenance_album_note'] = "  \n ";
        unset($values['provenance_note']);

        $parts = provenance_caption_parts($values, $this->labels());

        $this->assertSame(
            array('provenance_physical_album', 'provenance_scanned_on'),
            array_keys($parts)
        );
    }

    /** [BVA] Nothing populated yields no parts, so the caller writes no caption. */
    public function testNoValuesYieldsNoParts(): void
    {
        $this->assertSame(array(), provenance_caption_parts(array(), $this->labels()));
        $this->assertSame(array(), provenance_caption_parts(array('provenance_owner' => ' '), $this->labels()));
    }

    /**
     * [NEG] A missing label leaves the bare value rather than a stray colon.
     *
     * l10n() returns the key itself when a translation is missing, so this is a
     * defensive case rather than an expected one - but a caption reading
     * ": Anna Müller" would be written into every file of an album.
     */
    public function testAMissingLabelLeavesTheBareValue(): void
    {
        $parts = provenance_caption_parts($this->values(), array());

        $this->assertSame('Anna Müller', $parts['provenance_owner']);
    }

    /** [ECP] The label keys cover exactly the five provenance fields. */
    public function testLabelKeysCoverEveryField(): void
    {
        $this->assertSame(provenance_field_order(), array_keys(provenance_caption_label_keys()));
    }
}
