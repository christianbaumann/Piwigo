<?php
use PHPUnit\Framework\TestCase;

/**
 * The pure half of the recorder: deciding whether a write is a change at all,
 * and shaping the row that records it. No database is involved, so the rule
 * that matters most here - "a field whose value did not change writes no row" -
 * is checkable at the unit layer, where a re-apply over 76 unchanged photos
 * would otherwise only be visible as 380 surplus rows in a table.
 *
 * The SQL half (provenance_record_changes) is exercised against a real MariaDB
 * in tests/Integration/HistoryRecorderTest.php.
 */
final class HistoryRowTest extends TestCase
{
    private const ACTOR = 7;

    /** A shorthand for the argument shape provenance_history_row() takes. */
    private function change(array $overrides = array()): array
    {
        return array_merge(array(
            'object'    => 'album',
            'object_id' => 12,
            'field'     => 'provenance_owner',
            'old'       => 'Anna',
            'new'       => 'Bernd',
            'source'    => 'album_edit',
        ), $overrides);
    }

    /** [HAPPY] A changed value produces one fully populated row. */
    public function testChangedValueProducesARow(): void
    {
        $this->assertSame(
            array(
                'object'       => 'album',
                'object_id'    => 12,
                'field'        => 'provenance_owner',
                'old_value'    => 'Anna',
                'new_value'    => 'Bernd',
                'source'       => 'album_edit',
                'performed_by' => self::ACTOR,
            ),
            provenance_history_row($this->change(), self::ACTOR)
        );
    }

    /** [NEG] An unchanged value writes nothing. The trail records changes, not saves. */
    public function testUnchangedValueProducesNoRow(): void
    {
        $this->assertNull(
            provenance_history_row($this->change(array('old' => 'Anna', 'new' => 'Anna')), self::ACTOR)
        );
    }

    /**
     * [ECP] NULL and the empty string are the same absence.
     *
     * The database stores an emptied field as NULL, but a form posts it as '',
     * so a save that clears an already-empty field must not look like a change.
     */
    public function testNullAndEmptyStringAreTheSameAbsence(): void
    {
        $this->assertNull(provenance_history_row($this->change(array('old' => null, 'new' => '')), self::ACTOR));
        $this->assertNull(provenance_history_row($this->change(array('old' => '', 'new' => null)), self::ACTOR));
        $this->assertNull(provenance_history_row($this->change(array('old' => null, 'new' => null)), self::ACTOR));
    }

    /** [BVA] Filling an empty field is a change, recorded with a NULL old value. */
    public function testFillingAnEmptyFieldIsRecordedWithANullOldValue(): void
    {
        $row = provenance_history_row($this->change(array('old' => null, 'new' => 'Bernd')), self::ACTOR);

        $this->assertNotNull($row);
        $this->assertNull($row['old_value']);
        $this->assertSame('Bernd', $row['new_value']);
    }

    /** [BVA] Clearing a filled field is a change, recorded with a NULL new value. */
    public function testClearingAFieldIsRecordedWithANullNewValue(): void
    {
        $row = provenance_history_row($this->change(array('old' => 'Anna', 'new' => '')), self::ACTOR);

        $this->assertNotNull($row);
        $this->assertSame('Anna', $row['old_value']);
        $this->assertNull($row['new_value']);
    }

    /**
     * [BVA] Whitespace is not trimmed away.
     *
     * The note column is free text; a trailing newline an administrator removed
     * is a real edit, and the argfile layer - not the audit trail - is where
     * whitespace gets normalised.
     */
    public function testWhitespaceOnlyDifferenceIsAChange(): void
    {
        $this->assertNotNull(
            provenance_history_row($this->change(array('old' => 'Anna', 'new' => 'Anna ')), self::ACTOR)
        );
    }

    /**
     * [BVA] A value far longer than piwigo_activity.details could hold survives
     * shaping untouched - the exact case this table exists for.
     */
    public function testLongValueIsNotTruncated(): void
    {
        $long = str_repeat('x', 5000);
        $row = provenance_history_row($this->change(array('old' => null, 'new' => $long)), self::ACTOR);

        $this->assertNotNull($row);
        $this->assertSame(5000, strlen($row['new_value']));
    }

    /** [HAPPY] An unattributed write (filesystem sync, CLI) records a NULL actor. */
    public function testActorMayBeUnknown(): void
    {
        $row = provenance_history_row($this->change(), null);

        $this->assertNotNull($row);
        $this->assertNull($row['performed_by']);
    }

    /** [NEG] An object outside the enum is rejected rather than stored as ''. */
    public function testUnknownObjectIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        provenance_history_row($this->change(array('object' => 'category')), self::ACTOR);
    }

    /** [NEG] A source outside the enum is rejected too. */
    public function testUnknownSourceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        provenance_history_row($this->change(array('source' => 'guesswork')), self::ACTOR);
    }

    /** [NEG] A non-positive object id is rejected. */
    public function testNonPositiveObjectIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        provenance_history_row($this->change(array('object_id' => 0)), self::ACTOR);
    }

    /** [BVA] A field name exactly filling the column is accepted. */
    public function testFieldNameAtTheColumnWidthIsAccepted(): void
    {
        $field = str_repeat('f', PROVENANCE_HISTORY_FIELD_MAX_BYTES);
        $row = provenance_history_row($this->change(array('field' => $field)), self::ACTOR);

        $this->assertSame($field, $row['field']);
    }

    /** [BVA] One byte more would be silently cut by the column, so it is refused. */
    public function testFieldNameOverTheColumnWidthIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        provenance_history_row(
            $this->change(array('field' => str_repeat('f', PROVENANCE_HISTORY_FIELD_MAX_BYTES + 1))),
            self::ACTOR
        );
    }

    /** [NEG] An empty field name is refused - a row that names no field records nothing. */
    public function testEmptyFieldNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        provenance_history_row($this->change(array('field' => '')), self::ACTOR);
    }

    /**
     * [DT] Over a batch, the changed entries survive in order and the unchanged
     * ones drop out. This is the shape the apply operation hands to one
     * mass_inserts().
     */
    public function testBatchKeepsOnlyTheChangedEntriesInOrder(): void
    {
        $changes = array(
            $this->change(array('field' => 'a', 'old' => '1', 'new' => '2')),
            $this->change(array('field' => 'b', 'old' => '1', 'new' => '1')),
            $this->change(array('field' => 'c', 'old' => null, 'new' => '3')),
        );

        $rows = provenance_history_rows($changes, self::ACTOR);

        $this->assertCount(2, $rows);
        $this->assertSame(array('a', 'c'), array_column($rows, 'field'));
    }

    /** [BVA] A batch in which nothing changed produces no rows at all. */
    public function testBatchOfUnchangedEntriesProducesNoRows(): void
    {
        $changes = array(
            $this->change(array('old' => 'same', 'new' => 'same')),
            $this->change(array('old' => null, 'new' => '')),
        );

        $this->assertGreaterThan(0, count($changes), 'anti-vacuity: an empty batch would pass trivially');
        $this->assertSame(array(), provenance_history_rows($changes, self::ACTOR));
    }

    /** [HAPPY] Every enum value the schema declares is accepted as a source. */
    public function testEverySchemaSourceIsAccepted(): void
    {
        $this->assertGreaterThan(0, count(provenance_history_sources()), 'anti-vacuity: an empty enum list');

        foreach (provenance_history_sources() as $source)
        {
            $row = provenance_history_row($this->change(array('source' => $source)), self::ACTOR);
            $this->assertSame($source, $row['source']);
        }
    }

    /** [HAPPY] And every object the schema declares. */
    public function testEverySchemaObjectIsAccepted(): void
    {
        $this->assertGreaterThan(0, count(provenance_history_objects()), 'anti-vacuity: an empty enum list');

        foreach (provenance_history_objects() as $object)
        {
            $row = provenance_history_row($this->change(array('object' => $object)), self::ACTOR);
            $this->assertSame($object, $row['object']);
        }
    }
}
