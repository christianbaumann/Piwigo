<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The photo date's pure layer: reading the form fields, and every form the date
 * takes in the database, on the page and in the file.
 *
 * Requirements: design "Date model", "Year bounds 1800 to the current year",
 * "German display format", "Date tags in the file".
 */
final class DateTest extends TestCase
{
    private const NOW = 2026;

    private static function date(int $year, ?int $month = null, ?int $day = null): array
    {
        return array('year' => $year, 'month' => $month, 'day' => $day);
    }

    public static function acceptedInputs(): array
    {
        return array(
            'year only [HAPPY]' => array('1965', '', '', self::date(1965)),
            'month and year [HAPPY]' => array('1965', '3', '', self::date(1965, 3)),
            'full day [HAPPY]' => array('1965', '03', '14', self::date(1965, 3, 14)),
            'lowest year [BVA]' => array('1800', '', '', self::date(1800)),
            'current year [BVA]' => array((string)self::NOW, '', '', self::date(self::NOW)),
            'January [BVA]' => array('1965', '1', '', self::date(1965, 1)),
            'December [BVA]' => array('1965', '12', '', self::date(1965, 12)),
            'first day [BVA]' => array('1965', '3', '1', self::date(1965, 3, 1)),
            '31 March [BVA]' => array('1965', '3', '31', self::date(1965, 3, 31)),
            '30 April [BVA]' => array('1965', '4', '30', self::date(1965, 4, 30)),
            '29 February of a leap year [BVA]' => array('1964', '2', '29', self::date(1964, 2, 29)),
            '29 February 2000, divisible by 400 [BVA]' => array('2000', '2', '29', self::date(2000, 2, 29)),
            'surrounding blanks [ECP]' => array(' 1965 ', ' 3 ', '', self::date(1965, 3)),
            );
    }

    #[DataProvider('acceptedInputs')]
    public function testAcceptedInputs(string $year, string $month, string $day, array $expected): void
    {
        $this->assertSame(array('date' => $expected, 'error' => null),
            photoinfo_date_from_input($year, $month, $day, self::NOW));
    }

    /** [ECP] Three empty fields are no date, which clears it. */
    public function testEmptyFieldsAreNoDate(): void
    {
        $this->assertSame(array('date' => null, 'error' => null), photoinfo_date_from_input('', ' ', '', self::NOW));
    }

    public static function refusedInputs(): array
    {
        return array(
            '1799 [BVA]' => array('1799', '', '', PHOTOINFO_DATE_ERROR_YEAR),
            'next year [BVA]' => array((string)(self::NOW + 1), '', '', PHOTOINFO_DATE_ERROR_YEAR),
            'three digits [NEG]' => array('965', '', '', PHOTOINFO_DATE_ERROR_YEAR),
            'not a number [NEG]' => array('19x5', '', '', PHOTOINFO_DATE_ERROR_YEAR),
            'month without a year [NEG]' => array('', '3', '', PHOTOINFO_DATE_ERROR_YEAR),
            'month 0 [BVA]' => array('1965', '0', '', PHOTOINFO_DATE_ERROR_MONTH),
            'month 13 [BVA]' => array('1965', '13', '', PHOTOINFO_DATE_ERROR_MONTH),
            'day without a month [NEG]' => array('1965', '', '14', PHOTOINFO_DATE_ERROR_DAY),
            'day 0 [BVA]' => array('1965', '3', '0', PHOTOINFO_DATE_ERROR_DAY),
            '32 March [BVA]' => array('1965', '3', '32', PHOTOINFO_DATE_ERROR_DAY),
            '31 April [BVA]' => array('1965', '4', '31', PHOTOINFO_DATE_ERROR_DAY),
            '29 February of a common year [BVA]' => array('1965', '2', '29', PHOTOINFO_DATE_ERROR_DAY),
            '29 February 1900, divisible by 100 [BVA]' => array('1900', '2', '29', PHOTOINFO_DATE_ERROR_DAY),
            );
    }

    #[DataProvider('refusedInputs')]
    public function testRefusedInputs(string $year, string $month, string $day, string $error): void
    {
        $this->assertSame(array('date' => null, 'error' => $error),
            photoinfo_date_from_input($year, $month, $day, self::NOW));
    }

    public static function monthLengths(): array
    {
        return array(
            'January' => array(1965, 1, 31),
            'February, common year' => array(1965, 2, 28),
            'February, leap year' => array(1964, 2, 29),
            'February 1900, no leap year' => array(1900, 2, 28),
            'February 2000, leap year' => array(2000, 2, 29),
            'April' => array(1965, 4, 30),
            'June' => array(1965, 6, 30),
            'September' => array(1965, 9, 30),
            'November' => array(1965, 11, 30),
            'December' => array(1965, 12, 31),
            );
    }

    /** [BVA] 28, 29, 30 and 31 days, and the century rule. */
    #[DataProvider('monthLengths')]
    public function testDaysInMonth(int $year, int $month, int $days): void
    {
        $this->assertSame($days, photoinfo_days_in_month($year, $month));
    }

    public static function forms(): array
    {
        // date, precision, date_creation, display, EDTF / XMP, IPTC, chronology
        return array(
            'year' => array(self::date(1965), 'year', '1965-01-01 00:00:00', '1965', '1965', '1965:00:00', array('1965')),
            'month' => array(self::date(1965, 3), 'month', '1965-03-01 00:00:00', 'März 1965', '1965-03', '1965:03:00', array('1965', '03')),
            'day' => array(self::date(1965, 3, 14), 'day', '1965-03-14 00:00:00', '14. März 1965', '1965-03-14', '1965:03:14', array('1965', '03', '14')),
            'single-digit day' => array(self::date(1965, 12, 1), 'day', '1965-12-01 00:00:00', '1. Dezember 1965', '1965-12-01', '1965:12:01', array('1965', '12', '01')),
            );
    }

    /** [HAPPY] One date in every form it takes. */
    #[DataProvider('forms')]
    public function testEveryForm(array $date, string $precision, string $start, string $display, string $edtf, string $iptc, array $chronology): void
    {
        $this->assertSame($precision, photoinfo_date_precision($date));
        $this->assertSame($start, photoinfo_date_start($date));
        $this->assertSame($display, photoinfo_date_display($date));
        $this->assertSame($edtf, photoinfo_date_edtf($date));
        $this->assertSame($edtf, photoinfo_date_xmp($date));
        $this->assertSame($iptc, photoinfo_date_iptc($date));
        $this->assertSame($chronology, photoinfo_date_chronology($date));
        $this->assertSame($date, photoinfo_date_from_row($start, $precision), 'the row gives the date back');
    }

    /** [ECP] No date is empty in every form the file and the page take. */
    public function testNoDateIsEmptyEverywhere(): void
    {
        $this->assertSame('', photoinfo_date_display(null));
        $this->assertSame('', photoinfo_date_edtf(null));
        $this->assertSame('', photoinfo_date_xmp(null));
        $this->assertSame('', photoinfo_date_iptc(null));
    }

    /** [HAPPY] All twelve German month names, in order. */
    public function testGermanMonthNames(): void
    {
        $this->assertSame(
            array(1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'),
            photoinfo_month_names()
        );
    }

    public static function rows(): array
    {
        return array(
            'no date [ECP]' => array(null, null, null),
            'empty string [ECP]' => array('', null, null),
            'zero date [NEG]' => array('0000-00-00 00:00:00', null, null),
            'set elsewhere, no precision: exact day [ECP]' => array('1987-06-21 14:30:00', null, self::date(1987, 6, 21)),
            'year precision drops month and day [ECP]' => array('1965-01-01 00:00:00', 'year', self::date(1965)),
            'month precision drops the day [ECP]' => array('1965-03-01 00:00:00', 'month', self::date(1965, 3)),
            );
    }

    #[DataProvider('rows')]
    public function testDateFromRow(?string $dateCreation, ?string $precision, ?array $expected): void
    {
        $this->assertSame($expected, photoinfo_date_from_row($dateCreation, $precision));
    }

    /** [HAPPY] The column's ENUM is built from the precisions, in order. */
    public function testThePrecisionColumnCarriesEveryPrecision(): void
    {
        $columns = photoinfo_image_columns();

        $this->assertSame("ENUM('year','month','day') DEFAULT NULL", $columns['photoinfo_date_precision']);
        $this->assertContains('photoinfo_date_precision', photoinfo_written_columns());
        $this->assertContains('date_creation', photoinfo_written_columns());
    }
}
