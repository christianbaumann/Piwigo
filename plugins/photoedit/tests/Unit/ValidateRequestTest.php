<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * photoedit_validate_request(): which edits a request may ask for.
 *
 * Decision table not applicable beyond the "nothing to do" case: turns and crop
 * are checked independently, and only their combination 0 + no crop is refused.
 */
final class ValidateRequestTest extends TestCase
{
    public static function validTurns(): array
    {
        return array(
            '[BVA] lowest turn' => array('1', 1),
            '[ECP] half turn' => array('2', 2),
            '[BVA] highest turn' => array('3', 3),
            '[ECP] an integer, not a string' => array(1, 1),
            );
    }

    #[DataProvider('validTurns')]
    public function testTurnsFromOneToThreeAreAccepted(mixed $turns, int $expected): void
    {
        $result = photoedit_validate_request($turns, '');

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame($expected, $result['turns']);
        $this->assertNull($result['crop']);
    }

    public static function invalidTurns(): array
    {
        return array(
            '[BVA] below the range' => array('-1'),
            '[BVA] above the range' => array('4'),
            '[NEG] a fraction' => array('1.5'),
            '[NEG] not a number' => array('right'),
            '[NEG] empty' => array(''),
            '[NEG] an array' => array(array(1)),
            );
    }

    #[DataProvider('invalidTurns')]
    public function testTurnsOutsideTheRangeAreRefused(mixed $turns): void
    {
        $result = photoedit_validate_request($turns, '');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('turns', $result['error']);
    }

    /** [BVA] Zero turns and no crop leaves nothing to write. */
    public function testNoTurnAndNoCropIsNothingToDo(): void
    {
        $result = photoedit_validate_request('0', '');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('nothing to do', $result['error']);
    }

    /** [HAPPY] A crop alone is an edit; its fractions come back as a box. */
    public function testACropWithoutATurnIsAccepted(): void
    {
        $result = photoedit_validate_request('0', '0.1, 0.2,0.9,1');

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame(0, $result['turns']);
        $this->assertSame(array('l' => 0.1, 't' => 0.2, 'r' => 0.9, 'b' => 1.0), $result['crop']);
    }

    public static function invalidCrops(): array
    {
        return array(
            '[NEG] three values' => array('0,0,1'),
            '[NEG] five values' => array('0,0,1,1,1'),
            '[BVA] below zero' => array('-0.01,0,1,1'),
            '[BVA] above one' => array('0,0,1.01,1'),
            '[BVA] zero width' => array('0.5,0,0.5,1'),
            '[BVA] zero height' => array('0,0.5,1,0.5'),
            '[NEG] reversed' => array('1,1,0,0'),
            '[NEG] not a number' => array('a,0,1,1'),
            '[NEG] an array' => array(array('0', '0', '1', '1')),
            );
    }

    #[DataProvider('invalidCrops')]
    public function testMalformedCropsAreRefused(mixed $crop): void
    {
        $result = photoedit_validate_request('1', $crop);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('crop', $result['error']);
    }
}
