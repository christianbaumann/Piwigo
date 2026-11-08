<?php
use PHPUnit\Framework\TestCase;

/**
 * The value of a photo entry and the one place that says it offers no undo.
 * Requirement: task 05 of change 2026-10-20-picture-page-edit-ux.
 */
final class HistoryPhotoTest extends TestCase
{
    /** [ECP] A turn, a crop and both each read back as what they were. */
    public function testValuesRoundTrip(): void
    {
        $turn = photoinfo_history_photo_parse(photoinfo_history_photo_value(array('turns' => 1, 'crop_px' => null)));
        $crop = photoinfo_history_photo_parse(photoinfo_history_photo_value(array('turns' => 0, 'crop_px' => array('x' => 1, 'y' => 2, 'w' => 30, 'h' => 20))));

        $this->assertSame(array('turns' => 1, 'crop' => null), $turn);
        $this->assertSame(array('turns' => 0, 'crop' => array('w' => 30, 'h' => 20)), $crop);
    }

    /** [BVA] A turn counter outside 0-3 (a turn to the left is -1) is stored as 0-3. */
    public function testTurnsAreStoredModuloFour(): void
    {
        $value = photoinfo_history_photo_value(array('turns' => -1, 'crop_px' => null));

        $this->assertSame(3, photoinfo_history_photo_parse($value)['turns']);
    }

    /** [NEG] Other shapes parse to null. */
    public function testOtherShapesDoNotParse(): void
    {
        $this->assertNull(photoinfo_history_photo_parse(null));
        $this->assertNull(photoinfo_history_photo_parse('Foto gedreht'));
        $this->assertNull(photoinfo_history_photo_parse('{"turns":"1","crop":null}'));
    }

    /** [DT] A photo entry is not undoable; date, info, tag and region are. */
    public function testOnlyPhotoEntriesAreNotUndoable(): void
    {
        $this->assertFalse(photoinfo_history_is_undoable(PROVENANCE_HISTORY_FIELD_PHOTO));
        foreach (array(PROVENANCE_HISTORY_FIELD_DATE, PROVENANCE_HISTORY_FIELD_INFO, PROVENANCE_HISTORY_FIELD_TAG, PROVENANCE_HISTORY_FIELD_REGION) as $field)
        {
            $this->assertTrue(photoinfo_history_is_undoable($field), $field);
        }
    }
}
