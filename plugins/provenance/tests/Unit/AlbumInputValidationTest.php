<?php
use PHPUnit\Framework\TestCase;

/**
 * The boundary validation pwg.provenance.setAlbumInfo applies before anything
 * reaches SQL. Pure, so it is checkable with no database and no HTTP - the web
 * service handler contributes only the HTTP status codes.
 */
final class AlbumInputValidationTest extends TestCase
{
    // ── provenance_is_valid_scanned_on ────────────────────────────────────

    /** [HAPPY] A real calendar date in the one accepted shape. */
    public function testAcceptsAnIsoDate(): void
    {
        $this->assertTrue(provenance_is_valid_scanned_on('2026-08-29'));
    }

    /** [BVA] Empty clears the field, and is therefore valid input. */
    public function testAcceptsEmptyAsAClear(): void
    {
        $this->assertTrue(provenance_is_valid_scanned_on(''));
    }

    /** [BVA] Whitespace-only is the same clear as empty. */
    public function testAcceptsWhitespaceOnlyAsAClear(): void
    {
        $this->assertTrue(provenance_is_valid_scanned_on("  \n "));
    }

    /**
     * [BVA] A shape-valid date that does not exist is refused.
     *
     * The regexp alone would accept it; only checkdate() catches it, so this is
     * the case that says the second check is really there.
     *
     */
    #[PHPUnit\Framework\Attributes\DataProvider('impossibleCalendarDates')]
    public function testRefusesADateThatDoesNotExist(string $value): void
    {
        $this->assertFalse(provenance_is_valid_scanned_on($value));
    }

    public static function impossibleCalendarDates(): array
    {
        return array(
            'february 29 in a common year' => array('2026-02-29'),
            'february 30 in a leap year'   => array('2024-02-30'),
            'month 13'                     => array('2026-13-01'),
            'month 00'                     => array('2026-00-10'),
            'day 00'                       => array('2026-08-00'),
            'day 32'                       => array('2026-08-32'),
            'the all-zero date'            => array('0000-00-00'),
            );
    }

    /** [BVA] February 29 in a leap year is a real date and is accepted. */
    public function testAcceptsTheLeapDay(): void
    {
        $this->assertTrue(provenance_is_valid_scanned_on('2024-02-29'));
    }

    /**
     * [NEG] Anything not exactly YYYY-MM-DD is refused, including shapes MySQL
     * would otherwise coerce into a wrong date or into 0000-00-00.
     *
     */
    #[PHPUnit\Framework\Attributes\DataProvider('malformedDates')]
    public function testRefusesAMalformedDate(string $value): void
    {
        $this->assertFalse(provenance_is_valid_scanned_on($value));
    }

    public static function malformedDates(): array
    {
        return array(
            'unpadded parts'       => array('2026-8-9'),
            'two-digit year'       => array('26-08-29'),
            'with a time'          => array('2026-08-29 10:00:00'),
            'trailing character'   => array('2026-08-29x'),
            'leading character'    => array('x2026-08-29'),
            'slashes'              => array('2026/08/29'),
            'free text'            => array('yesterday'),
            'a bare number'        => array('20260829'),
            );
    }

    // ── provenance_clean_short_text ───────────────────────────────────────

    /** [HAPPY] Ordinary text passes through unchanged and inside the cap. */
    public function testShortTextPassesOrdinaryTextThrough(): void
    {
        $this->assertSame(
            array('text' => 'Oma Müllers Fotoalbum', 'too_long' => false),
            provenance_clean_short_text('Oma Müllers Fotoalbum')
        );
    }

    /** [ECP] Markup is stripped: this text is destined for an EXIF packet. */
    public function testShortTextStripsMarkup(): void
    {
        $this->assertSame('bold', provenance_clean_short_text('<b>bold</b>')['text']);
    }

    /** [ECP] Surrounding whitespace typed into a form is not a provenance fact. */
    public function testShortTextTrims(): void
    {
        $this->assertSame('Album 3', provenance_clean_short_text("  Album 3\n")['text']);
    }

    /** [BVA] Empty input stays empty and is not over-long. */
    public function testShortTextAcceptsEmpty(): void
    {
        $this->assertSame(array('text' => '', 'too_long' => false), provenance_clean_short_text(''));
    }

    /** [BVA] Exactly at the cap is accepted. */
    public function testShortTextAtTheCapIsAccepted(): void
    {
        $value = str_repeat('a', PROVENANCE_SHORT_TEXT_MAX_CHARS);

        $this->assertGreaterThan(0, PROVENANCE_SHORT_TEXT_MAX_CHARS, 'anti-vacuity: a zero cap would make every case below meaningless');
        $this->assertFalse(provenance_clean_short_text($value)['too_long']);
    }

    /** [BVA] One character past the cap is refused rather than silently cut. */
    public function testShortTextOneOverTheCapIsFlagged(): void
    {
        $value = str_repeat('a', PROVENANCE_SHORT_TEXT_MAX_CHARS + 1);
        $result = provenance_clean_short_text($value);

        $this->assertTrue($result['too_long']);
        $this->assertSame($value, $result['text'], 'the value is reported back whole; rejecting is the caller’s job, not truncating');
    }

    /**
     * [BVA] The cap counts characters, not bytes.
     *
     * The column is VARCHAR(255) on a utf8mb4 table, which is 255 *characters*.
     * A byte cap here would refuse 128 perfectly storable umlauts - and is the
     * opposite of PROVENANCE_IPTC_MAX_BYTES, which really is a byte budget.
     */
    public function testShortTextCapCountsCharactersNotBytes(): void
    {
        $value = str_repeat('ä', PROVENANCE_SHORT_TEXT_MAX_CHARS);

        $this->assertGreaterThan(
            PROVENANCE_SHORT_TEXT_MAX_CHARS,
            strlen($value),
            'anti-vacuity: the fixture must really be longer in bytes than in characters'
        );
        $this->assertFalse(provenance_clean_short_text($value)['too_long']);
        $this->assertTrue(provenance_clean_short_text($value . 'ä')['too_long']);
    }

    /**
     * [DT] The cap is measured after stripping, so markup a user never sees
     * cannot push a legitimate value over the limit.
     */
    public function testShortTextMeasuresTheCapAfterStripping(): void
    {
        $value = '<span class="' . str_repeat('x', 400) . '">' . str_repeat('a', 10) . '</span>';
        $result = provenance_clean_short_text($value);

        $this->assertSame(str_repeat('a', 10), $result['text']);
        $this->assertFalse($result['too_long']);
    }

    // ── provenance_clean_note ─────────────────────────────────────────────

    /** [HAPPY] The note keeps its internal line breaks; only the ends are trimmed. */
    public function testNoteKeepsInternalNewlines(): void
    {
        $this->assertSame("erste Zeile\nzweite Zeile", provenance_clean_note("\n erste Zeile\nzweite Zeile \n"));
    }

    /** [ECP] Markup is stripped from the note for the same reason as the short fields. */
    public function testNoteStripsMarkup(): void
    {
        $this->assertSame('geliehen von Anna', provenance_clean_note('<i>geliehen von Anna</i>'));
    }

    /**
     * [BVA] The note has no length cap - the column is TEXT, and cutting a
     * provenance fact silently is exactly what this feature exists to avoid.
     */
    public function testNoteHasNoLengthCap(): void
    {
        $long = rtrim(str_repeat('Ω lange Notiz. ', 500));

        $this->assertGreaterThan(
            PROVENANCE_SHORT_TEXT_MAX_CHARS,
            mb_strlen($long),
            'anti-vacuity: the fixture must exceed the short-field cap for this to say anything'
        );
        $this->assertSame($long, provenance_clean_note($long . ' '));
    }
}
