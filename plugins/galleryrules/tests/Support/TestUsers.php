<?php
/**
 * The test accounts the suites log in as.
 *
 * Roles, and why each exists:
 *   webmaster - the highest status, the E2E suite's default session
 *   admin     - an administrator who is not a webmaster: proves a gate is
 *               is_admin() and not is_webmaster()
 *   normal    - an authenticated non-admin
 *   generic   - the status core gives ACCESS_GUEST, which passes
 *               !is_a_guest() but not is_classic_user()
 *
 * The `guest` account ships with Piwigo and is not created here.
 */
class TestUsers
{
    public const WEBMASTER = 'galleryrules_webmaster';
    public const ADMIN = 'galleryrules_admin';
    public const NORMAL = 'galleryrules_normal';
    public const GENERIC = 'galleryrules_generic';

    /** username => Piwigo status */
    public const ROLES = array(
        self::WEBMASTER => 'webmaster',
        self::ADMIN     => 'admin',
        self::NORMAL    => 'normal',
        self::GENERIC   => 'generic',
        );

    /** Every account's language: the local install runs in German. */
    public const LANGUAGE = 'de_DE';

    /** Written by create-test-users.php, read by Config, git-ignored under local/. */
    public const ENV_FILE = 'local/config/galleryrules-test.env';

    /** Environment variable pair for a role, as (username var, password var). */
    public static function envVars(string $role): array
    {
        $suffix = strtoupper(str_replace('galleryrules_', '', $role));
        return array(
            'GALLERYRULES_TEST_' . $suffix . '_USERNAME',
            'GALLERYRULES_TEST_' . $suffix . '_PASSWORD',
            );
    }
}
