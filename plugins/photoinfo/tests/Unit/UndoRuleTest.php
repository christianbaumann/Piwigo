<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The three rules behind pwg.photoinfo.undo and how they combine.
 * Requirement: design "Who may undo" (own entries for everybody, every entry
 * for an administrator), "Conflicts, undo rows, no redo" (only while the
 * current value is the entry's new one; an undo entry has no undo) and
 * "Undone entries" (an entry is undone once another points at it).
 */
final class UndoRuleTest extends TestCase
{
    private const OWNER = 7;
    private const OTHER = 8;

    public static function mayUndo(): array
    {
        return array(
            '[HAPPY] own entry' => array(self::OWNER, self::OWNER, false, true),
            '[NEG] another account\'s entry' => array(self::OWNER, self::OTHER, false, false),
            '[HAPPY] an administrator, another account\'s entry' => array(self::OWNER, self::OTHER, true, true),
            '[HAPPY] an administrator, own entry' => array(self::OWNER, self::OWNER, true, true),
            '[NEG] an entry nobody made, as a normal account' => array(null, self::OTHER, false, false),
            '[HAPPY] an entry nobody made, as an administrator' => array(null, self::OTHER, true, true),
        );
    }

    #[DataProvider('mayUndo')]
    public function testWhoMayUndo(?int $performedBy, int $viewer, bool $isAdmin, bool $expected): void
    {
        $this->assertSame($expected, photoinfo_history_may_undo($performedBy, $viewer, $isAdmin));
    }

    public static function isCurrent(): array
    {
        return array(
            '[HAPPY] same text' => array('Neu', 'Neu', true),
            '[NEG] another text' => array('Neuer', 'Neu', false),
            '[HAPPY] both no value' => array(null, null, true),
            '[NEG] value now, none expected' => array('Neu', null, false),
            '[NEG] none now, a value expected' => array(null, 'Neu', false),
            '[HAPPY] a tag id on both sides' => array(5, 5, true),
            '[NEG] another tag id' => array(6, 5, false),
        );
    }

    #[DataProvider('isCurrent')]
    public function testIsCurrent($current, $expected, bool $verdict): void
    {
        $this->assertSame($verdict, photoinfo_history_is_current($current, $expected));
    }

    public static function undoable(): array
    {
        $fields = array(
            PROVENANCE_HISTORY_FIELD_DATE => true,
            PROVENANCE_HISTORY_FIELD_INFO => true,
            PROVENANCE_HISTORY_FIELD_TAG => true,
            PROVENANCE_HISTORY_FIELD_REGION => true,
            PROVENANCE_HISTORY_FIELD_PHOTO => false,
        );
        $cases = array();
        foreach ($fields as $field => $expected)
        {
            $cases["[ECP] a plain $field entry"] = array($field, null, false, $expected);
        }
        $cases['[NEG] an undo entry'] = array(PROVENANCE_HISTORY_FIELD_INFO, 3, false, false);
        $cases['[NEG] an entry that is undone'] = array(PROVENANCE_HISTORY_FIELD_INFO, null, true, false);

        return $cases;
    }

    #[DataProvider('undoable')]
    public function testIsUndoableEntry(string $field, ?int $undoesId, bool $undone, bool $expected): void
    {
        $entry = array('field' => $field, 'undoes_id' => $undoesId, 'undone' => $undone);

        $this->assertSame($expected, photoinfo_history_entry_is_undoable($entry));
    }

    /** The decision table over the three rules; the first refusal in this order wins. */
    public static function verdicts(): array
    {
        $info = PROVENANCE_HISTORY_FIELD_INFO;

        return array(
            '[DT] all hold' => array($info, null, false, true, true, null),
            '[DT] not allowed, rest holds' => array($info, null, false, false, true, PHOTOINFO_UNDO_NOT_ALLOWED),
            '[DT] not allowed beats undone' => array($info, null, true, false, true, PHOTOINFO_UNDO_NOT_ALLOWED),
            '[DT] not allowed beats a conflict' => array($info, null, false, false, false, PHOTOINFO_UNDO_NOT_ALLOWED),
            '[DT] undone, allowed' => array($info, null, true, true, true, PHOTOINFO_UNDO_ALREADY_UNDONE),
            '[DT] undone beats a conflict' => array($info, null, true, true, false, PHOTOINFO_UNDO_ALREADY_UNDONE),
            '[DT] an undo entry is never undone again' => array($info, 3, false, true, true, PHOTOINFO_UNDO_NOT_ALLOWED),
            '[DT] a photo entry has none' => array(PROVENANCE_HISTORY_FIELD_PHOTO, null, false, true, true, PHOTOINFO_UNDO_NOT_ALLOWED),
            '[DT] conflict, rest holds' => array($info, null, false, true, false, PHOTOINFO_UNDO_CHANGED_SINCE),
        );
    }

    #[DataProvider('verdicts')]
    public function testTheVerdict(string $field, ?int $undoesId, bool $undone, bool $mayUndo, bool $isCurrent, ?string $refusal): void
    {
        $entry = array('field' => $field, 'undoes_id' => $undoesId, 'undone' => $undone);

        $this->assertSame($refusal, photoinfo_history_undo_refusal($entry, $mayUndo, $isCurrent));
    }

    public function testTheUiFieldsAreUndoableOnes(): void
    {
        $this->assertNotEmpty(photoinfo_history_undo_fields());
        foreach (photoinfo_history_undo_fields() as $field)
        {
            $this->assertTrue(photoinfo_history_is_undoable($field), $field);
        }
    }

    public static function offers(): array
    {
        $plain = array('field' => PROVENANCE_HISTORY_FIELD_TAG, 'undoes_id' => null, 'undone' => false);

        return array(
            '[HAPPY] own entry' => array($plain, true, false, true),
            '[NEG] another account\'s entry, as a normal account' => array($plain, false, false, false),
            '[HAPPY] another account\'s entry, as an administrator' => array($plain, false, true, true),
            '[NEG] undone, own' => array(array('undone' => true) + $plain, true, true, false),
            '[NEG] an undo entry, as an administrator' => array(array('undoes_id' => 3) + $plain, true, true, false),
            '[NEG] a photo entry' => array(array('field' => PROVENANCE_HISTORY_FIELD_PHOTO) + $plain, true, true, false),
            '[HAPPY] a region entry' => array(array('field' => PROVENANCE_HISTORY_FIELD_REGION) + $plain, true, false, true),
        );
    }

    #[DataProvider('offers')]
    public function testWhenTheListOffersUndo(array $entry, bool $own, bool $isAdmin, bool $expected): void
    {
        $this->assertSame($expected, photoinfo_history_offers_undo($entry, $own, $isAdmin));
    }
}
