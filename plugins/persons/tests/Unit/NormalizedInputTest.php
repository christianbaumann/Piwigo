<?php
use PHPUnit\Framework\TestCase;

/**
 * The boundary check every coordinate arriving over the web service passes
 * through. Everything downstream of it casts to float and trusts the value.
 */
final class NormalizedInputTest extends TestCase
{
    /** [HAPPY] An ordinary coordinate is accepted. */
    public function testAMidRangeValueIsValid(): void
    {
        $this->assertTrue(persons_is_valid_normalized(0.5));
    }

    /** [BVA] Both ends of the range are inside it. */
    public function testTheRangeEndsAreValid(): void
    {
        $this->assertTrue(persons_is_valid_normalized(0));
        $this->assertTrue(persons_is_valid_normalized(1));
    }

    /** [BVA] [NEG] One step outside either end is not. */
    public function testJustOutsideTheRangeIsInvalid(): void
    {
        $this->assertFalse(persons_is_valid_normalized(-0.000001));
        $this->assertFalse(persons_is_valid_normalized(1.000001));
    }

    /**
     * [ERR] A coordinate arrives as a query-string string, so a numeric string
     * is the normal case, not the exception. Immich PR #29333 is the same bug
     * seen from the parsing side.
     */
    public function testANumericStringIsValid(): void
    {
        $this->assertTrue(persons_is_valid_normalized('0.25'));
    }

    /** [NEG] Anything that is not a number at all is refused. */
    public function testNonNumericInputIsInvalid(): void
    {
        $this->assertFalse(persons_is_valid_normalized('abc'));
        $this->assertFalse(persons_is_valid_normalized(''));
        $this->assertFalse(persons_is_valid_normalized(null));
        $this->assertFalse(persons_is_valid_normalized(array(0.5)));
        $this->assertFalse(persons_is_valid_normalized(true));
    }

    /** [NEG] INF and NAN are numeric floats and would poison the math. */
    public function testNonFiniteValuesAreInvalid(): void
    {
        $this->assertFalse(persons_is_valid_normalized(INF));
        $this->assertFalse(persons_is_valid_normalized(NAN));
    }
}
