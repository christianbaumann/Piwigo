<?php
use PHPUnit\Framework\TestCase;

require_once GALLERYRULES_PATH . 'include/replace.inc.php';

/**
 * The replace option of Fotos hinzufügen never overwrites a photo it had to
 * guess: more than one photo with the file name in the album is a refusal that
 * names them (design: "Replace refuses when a file name is ambiguous").
 *
 * Technique notes: decision table not applicable, one condition (how many
 * photos match); state transition not applicable, the function keeps no state.
 */
final class ReplaceAmbiguityTest extends TestCase
{
    /** [BVA] No photo has the name: nothing to refuse, core adds the photo. */
    public function testNoMatchIsNotAmbiguous(): void
    {
        $this->assertNull(galleryrules_replace_ambiguity(array()));
    }

    /** [BVA][HAPPY] One photo has the name: core replaces it. */
    public function testOneMatchIsNotAmbiguous(): void
    {
        $this->assertNull(galleryrules_replace_ambiguity(array(7)));
    }

    /** [BVA][NEG] Two photos have the name: refused, both named. */
    public function testTwoMatchesAreNamed(): void
    {
        $this->assertSame('#3, #7', galleryrules_replace_ambiguity(array(3, 7)));
    }

    /** [ECP] Many matches are named in id order, whatever order the database gave. */
    public function testManyMatchesAreNamedInIdOrder(): void
    {
        $this->assertSame('#3, #7, #12', galleryrules_replace_ambiguity(array('12', '3', '7')));
    }

    /** [ERR] A photo listed twice, as a join can return it, is one photo. */
    public function testADuplicateRowIsOnePhoto(): void
    {
        $this->assertNull(galleryrules_replace_ambiguity(array(7, '7')));
    }
}
