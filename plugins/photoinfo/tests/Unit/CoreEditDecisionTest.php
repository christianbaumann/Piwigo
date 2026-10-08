<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a save in one of core's screens changes on photoinfo's side
 * (photoinfo_core_edit()). Requirement: design "Core admin screens set an exact
 * date", "Core description edits rewrite the caption".
 */
final class CoreEditDecisionTest extends TestCase
{
    private const EXACT_DAY = array(
        'photoinfo_date_precision' => 'day',
        'photoinfo_date_qualifier' => null,
        'photoinfo_date_end' => null,
        'photoinfo_date_end_precision' => null,
    );

    private const NO_DATE = array(
        'photoinfo_date_precision' => null,
        'photoinfo_date_qualifier' => null,
        'photoinfo_date_end' => null,
        'photoinfo_date_end_precision' => null,
    );

    public static function edits(): array
    {
        $date = '1965-03-14 00:00:00';
        $other = '1966-05-02 10:20:30';

        // before, after => columns, write
        return array(
            'nothing changed' => array(
                array('date_creation' => $date, 'comment' => 'Text'),
                array('date_creation' => $date, 'comment' => 'Text'),
                null, false),
            'only the description changed' => array(
                array('date_creation' => $date, 'comment' => 'Text'),
                array('date_creation' => $date, 'comment' => 'Neu'),
                null, true),
            'description added' => array(
                array('date_creation' => $date, 'comment' => null),
                array('date_creation' => $date, 'comment' => 'Neu'),
                null, true),
            'date changed' => array(
                array('date_creation' => $date, 'comment' => 'Text'),
                array('date_creation' => $other, 'comment' => 'Text'),
                self::EXACT_DAY, true),
            'date set where there was none' => array(
                array('date_creation' => null, 'comment' => null),
                array('date_creation' => $date, 'comment' => null),
                self::EXACT_DAY, true),
            'date removed' => array(
                array('date_creation' => $date, 'comment' => null),
                array('date_creation' => null, 'comment' => null),
                self::NO_DATE, true),
            'set outright, same date' => array(
                null,
                array('date_creation' => $date, 'comment' => 'Text'),
                self::EXACT_DAY, true),
            'removed outright' => array(
                null,
                array('date_creation' => null, 'comment' => 'Text'),
                self::NO_DATE, true),
        );
    }

    /**
     * [DT] Two conditions - did the date change (or was it set outright), did
     * the description change - decide the columns and the file write.
     */
    #[DataProvider('edits')]
    public function testTheEditDecidesColumnsAndWrite(?array $before, array $after, ?array $columns, bool $write): void
    {
        $this->assertSame(array('columns' => $columns, 'write' => $write), photoinfo_core_edit($before, $after));
    }

    /** [BVA] A changed time of day alone is a changed date. */
    public function testAChangedTimeIsAChangedDate(): void
    {
        $edit = photoinfo_core_edit(
            array('date_creation' => '1965-03-14 00:00:00', 'comment' => null),
            array('date_creation' => '1965-03-14 00:00:01', 'comment' => null)
        );

        $this->assertSame(self::EXACT_DAY, $edit['columns']);
    }

    /** [NEG] Core's date_creation is never among the columns: core stored it, time of day included. */
    public function testCoresDateIsLeftAsCoreStoredIt(): void
    {
        $edit = photoinfo_core_edit(null, array('date_creation' => '1965-03-14 10:20:30', 'comment' => null));

        $this->assertArrayNotHasKey('date_creation', $edit['columns']);
    }
}
