<?php
/**
 * The test accounts the suites log in as.
 *
 * Roles, and why each exists:
 *   webmaster - the highest status, the E2E suite's default session
 *   admin     - an administrator who is not a webmaster: proves a gate is
 *               is_admin() and not is_webmaster()
 *   normal    - an authenticated non-admin, who edits a photo's date, info
 *               text and tags but not on core's screens
 *   generic   - the status core gives ACCESS_GUEST: the plugin admits it like
 *               normal through !is_a_guest(), which is_classic_user() would not
 *
 * The `guest` account ships with Piwigo and is not created here.
 */
class TestUsers
{
    public const WEBMASTER = 'photoinfo_webmaster';
    public const ADMIN = 'photoinfo_admin';
    public const NORMAL = 'photoinfo_normal';
    public const GENERIC = 'photoinfo_generic';

    /** username => Piwigo status */
    public const ROLES = array(
        self::WEBMASTER => 'webmaster',
        self::ADMIN     => 'admin',
        self::NORMAL    => 'normal',
        self::GENERIC   => 'generic',
        );

    /**
     * Every account's language: the admin pages a suite reads show photoinfo's
     * German messages, and the oracle is read from the de_DE language file.
     */
    public const LANGUAGE = 'de_DE';

    /** Written by create-test-users.php, read by Config, git-ignored under local/. */
    public const ENV_FILE = 'local/config/photoinfo-test.env';

    /** Environment variable pair for a role, as (username var, password var). */
    public static function envVars(string $role): array
    {
        $suffix = strtoupper(str_replace('photoinfo_', '', $role));
        return array(
            'PHOTOINFO_TEST_' . $suffix . '_USERNAME',
            'PHOTOINFO_TEST_' . $suffix . '_PASSWORD',
            );
    }
}
