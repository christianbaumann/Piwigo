<?php
/**
 * The test accounts the suites log in as.
 *
 * Roles, and why each exists (every logged-in account that sees a photo may
 * edit it, decision 0059):
 *   webmaster - the top status
 *   admin     - an administrator who is not a webmaster: proves the gate is
 *               not is_webmaster()
 *   normal    - an authenticated non-admin: proves the gate is not is_admin()
 *   generic   - core's shared-account status, below normal: proves the gate
 *               is !is_a_guest() and not is_classic_user()
 *
 * The `guest` account ships with Piwigo and is not created here.
 */
class TestUsers
{
    public const WEBMASTER = 'photoedit_webmaster';
    public const ADMIN = 'photoedit_admin';
    public const NORMAL = 'photoedit_normal';
    public const GENERIC = 'photoedit_generic';

    /** username => Piwigo status */
    public const ROLES = array(
        self::WEBMASTER => 'webmaster',
        self::ADMIN     => 'admin',
        self::NORMAL    => 'normal',
        self::GENERIC   => 'generic',
        );

    /** Written by create-test-users.php, read by Config, git-ignored under local/. */
    public const ENV_FILE = 'local/config/photoedit-test.env';

    /** Environment variable pair for a role, as (username var, password var). */
    public static function envVars(string $role): array
    {
        $suffix = strtoupper(str_replace('photoedit_', '', $role));
        return array(
            'PHOTOEDIT_TEST_' . $suffix . '_USERNAME',
            'PHOTOEDIT_TEST_' . $suffix . '_PASSWORD',
            );
    }
}
