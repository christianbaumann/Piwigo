<?php
use PHPUnit\Framework\TestCase;

require_once GALLERYRULES_PATH . 'include/last_album.inc.php';

/**
 * Which photos deleting albums with "keep photos" leaves in no album. Core's
 * delete_categories() deletes a photo whose storage album is among the deleted
 * ones, so such a photo is gone, not orphaned; the expectations follow that.
 *
 * Technique notes: state transition not applicable, no function keeps state.
 */
final class AlbumDeleteLeftTest extends TestCase
{
    /** [BVA][NEG] The only album is deleted: the photo is named. */
    public function testThePhotoInTheDeletedAlbumOnlyIsNamed(): void
    {
        $this->assertSame(array(7), galleryrules_left_by_album_delete(
            array(7 => array('albums' => array(3), 'storage' => null)), array(3)));
    }

    /** [HAPPY] A second album outside the deleted ones keeps the photo. */
    public function testAnAlbumOutsideTheDeletedOnesKeepsThePhoto(): void
    {
        $this->assertSame(array(), galleryrules_left_by_album_delete(
            array(7 => array('albums' => array(3, 5), 'storage' => null)), array(3)));
    }

    /** [ECP] Every album of the photo is among the deleted ones (parent and sub-album). */
    public function testAllAlbumsDeletedNamesThePhoto(): void
    {
        $this->assertSame(array(7), galleryrules_left_by_album_delete(
            array(7 => array('albums' => array(3, 4), 'storage' => null)), array(3, 4)));
    }

    /** [ECP] A photo stored in a deleted album is deleted by core, not left. */
    public function testAPhotoStoredInADeletedAlbumIsNotLeftInNone(): void
    {
        $this->assertSame(array(), galleryrules_left_by_album_delete(
            array(7 => array('albums' => array(3), 'storage' => 3)), array(3)));
    }

    /** [BVA] A photo stored elsewhere is still named. */
    public function testAPhotoStoredOutsideTheDeletedAlbumsIsNamed(): void
    {
        $this->assertSame(array(7), galleryrules_left_by_album_delete(
            array(7 => array('albums' => array(3), 'storage' => 9)), array(3)));
    }

    /** [NEG] A photo in no album already is not this delete's doing. */
    public function testAPhotoInNoAlbumBeforeIsNotNamed(): void
    {
        $this->assertSame(array(), galleryrules_left_by_album_delete(
            array(7 => array('albums' => array(), 'storage' => null)), array(3)));
    }

    /** [ECP] Several photos are named in ascending order. */
    public function testSeveralPhotosAreNamedAscending(): void
    {
        $this->assertSame(array(2, 9), galleryrules_left_by_album_delete(array(
            9 => array('albums' => array(3), 'storage' => null),
            4 => array('albums' => array(3, 6), 'storage' => null),
            2 => array('albums' => array(3), 'storage' => null),
            ), array(3)));
    }
}
