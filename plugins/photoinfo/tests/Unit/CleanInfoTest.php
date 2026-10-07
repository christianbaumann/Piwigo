<?php
use PHPUnit\Framework\TestCase;

/**
 * The info text is cleaned like core cleans a description on its photo edit
 * screen (admin/picture_modify.php): markup only where HTML descriptions are
 * allowed. Requirement: design "Info text is core's comment".
 */
final class CleanInfoTest extends TestCase
{
    /** [DT] Markup is kept when the install allows HTML descriptions. */
    public function testMarkupIsKeptWhenAllowed(): void
    {
        $this->assertSame('<b>Oma</b> & Opa', photoinfo_clean_info('<b>Oma</b> & Opa', true));
    }

    /** [DT] Markup is stripped when it is not allowed. */
    public function testMarkupIsStrippedWhenNotAllowed(): void
    {
        $this->assertSame('Oma & Opa', photoinfo_clean_info('<b>Oma</b> & Opa', false));
    }

    /** [ECP] Surrounding white space goes, line breaks inside stay. */
    public function testOuterWhiteSpaceIsTrimmed(): void
    {
        $this->assertSame("a\n\nb", photoinfo_clean_info("  a\n\nb \n", true));
        $this->assertSame('', photoinfo_clean_info(" \n ", false));
    }
}
