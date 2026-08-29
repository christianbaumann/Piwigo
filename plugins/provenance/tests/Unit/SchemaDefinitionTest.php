<?php
use PHPUnit\Framework\TestCase;

/**
 * The schema definition every other layer reads. These are pure array builders,
 * so the rules they encode - which columns exist, and which of them an album
 * operation is allowed to write - are checkable with no database.
 */
final class SchemaDefinitionTest extends TestCase
{
    /** [HAPPY] The album carries the four fields an administrator fills in. */
    public function testAlbumColumns(): void
    {
        $this->assertSame(
            array(
                'provenance_physical_album',
                'provenance_owner',
                'provenance_scanned_on',
                'provenance_note',
            ),
            array_keys(provenance_album_columns())
        );
    }

    /** [HAPPY] The photo carries the four copied-down fields plus its own note. */
    public function testImageColumns(): void
    {
        $this->assertSame(
            array(
                'provenance_physical_album',
                'provenance_owner',
                'provenance_scanned_on',
                'provenance_album_note',
                'provenance_note',
            ),
            array_keys(provenance_image_columns())
        );
    }

    /**
     * [NEG] The photo's own note is never a copy-down target.
     *
     * This is the whole of decision C3 expressed as an assertion: an album
     * operation writes the four album-sourced columns and nothing else.
     */
    public function testCopyDownNeverTargetsThePhotosOwnNote(): void
    {
        $targets = array_values(provenance_copy_down_map());

        $this->assertGreaterThan(0, count($targets), 'anti-vacuity: an empty map would pass trivially');
        $this->assertNotContains('provenance_note', $targets);
    }

    /** [HAPPY] Every copy-down source is a real album column and every target a real photo column. */
    public function testCopyDownMapReferencesOnlyDeclaredColumns(): void
    {
        $albumColumns = array_keys(provenance_album_columns());
        $imageColumns = array_keys(provenance_image_columns());

        foreach (provenance_copy_down_map() as $source => $target)
        {
            $this->assertContains($source, $albumColumns, "copy-down source $source is not an album column");
            $this->assertContains($target, $imageColumns, "copy-down target $target is not a photo column");
        }
    }

    /** [BVA] Every album column is copied down; none is silently forgotten. */
    public function testEveryAlbumColumnIsCopiedDown(): void
    {
        $this->assertSame(
            array_keys(provenance_album_columns()),
            array_keys(provenance_copy_down_map())
        );
    }
}
