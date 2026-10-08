<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Qualifiers and ranges in the date's pure layer: reading the form, the
 * columns, the German text and the EDTF string.
 *
 * Requirements: design "Date model" (the combination table), "Qualifier and
 * range as one select, with no genau option", "Year bounds 1800 to the current
 * year" (end not before start), "German display format".
 */
final class DatingTest extends TestCase
{
    private const NOW = 2026;
    private const NONE = array('', '', '');

    private static function date(int $year, ?int $month = null, ?int $day = null): array
    {
        return array('year' => $year, 'month' => $month, 'day' => $day);
    }

    private static function dating(?string $qualifier, array $start, ?array $end = null): array
    {
        return array('qualifier' => $qualifier, 'start' => $start, 'end' => $end);
    }

    public static function combinations(): array
    {
        // qualifier, start fields, end fields => dating, shown, EDTF
        return array(
            'exact year' => array('', array('1965', '', ''), self::NONE,
                self::dating(null, self::date(1965)), '1965', '1965'),
            'exact month' => array('', array('1965', '3', ''), self::NONE,
                self::dating(null, self::date(1965, 3)), 'März 1965', '1965-03'),
            'exact day' => array('', array('1965', '3', '14'), self::NONE,
                self::dating(null, self::date(1965, 3, 14)), '14. März 1965', '1965-03-14'),
            'circa year' => array('circa', array('1965', '', ''), self::NONE,
                self::dating('circa', self::date(1965)), 'ca. 1965', '1965~'),
            'circa month' => array('circa', array('1965', '3', ''), self::NONE,
                self::dating('circa', self::date(1965, 3)), 'ca. März 1965', '1965-03~'),
            'circa day' => array('circa', array('1965', '3', '14'), self::NONE,
                self::dating('circa', self::date(1965, 3, 14)), 'ca. 14. März 1965', '1965-03-14~'),
            'before year' => array('before', array('1965', '', ''), self::NONE,
                self::dating('before', self::date(1965)), 'vor 1965', '../1965'),
            'before day' => array('before', array('1965', '3', '14'), self::NONE,
                self::dating('before', self::date(1965, 3, 14)), 'vor 14. März 1965', '../1965-03-14'),
            'after month' => array('after', array('1965', '3', ''), self::NONE,
                self::dating('after', self::date(1965, 3)), 'nach März 1965', '1965-03/..'),
            'after year' => array('after', array('1965', '', ''), self::NONE,
                self::dating('after', self::date(1965)), 'nach 1965', '1965/..'),
            'between years' => array('between', array('1965', '', ''), array('1970', '', ''),
                self::dating('between', self::date(1965), self::date(1970)), "1965\u{2013}1970", '1965/1970'),
            'between month and day' => array('between', array('1965', '3', ''), array('1966', '5', '2'),
                self::dating('between', self::date(1965, 3), self::date(1966, 5, 2)),
                "März 1965\u{2013}2. Mai 1966", '1965-03/1966-05-02'),
            'between, same year twice [BVA]' => array('between', array('1965', '', ''), array('1965', '', ''),
                self::dating('between', self::date(1965), self::date(1965)), "1965\u{2013}1965", '1965/1965'),
            'between, end inside the start month [BVA]' => array('between', array('1965', '3', '14'), array('1965', '3', ''),
                self::dating('between', self::date(1965, 3, 14), self::date(1965, 3)),
                "14. März 1965\u{2013}März 1965", '1965-03-14/1965-03'),
            'between, end one day later [BVA]' => array('between', array('1965', '3', '14'), array('1965', '3', '15'),
                self::dating('between', self::date(1965, 3, 14), self::date(1965, 3, 15)),
                "14. März 1965\u{2013}15. März 1965", '1965-03-14/1965-03-15'),
            'between, end in the current year [BVA]' => array('between', array('1965', '', ''), array((string)self::NOW, '', ''),
                self::dating('between', self::date(1965), self::date(self::NOW)), "1965\u{2013}" . self::NOW, '1965/' . self::NOW),
            );
    }

    /**
     * [DT] Every qualifier at every precision, and ranges with an end of its own
     * precision: what the form gives, how it reads and its EDTF string.
     */
    #[DataProvider('combinations')]
    public function testEveryCombination(string $qualifier, array $start, array $end, array $dating, string $shown, string $edtf): void
    {
        $this->assertSame(array('dating' => $dating, 'error' => null),
            photoinfo_dating_from_input($qualifier, $start, $end, self::NOW));
        $this->assertSame($shown, photoinfo_dating_display($dating));
        $this->assertSame($edtf, photoinfo_dating_edtf($dating));
    }

    /** [DT] Every combination survives the trip through the row's columns. */
    #[DataProvider('combinations')]
    public function testTheColumnsGiveTheDatingBack(string $qualifier, array $start, array $end, array $dating, string $shown, string $edtf): void
    {
        $back = photoinfo_dating_from_row(photoinfo_dating_columns($dating));
        $this->assertSame($dating, $back);
        $this->assertSame($shown, photoinfo_dating_display($back));
        $this->assertSame($edtf, photoinfo_dating_edtf($back));
    }

    /** [HAPPY] The columns one range is stored in: the start in core's, the rest in the plugin's. */
    public function testTheColumnsOfARange(): void
    {
        $this->assertSame(
            array(
                'date_creation' => '1965-03-01 00:00:00',
                'photoinfo_date_precision' => 'month',
                'photoinfo_date_qualifier' => 'between',
                'photoinfo_date_end' => '1970-01-01',
                'photoinfo_date_end_precision' => 'year',
            ),
            photoinfo_dating_columns(self::dating('between', self::date(1965, 3), self::date(1970)))
        );
    }

    /** [ECP] No date clears every column, and is empty on the page and in the file. */
    public function testNoDateIsEmptyEverywhere(): void
    {
        $this->assertSame(array('dating' => null, 'error' => null), photoinfo_dating_from_input('', self::NONE, self::NONE, self::NOW));
        $this->assertSame(array_fill_keys(array('date_creation', 'photoinfo_date_precision', 'photoinfo_date_qualifier',
            'photoinfo_date_end', 'photoinfo_date_end_precision'), null), photoinfo_dating_columns(null));
        $this->assertSame('', photoinfo_dating_display(null));
        $this->assertSame('', photoinfo_dating_edtf(null));
        $this->assertNull(photoinfo_dating_from_row(array()));
    }

    public static function refusedInputs(): array
    {
        return array(
            'end before start, years [BVA]' => array('between', array('1965', '', ''), array('1964', '', ''), PHOTOINFO_DATE_ERROR_END_BEFORE_START),
            'end one month before [BVA]' => array('between', array('1965', '3', ''), array('1965', '2', ''), PHOTOINFO_DATE_ERROR_END_BEFORE_START),
            'end one day before [BVA]' => array('between', array('1965', '3', '14'), array('1965', '3', '13'), PHOTOINFO_DATE_ERROR_END_BEFORE_START),
            'between with no end [NEG]' => array('between', array('1965', '', ''), self::NONE, PHOTOINFO_DATE_ERROR_END),
            'circa with an end: ca. never takes a range [NEG]' => array('circa', array('1965', '', ''), array('1970', '', ''), PHOTOINFO_DATE_ERROR_END),
            'exact with an end [NEG]' => array('', array('1965', '', ''), array('1970', '', ''), PHOTOINFO_DATE_ERROR_END),
            'end with no start [NEG]' => array('', self::NONE, array('1970', '', ''), PHOTOINFO_DATE_ERROR_END),
            'qualifier with no date [NEG]' => array('circa', self::NONE, self::NONE, PHOTOINFO_DATE_ERROR_QUALIFIER),
            'unknown qualifier [NEG]' => array('exact', array('1965', '', ''), self::NONE, PHOTOINFO_DATE_ERROR_QUALIFIER),
            'end in the next year [BVA]' => array('between', array('1965', '', ''), array((string)(self::NOW + 1), '', ''), PHOTOINFO_DATE_ERROR_YEAR),
            'impossible end day [BVA]' => array('between', array('1965', '', ''), array('1970', '4', '31'), PHOTOINFO_DATE_ERROR_DAY),
            'impossible start, valid range [NEG]' => array('between', array('1799', '', ''), array('1970', '', ''), PHOTOINFO_DATE_ERROR_YEAR),
            );
    }

    #[DataProvider('refusedInputs')]
    public function testRefusedInputs(string $qualifier, array $start, array $end, string $error): void
    {
        $this->assertSame(array('dating' => null, 'error' => $error),
            photoinfo_dating_from_input($qualifier, $start, $end, self::NOW));
    }

    public static function comparisons(): array
    {
        return array(
            'earlier year' => array(self::date(1964, 12, 31), self::date(1965), -1),
            'later year' => array(self::date(1966), self::date(1965, 12, 31), 1),
            'year against a month in it' => array(self::date(1965), self::date(1965, 3), 0),
            'month against a day in it' => array(self::date(1965, 3), self::date(1965, 3, 31), 0),
            'earlier day' => array(self::date(1965, 3, 13), self::date(1965, 3, 14), -1),
            'same day' => array(self::date(1965, 3, 14), self::date(1965, 3, 14), 0),
            );
    }

    /** [ECP] Two dates compare at the coarser of their precisions. */
    #[DataProvider('comparisons')]
    public function testDatesCompareAtTheCoarserPrecision(array $a, array $b, int $expected): void
    {
        $this->assertSame($expected, photoinfo_date_compare($a, $b));
    }

    /** [ECP] A stored end without "between" is ignored: only a range has one. */
    public function testAStrayEndIsIgnored(): void
    {
        $row = array(
            'date_creation' => '1965-01-01 00:00:00',
            'photoinfo_date_precision' => 'year',
            'photoinfo_date_qualifier' => 'circa',
            'photoinfo_date_end' => '1970-01-01',
            'photoinfo_date_end_precision' => 'year',
        );

        $this->assertSame(self::dating('circa', self::date(1965)), photoinfo_dating_from_row($row));
    }

    /**
     * [NEG] A range row with no end - which setDate cannot write, but a rescan
     * or a hand edit could - reads as an exact date, never "1965–" or "1965/".
     */
    public function testARangeWithNoEndIsExact(): void
    {
        $row = array(
            'date_creation' => '1965-01-01 00:00:00',
            'photoinfo_date_precision' => 'year',
            'photoinfo_date_qualifier' => 'between',
        );

        $this->assertSame(self::dating(null, self::date(1965)), photoinfo_dating_from_row($row));
    }

    /** [ECP] A date set by core, with none of the plugin's columns, is exact to the day. */
    public function testACoreDateIsExact(): void
    {
        $this->assertSame(self::dating(null, self::date(1987, 6, 21)),
            photoinfo_dating_from_row(array('date_creation' => '1987-06-21 14:30:00')));
    }

    /** [HAPPY] The select's options and the column's ENUM come from one list, in order. */
    public function testTheQualifiersAndTheirColumn(): void
    {
        $this->assertSame(array('circa' => 'ca.', 'before' => 'vor', 'after' => 'nach', 'between' => 'zwischen'),
            photoinfo_qualifier_labels());
        $this->assertSame("ENUM('circa','before','after','between') DEFAULT NULL",
            photoinfo_image_columns()['photoinfo_date_qualifier']);
        $this->assertSame(array('comment', 'date_creation', 'photoinfo_date_precision', 'photoinfo_date_qualifier',
            'photoinfo_date_end', 'photoinfo_date_end_precision'), photoinfo_written_columns());
    }
}
