<?php
use PHPUnit\Framework\TestCase;

/**
 * Which tags a save added and removed, and the change-log entries that make:
 * one per tag, an added tag before a removed one, value {"id","name"}.
 *
 * Requirement: design "What gets a row" and "Values of tag and region entries".
 */
final class TagChangesTest extends TestCase
{
    private const SOURCE = 'picture_page';

    /** [ECP] Tags only in the new list are added, only in the old list removed. */
    public function testAddedAndRemovedAreTheTwoSidesOfTheDifference(): void
    {
        $this->assertSame(
            array('added' => array(7, 9), 'removed' => array(3)),
            photoinfo_tag_changes(array(3, 5), array(5, 9, 7))
            );
    }

    /** [ECP] The same tags in another order are no change. */
    public function testTheSameTagsInAnyOrderChangeNothing(): void
    {
        $this->assertSame(array('added' => array(), 'removed' => array()), photoinfo_tag_changes(array(2, 1), array(1, 2)));
    }

    /** [BVA] From no tags, everything is added; to no tags, everything is removed. */
    public function testTheEmptyListsAreTheBoundaries(): void
    {
        $this->assertSame(array('added' => array(4), 'removed' => array()), photoinfo_tag_changes(array(), array(4)));
        $this->assertSame(array('added' => array(), 'removed' => array(4)), photoinfo_tag_changes(array(4), array()));
    }

    /** [ECP] Ids that arrive as strings from the database compare as numbers. */
    public function testStringIdsCompareAsNumbers(): void
    {
        $this->assertSame(array('added' => array(), 'removed' => array()), photoinfo_tag_changes(array('5'), array(5)));
    }

    /** [HAPPY] An added tag has no old value, a removed one no new value. */
    public function testEntriesCarryTheTagAsJson(): void
    {
        $changes = photoinfo_tag_history_changes(12, array(3), array(5), array(3 => 'Alt', 5 => 'Kirche'), self::SOURCE);

        $this->assertCount(2, $changes, 'anti-vacuity: one add and one removal are expected');
        $this->assertSame(array(null, '{"id":5,"name":"Kirche"}'), array($changes[0]['old'], $changes[0]['new']));
        $this->assertSame(array('{"id":3,"name":"Alt"}', null), array($changes[1]['old'], $changes[1]['new']));
        foreach ($changes as $change)
        {
            $this->assertSame(array('photo', 12, PROVENANCE_HISTORY_FIELD_TAG, self::SOURCE),
                array($change['object'], $change['object_id'], $change['field'], $change['source']));
        }
    }

    /** [NEG] No difference, no entry. */
    public function testNoDifferenceMakesNoEntry(): void
    {
        $this->assertSame(array(), photoinfo_tag_history_changes(12, array(1, 2), array(2, 1), array(), self::SOURCE));
    }
}
