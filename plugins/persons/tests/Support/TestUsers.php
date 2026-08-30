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
 *   normal    - the [NEG] side of every admin gate: an authenticated non-admin
 *
 * The `guest` account ships with Piwigo and is not created here.
 */
class TestUsers
{
    /** username => Piwigo status */
    public const ROLES = array(
        'persons_webmaster' => 'webmaster',
        'persons_normal'    => 'normal',
        );

    /** Written by create-test-users.php, read by Config, git-ignored under local/. */
    public const ENV_FILE = 'local/config/persons-test.env';

    /** Environment variable pair for a role, as (username var, password var). */
    public static function envVars(string $role): array
    {
        $suffix = strtoupper(str_replace('persons_', '', $role));
        return array(
            'PERSONS_TEST_' . $suffix . '_USERNAME',
            'PERSONS_TEST_' . $suffix . '_PASSWORD',
            );
    }
}
