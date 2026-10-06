<?php
use PHPUnit\Framework\TestCase;

require_once PIWIGO_ROOT . 'include/functions_category.inc.php';

/**
 * discount_hidden_subalbums(): an album thumbnail counts only the sub-albums the
 * listing will actually show.
 *
 * Requirement: the "N albums" on a thumbnail equals the number of album thumbnails
 * shown after opening it. The listing hides albums without photos
 * (include/category_cats.inc.php), while an administrator's user cache keeps them
 * (include/functions_user.inc.php, feature 1053).
 *
 * Techniques per .claude/rules/test-design.md. Decision table not applicable: the
 * outcome per hidden album depends on one relation (child / deeper descendant /
 * unrelated), covered as equivalence classes.
 */
final class CoreHiddenSubalbumCountTest extends TestCase
{
    /** Album 1 > album 2, with the counters an admin's cache gives it. */
    private const CATEGORY = array(
        'id' => 2,
        'uppercats' => '1,2',
        'nb_categories' => 3,
        'count_categories' => 5,
        );

    /** [HAPPY] An empty direct child is neither a listed album nor a sub-album. */
    public function testAHiddenChildIsSubtractedFromBothCounters(): void
    {
        $result = discount_hidden_subalbums(self::CATEGORY, array(
            array('id_uppercat' => 2, 'uppercats' => '1,2,7'),
            ));

        $this->assertSame(2, $result['nb_categories']);
        $this->assertSame(4, $result['count_categories']);
    }

    /** [ECP] A hidden grandchild is a sub-album, but not one of the listed children. */
    public function testAHiddenGrandchildIsSubtractedFromTheRecursiveCounterOnly(): void
    {
        $result = discount_hidden_subalbums(self::CATEGORY, array(
            array('id_uppercat' => 7, 'uppercats' => '1,2,7,9'),
            ));

        $this->assertSame(3, $result['nb_categories']);
        $this->assertSame(4, $result['count_categories']);
    }

    /** [ECP] A hidden subtree: every album in it is hidden, so each one is subtracted. */
    public function testAHiddenChildWithAHiddenChildOfItsOwnIsSubtractedTwice(): void
    {
        $result = discount_hidden_subalbums(self::CATEGORY, array(
            array('id_uppercat' => 2, 'uppercats' => '1,2,7'),
            array('id_uppercat' => 7, 'uppercats' => '1,2,7,9'),
            ));

        $this->assertSame(2, $result['nb_categories']);
        $this->assertSame(3, $result['count_categories']);
    }

    /** [BVA] uppercats "1,23,…" starts with "1,2" as a string, but album 23 is not inside album 2. */
    public function testAnAlbumWhoseIdMerelyStartsWithTheSameDigitsIsNotADescendant(): void
    {
        $result = discount_hidden_subalbums(self::CATEGORY, array(
            array('id_uppercat' => 23, 'uppercats' => '1,23,24'),
            array('id_uppercat' => 1, 'uppercats' => '1,23'),
            ));

        $this->assertSame(3, $result['nb_categories']);
        $this->assertSame(5, $result['count_categories']);
    }

    /** [BVA] The album itself is not its own sub-album. */
    public function testTheAlbumItselfIsNotCounted(): void
    {
        $result = discount_hidden_subalbums(self::CATEGORY, array(
            array('id_uppercat' => 1, 'uppercats' => '1,2'),
            ));

        $this->assertSame(3, $result['nb_categories']);
        $this->assertSame(5, $result['count_categories']);
    }

    /** [NEG] Nothing hidden - a normal user's case - leaves the counters and the rest alone. */
    public function testNoHiddenAlbumsChangesNothing(): void
    {
        $this->assertSame(self::CATEGORY, discount_hidden_subalbums(self::CATEGORY, array()));
    }
}
