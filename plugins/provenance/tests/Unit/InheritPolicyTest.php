<?php
use PHPUnit\Framework\TestCase;

/**
 * Who decides how an inheritance runs (decision 0060).
 *
 * check_lounge() empties a lounge that waited too long from any page view,
 * before include/user.inc.php has loaded $user. An inheritance arriving with no
 * user loaded therefore runs in keep, whatever the request says, and writes no
 * history row. With a user loaded the request's mode applies and history is
 * written, as before.
 *
 * Decision table, two conditions:
 *
 *   user loaded | request mode     | mode            | history
 *   no          | any / none       | keep            | no
 *   yes         | valid mode       | that mode       | yes
 *   yes         | none / unusable  | keep            | yes
 */
final class InheritPolicyTest extends TestCase
{
    /** core's include/common.inc.php sets $user = array() before check_lounge(). */
    private const USER_NOT_LOADED = array();

    private const LOADED_USER = array('id' => 1, 'status' => 'webmaster');

    /** [DT] [NEG] No user loaded: replace and clear in the request are ignored. */
    public function testWithNoUserLoadedEveryRequestedModeBecomesKeepWithoutHistory(): void
    {
        $this->assertNotEmpty(provenance_move_modes(), 'anti-vacuity: an empty list would assert nothing');

        foreach (provenance_move_modes() as $mode)
        {
            $this->assertSame(
                array('mode' => PROVENANCE_MODE_KEEP, 'history' => false),
                provenance_inherit_policy(self::USER_NOT_LOADED, array(PROVENANCE_MOVE_MODE_PARAM => $mode)),
                "requested mode: $mode"
            );
        }
    }

    /** [DT] No user loaded and no parameter: still keep without history. */
    public function testWithNoUserLoadedAndNoParameterTheResultIsKeepWithoutHistory(): void
    {
        $this->assertSame(
            array('mode' => PROVENANCE_MODE_KEEP, 'history' => false),
            provenance_inherit_policy(self::USER_NOT_LOADED, array())
        );
    }

    /** [ECP] An uninitialised global is not a loaded user either. */
    public function testANullUserIsNotLoaded(): void
    {
        $this->assertSame(
            array('mode' => PROVENANCE_MODE_KEEP, 'history' => false),
            provenance_inherit_policy(null, array(PROVENANCE_MOVE_MODE_PARAM => PROVENANCE_MODE_REPLACE))
        );
    }

    /** [DT] [HAPPY] A loaded user gets the requested mode, with history. */
    public function testWithAUserLoadedTheRequestedModeAppliesWithHistory(): void
    {
        foreach (array(PROVENANCE_MODE_REPLACE, PROVENANCE_MODE_CLEAR) as $mode)
        {
            $this->assertSame(
                array('mode' => $mode, 'history' => true),
                provenance_inherit_policy(self::LOADED_USER, array(PROVENANCE_MOVE_MODE_PARAM => $mode)),
                "requested mode: $mode"
            );
        }
    }

    /** [DT] A loaded user with no parameter gets keep, still with history. */
    public function testWithAUserLoadedAndNoParameterTheResultIsKeepWithHistory(): void
    {
        $this->assertSame(
            array('mode' => PROVENANCE_MODE_KEEP, 'history' => true),
            provenance_inherit_policy(self::LOADED_USER, array())
        );
    }

    /** [ECP] A guest's $user carries an id too, so a guest page view after loading counts as loaded. */
    public function testAnyUserIdCountsAsLoaded(): void
    {
        $this->assertTrue(provenance_inherit_policy(array('id' => 2), array())['history']);
    }
}
