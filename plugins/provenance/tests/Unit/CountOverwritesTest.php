<?php
use PHPUnit\Framework\TestCase;

/**
 * Which photos "Apply to N photos" would overwrite (decision 0086).
 *
 * Decision table, two conditions per photo:
 *
 *   holds Herkunft | differs from the values | counted
 *   no             | (yes or no)             | no
 *   yes            | no                      | no
 *   yes            | in at least one field   | yes
 */
final class CountOverwritesTest extends TestCase
{
    private const VALUES = array(
        'provenance_physical_album' => 'Oma',
        'provenance_owner' => 'Anna',
        'provenance_scanned_on' => '2026-04-19',
        'provenance_album_note' => 'Notiz',
        );

    private const EMPTY_PHOTO = array(
        'provenance_physical_album' => null,
        'provenance_owner' => null,
        'provenance_scanned_on' => null,
        'provenance_album_note' => null,
        );

    /** [DT] */
    public function testOnlyAPhotoWithHerkunftThatDiffersCounts(): void
    {
        $photos = array(
            1 => self::EMPTY_PHOTO,
            2 => self::VALUES,
            3 => array_merge(self::VALUES, array('provenance_owner' => 'Berta')),
            4 => array_merge(self::EMPTY_PHOTO, array('provenance_owner' => 'Fremd')),
        );

        $this->assertSame(2, provenance_count_overwrites($photos, self::VALUES));
    }

    /** [BVA] Nothing to look at counts nothing. */
    public function testNoPhotosCountZero(): void
    {
        $this->assertSame(0, provenance_count_overwrites(array(), self::VALUES));
    }

    /** [ECP] Blank values (no Herkunft anywhere): every photo holding any counts. */
    public function testAnApplyOfBlanksOverwritesEveryPhotoThatHoldsAnything(): void
    {
        $blank = array_fill_keys(array_keys(self::VALUES), '');
        $photos = array(1 => self::EMPTY_PHOTO, 2 => self::VALUES);

        $this->assertSame(1, provenance_count_overwrites($photos, $blank));
    }
}
