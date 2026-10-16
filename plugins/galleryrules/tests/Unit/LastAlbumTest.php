<?php
use PHPUnit\Framework\TestCase;

require_once GALLERYRULES_PATH . 'include/last_album.inc.php';

/**
 * A photo's last album cannot be removed (design: "A photo's last album cannot
 * be removed"). These functions say which albums a photo keeps after each of
 * core's removing paths; the expected values of the storage-album cases follow
 * core's own rule that a photo's storage link is never broken by dissociate or
 * move (dissociate_images_from_category(), move_images_to_categories()).
 *
 * Technique notes: state transition not applicable, no function keeps state.
 */
final class LastAlbumTest extends TestCase
{
    /** [HAPPY] Taken out of one of two albums: the other stays. */
    public function testDissociateKeepsTheOtherAlbum(): void
    {
        $this->assertSame(array(5), galleryrules_albums_after_dissociate(array(3, 5), null, 3));
    }

    /** [BVA][NEG] Taken out of its only album: none left. */
    public function testDissociateFromTheOnlyAlbumLeavesNone(): void
    {
        $this->assertSame(array(), galleryrules_albums_after_dissociate(array(3), null, 3));
    }

    /** [ECP] An album the photo is not in changes nothing. */
    public function testDissociateFromAnotherAlbumChangesNothing(): void
    {
        $this->assertSame(array(3), galleryrules_albums_after_dissociate(array(3), null, 9));
    }

    /** [DT] The storage album is never dissociated, as core keeps that link. */
    public function testDissociateKeepsTheStorageAlbum(): void
    {
        $this->assertSame(array(3), galleryrules_albums_after_dissociate(array(3), 3, '3'));
    }

    /** [DT] Another album than the storage one is dissociated as usual. */
    public function testDissociateWithAStorageAlbumRemovesTheOther(): void
    {
        $this->assertSame(array(3), galleryrules_albums_after_dissociate(array(3, 7), 3, 7));
    }

    /** [HAPPY] Moved: the targets only. */
    public function testMoveGivesTheTargets(): void
    {
        $this->assertSame(array(4, 8), galleryrules_albums_after_move(array(3), null, array('8', '4')));
    }

    /** [BVA][NEG] Moved to no album: none left. */
    public function testMoveToNothingLeavesNone(): void
    {
        $this->assertSame(array(), galleryrules_albums_after_move(array(3, 5), null, array()));
    }

    /** [DT] The storage album stays when the photo is in it. */
    public function testMoveKeepsTheStorageAlbumItIsIn(): void
    {
        $this->assertSame(array(3), galleryrules_albums_after_move(array(3, 5), 3, array()));
    }

    /** [DT][ERR] A storage album the photo is no longer in is not brought back. */
    public function testMoveDoesNotAddAStorageAlbumItIsNotIn(): void
    {
        $this->assertSame(array(), galleryrules_albums_after_move(array(5), 3, array()));
    }

    /** [HAPPY] "id,rank;id" gives the ids. */
    public function testCategoryStringGivesItsIds(): void
    {
        $this->assertSame(array(4, 12), galleryrules_category_string_ids('12,3;4'));
    }

    /** [BVA] An empty string, and null, give none. */
    public function testEmptyCategoryStringGivesNone(): void
    {
        $this->assertSame(array(), galleryrules_category_string_ids(''));
        $this->assertSame(array(), galleryrules_category_string_ids(null));
    }

    /** [NEG] Tokens whose id is not a number are skipped, as core skips them. */
    public function testNonNumericTokensAreSkipped(): void
    {
        $this->assertSame(array(), galleryrules_category_string_ids('abc;;x,1'));
        $this->assertSame(array(7), galleryrules_category_string_ids('abc;7,auto'));
    }

    /** [NEG][BVA] In one album before, in none after: left without one. */
    public function testAPhotoLosingItsLastAlbumIsNamed(): void
    {
        $this->assertSame(array(7), galleryrules_left_without_album(array(7 => array(3)), array(7 => array())));
    }

    /** [HAPPY][BVA] In one album after: not named. */
    public function testAPhotoKeepingOneAlbumIsNotNamed(): void
    {
        $this->assertSame(array(), galleryrules_left_without_album(array(7 => array(3, 5)), array(7 => array(5))));
    }

    /** [ECP] A photo in no album before is not the change's doing. */
    public function testAPhotoInNoAlbumBeforeIsNotNamed(): void
    {
        $this->assertSame(array(), galleryrules_left_without_album(array(7 => array()), array(7 => array())));
    }

    /** [ECP] Several photos: only those left in none, ascending. */
    public function testOnlyThePhotosLeftInNoneAreNamedInOrder(): void
    {
        $this->assertSame(
            array(2, 9),
            galleryrules_left_without_album(
                array(9 => array(1), 4 => array(1, 2), 2 => array(1)),
                array(9 => array(), 4 => array(2))
            )
        );
    }
}
