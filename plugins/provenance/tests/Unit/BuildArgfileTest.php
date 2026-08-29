<?php
use PHPUnit\Framework\TestCase;

/**
 * provenance_build_argfile() produces the exact lines handed to exiftool via
 * -@. exiftool reads one argument per line and applies no escaping, so the line
 * shape is the whole safety contract: a value can never become a flag, and a
 * value can never become two arguments.
 */
final class BuildArgfileTest extends TestCase
{
    private const CAPTION = 'Album: Oma | Owner: Anna';

    private function allValues(): array
    {
        return array(
            'provenance_physical_album' => 'Oma Mueller, blaues Album',
            'provenance_owner'          => 'Anna Mueller',
            'provenance_scanned_on'     => '2026-04-19',
            'provenance_album_note'     => 'Rueckseiten beschriftet',
            'provenance_note'           => 'Ecke abgerissen',
        );
    }

    /** [HAPPY] The full line sequence, in order, for a fully populated photo. */
    public function testFullLineSequence(): void
    {
        $this->assertSame(
            array(
                '-charset',
                'iptc=UTF8',
                '-EXIF:ImageDescription=' . self::CAPTION,
                '-IPTC:Caption-Abstract=' . self::CAPTION,
                '-XMP-dc:Description=' . self::CAPTION,
                '-XMP-photoshop:Headline=' . self::CAPTION,
                '-XMP-tiff:ImageDescription=' . self::CAPTION,
                '-XMP-pwgprov:PhysicalAlbum=Oma Mueller, blaues Album',
                '-XMP-pwgprov:Owner=Anna Mueller',
                '-XMP-pwgprov:ScannedOn=2026-04-19',
                '-XMP-pwgprov:AlbumNote=Rueckseiten beschriftet',
                '-XMP-pwgprov:PhotoNote=Ecke abgerissen',
            ),
            provenance_build_argfile($this->allValues(), self::CAPTION)
        );
    }

    /** [HAPPY] The charset declaration comes first, or every value below it is mis-encoded. */
    public function testCharsetDeclarationComesFirst(): void
    {
        $lines = provenance_build_argfile($this->allValues(), self::CAPTION);

        $this->assertGreaterThan(2, count($lines), 'anti-vacuity: a two-line result would make the slice below trivial');
        $this->assertSame(array('-charset', 'iptc=UTF8'), array_slice($lines, 0, 2));
    }

    /**
     * [DT] Only the IPTC slot carries the truncated caption; every other slot
     * carries the full text. This is the whole point of having two copies.
     */
    public function testOnlyTheIptcSlotCarriesTheTruncatedCaption(): void
    {
        $caption = str_repeat('a', PROVENANCE_IPTC_MAX_BYTES + 500);
        $lines = provenance_build_argfile($this->allValues(), $caption);

        $iptc = $this->lineFor($lines, '-IPTC:Caption-Abstract');
        $this->assertLessThanOrEqual(PROVENANCE_IPTC_MAX_BYTES, strlen($iptc));
        $this->assertNotSame($caption, $iptc);

        foreach (array('-EXIF:ImageDescription', '-XMP-dc:Description',
                       '-XMP-photoshop:Headline', '-XMP-tiff:ImageDescription') as $tag)
        {
            $this->assertSame($caption, $this->lineFor($lines, $tag), "$tag must carry the full caption");
        }
    }

    /** [ECP] An empty value emits no line for that tag, rather than an empty tag. */
    public function testEmptyValueEmitsNoLine(): void
    {
        $values = $this->allValues();
        $values['provenance_owner'] = '';
        $values['provenance_note']  = '   ';

        $lines = provenance_build_argfile($values, self::CAPTION);

        $this->assertSame(array(), $this->linesStartingWith($lines, '-XMP-pwgprov:Owner'));
        $this->assertSame(array(), $this->linesStartingWith($lines, '-XMP-pwgprov:PhotoNote'));
        $this->assertCount(1, $this->linesStartingWith($lines, '-XMP-pwgprov:PhysicalAlbum'));
    }

    /** [ECP] A missing key behaves exactly like an empty one. */
    public function testMissingKeyEmitsNoLine(): void
    {
        $values = $this->allValues();
        unset($values['provenance_scanned_on']);

        $this->assertSame(
            array(),
            $this->linesStartingWith(provenance_build_argfile($values, self::CAPTION), '-XMP-pwgprov:ScannedOn')
        );
    }

    /** [ECP] An empty caption emits no caption slots but keeps the pwgprov tags. */
    public function testEmptyCaptionEmitsNoCaptionSlots(): void
    {
        $lines = provenance_build_argfile($this->allValues(), '');

        foreach (array('-EXIF:ImageDescription', '-IPTC:Caption-Abstract', '-XMP-dc:Description',
                       '-XMP-photoshop:Headline', '-XMP-tiff:ImageDescription') as $tag)
        {
            $this->assertSame(array(), $this->linesStartingWith($lines, $tag), "$tag must be omitted");
        }
        $this->assertCount(5, $this->linesStartingWith($lines, '-XMP-pwgprov:'));
    }

    /**
     * [BVA] Nothing to write at all returns no lines, so the caller can tell
     * without parsing that exiftool must not be invoked.
     */
    public function testNothingToWriteReturnsNoLines(): void
    {
        $this->assertSame(array(), provenance_build_argfile(array(), ''));
        $this->assertSame(array(), provenance_build_argfile(array(
            'provenance_owner' => '',
            'provenance_note'  => "  \n ",
        ), '  '));
    }

    /**
     * [NEG] A value containing a newline never becomes two argfile lines.
     *
     * exiftool splits its argfile on newlines with no escape, so an unhandled
     * newline in the free-text note would turn the tail of the note into a
     * separate - and possibly flag-shaped - argument.
     */
    public function testNewlineInAValueNeverProducesASecondLine(): void
    {
        $values = $this->allValues();
        $values['provenance_note'] = "erste Zeile\n-overwrite_original\r\nzweite\rdritte";

        $lines = provenance_build_argfile($values, self::CAPTION);

        foreach ($lines as $line)
        {
            $this->assertStringNotContainsString("\n", $line);
            $this->assertStringNotContainsString("\r", $line);
        }
        $this->assertCount(1, $this->linesStartingWith($lines, '-XMP-pwgprov:PhotoNote'));
        $this->assertSame(
            'erste Zeile -overwrite_original zweite dritte',
            $this->lineFor($lines, '-XMP-pwgprov:PhotoNote')
        );
    }

    /** [NEG] A caption containing a newline is collapsed too, in every slot. */
    public function testNewlineInTheCaptionNeverProducesASecondLine(): void
    {
        $lines = provenance_build_argfile($this->allValues(), "erste\nzweite");

        $this->assertCount(1, $this->linesStartingWith($lines, '-EXIF:ImageDescription'));
        $this->assertSame('erste zweite', $this->lineFor($lines, '-EXIF:ImageDescription'));
    }

    /**
     * [NEG] A value that starts with a dash stays a value.
     *
     * The tag and its value share one line separated by '=', so a leading dash
     * is inert. This records that the line shape - not any escaping - is what
     * makes it safe.
     */
    public function testValueStartingWithADashIsNotAFlag(): void
    {
        $values = $this->allValues();
        $values['provenance_owner'] = '-delete_original';

        $lines = provenance_build_argfile($values, self::CAPTION);

        $this->assertContains('-XMP-pwgprov:Owner=-delete_original', $lines);
        $this->assertNotContains('-delete_original', $lines);
    }

    /** [HAPPY] The pwgprov tag names match the namespace declared to exiftool. */
    public function testPwgprovTagNamesUseTheDeclaredPrefix(): void
    {
        $lines = $this->linesStartingWith(provenance_build_argfile($this->allValues(), self::CAPTION), '-XMP-');

        $this->assertCount(8, $lines);
        $this->assertCount(5, $this->linesStartingWith($lines, '-XMP-' . PROVENANCE_XMP_PREFIX . ':'));
    }

    private function lineFor(array $lines, string $tag): string
    {
        $matches = $this->linesStartingWith($lines, $tag . '=');
        $this->assertCount(1, $matches, "expected exactly one $tag line");

        return substr($matches[0], strlen($tag) + 1);
    }

    private function linesStartingWith(array $lines, string $prefix): array
    {
        return array_values(array_filter($lines, static function ($line) use ($prefix) {
            return strpos($line, $prefix) === 0;
        }));
    }
}
