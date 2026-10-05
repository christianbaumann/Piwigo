<?php
/**
 * The test accounts the suites log in as.
 *
 * Roles, and why each exists:
 *   webmaster - the only status that may edit a photo
 *   admin     - an administrator who is not a webmaster: the [NEG] case that
 *               proves the gate is is_webmaster() and not is_admin()
 *   normal    - an authenticated non-admin
 *
 * The `guest` account ships with Piwigo and is not created here.
 */
class TestUsers
{
    public const WEBMASTER = 'photoedit_webmaster';
    public const ADMIN = 'photoedit_admin';
    public const NORMAL = 'photoedit_normal';

    /** username => Piwigo status */
    public const ROLES = array(
        self::WEBMASTER => 'webmaster',
        self::ADMIN     => 'admin',
        self::NORMAL    => 'normal',
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
