<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The merge rule for consecutive info saves and the shape of a date entry's
 * value. Requirement: design "Several Info saves become one entry" - same
 * user, same photo, nothing else in between, within 10 minutes.
 */
final class HistoryRuleTest extends TestCase
{
    private const NOW = 1000000;
    private const USER = 7;

    private static function latest(array $override = array()): array
    {
        return array_merge(array(
            'field' => PROVENANCE_HISTORY_FIELD_INFO,
            'undoes_id' => null,
            'performed_by' => self::USER,
            'performed_at' => self::NOW - 180,
        ), $override);
    }

    public static function cases(): array
    {
        $window = PHOTOINFO_INFO_MERGE_SECONDS;

        return array(
            '[HAPPY] 3 minutes ago, same user' => array(self::latest(), self::USER, true),
            '[BVA] exactly at the window' => array(self::latest(array('performed_at' => self::NOW - $window)), self::USER, true),
            '[BVA] one second past the window' => array(self::latest(array('performed_at' => self::NOW - $window - 1)), self::USER, false),
            '[BVA] one second inside the window' => array(self::latest(array('performed_at' => self::NOW - $window + 1)), self::USER, true),
            '[NEG] another user' => array(self::latest(array('performed_by' => 8)), self::USER, false),
            '[NEG] a date entry in between' => array(self::latest(array('field' => PROVENANCE_HISTORY_FIELD_DATE)), self::USER, false),
            '[NEG] a tag entry in between' => array(self::latest(array('field' => PROVENANCE_HISTORY_FIELD_TAG)), self::USER, false),
            '[NEG] the latest is an undo' => array(self::latest(array('undoes_id' => 3)), self::USER, false),
            '[NEG] no entry yet' => array(null, self::USER, false),
            '[NEG] nobody logged in' => array(self::latest(array('performed_by' => null)), null, false),
        );
    }

    #[DataProvider('cases')]
    public function testTheMergeRule(?array $latest, ?int $user, bool $merges): void
    {
        $this->assertSame($merges, photoinfo_history_should_merge($latest, $user, self::NOW));
    }

    /** The default window is the named constant, ten minutes (design). */
    public function testTheWindowIsTenMinutes(): void
    {
        $this->assertSame(600, PHOTOINFO_INFO_MERGE_SECONDS);
        $this->assertTrue(photoinfo_history_should_merge(self::latest(array('performed_at' => self::NOW - 600)), self::USER, self::NOW));
        $this->assertFalse(photoinfo_history_should_merge(self::latest(array('performed_at' => self::NOW - 601)), self::USER, self::NOW));
    }

    public static function datings(): array
    {
        $date = fn ($year, $month = null, $day = null) => array('year' => $year, 'month' => $month, 'day' => $day);

        return array(
            'exact day' => array(array('qualifier' => null, 'start' => $date(1965, 3, 14), 'end' => null), '1965-03-14'),
            'circa year' => array(array('qualifier' => 'circa', 'start' => $date(1965), 'end' => null), '1965~'),
            'between' => array(array('qualifier' => 'between', 'start' => $date(1965), 'end' => $date(1970, 5)), '1965/1970-05'),
        );
    }

    /** [ECP] A value carries the display text and the EDTF, and the EDTF reads back to the same dating. */
    #[DataProvider('datings')]
    public function testADateValueRoundTrips(array $dating, string $edtf): void
    {
        $value = photoinfo_history_date_value($dating);

        $this->assertSame(photoinfo_dating_display($dating) . ' (' . $edtf . ')', $value);
        $parsed = photoinfo_history_date_parse($value);
        $this->assertSame($edtf, $parsed['edtf']);
        $this->assertSame(photoinfo_dating_display($dating), $parsed['display']);
        $this->assertSame($dating, photoinfo_dating_from_edtf($parsed['edtf'], 2026)['dating']);
    }

    /** [ECP] No date is the absence provenance stores as NULL. */
    public function testNoDateHasNoValue(): void
    {
        $this->assertNull(photoinfo_history_date_value(null));
        $this->assertNull(photoinfo_history_date_parse(null));
        $this->assertNull(photoinfo_history_date_parse('kein Datum'));
    }
}
