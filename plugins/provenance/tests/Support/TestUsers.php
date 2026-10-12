<?php
/**
 * The test accounts the suites log in as, and the one place that creates them.
 *
 * Tests never use a human's real login: a suite that needs a webmaster gets a
 * dedicated account with a generated password, so nobody has to hand over their
 * own credentials and revoking test access is one script run.
 *
 * Roles, and why each exists:
 *   webmaster - pwg.plugins.performAction and the plugin's admin_only methods
 *   admin     - an administrator who is not a webmaster: proves a gate is
 *               is_admin() and not is_webmaster() (decision 0066)
 *   normal    - the [NEG] side of every admin gate: an authenticated non-admin
 *   generic   - a shared login core treats like a guest in some places: proves
 *               a gate admitting every logged-in account is !is_a_guest() and
 *               not is_classic_user()
 *
 * The `guest` account ships with Piwigo and is not created here.
 */
class TestUsers
{
    /** username => Piwigo status */
    public const ROLES = array(
        'provenance_webmaster' => 'webmaster',
        'provenance_admin'     => 'admin',
        'provenance_normal'    => 'normal',
        'provenance_generic'   => 'generic',
        );

    /** Written by create-test-users.php, read by Config, git-ignored under local/. */
    public const ENV_FILE = 'local/config/provenance-test.env';

    /** Environment variable pair for a role, as (username var, password var). */
    public static function envVars(string $role): array
    {
        $suffix = strtoupper(str_replace('provenance_', '', $role));
        return array(
            'PROVENANCE_TEST_' . $suffix . '_USERNAME',
            'PROVENANCE_TEST_' . $suffix . '_PASSWORD',
            );
    }
}
