<?php
/**
 * Environment-driven test configuration. No hardcoded credentials - every value
 * is either a safe, documented DDEV default (host/DB) or must come from the
 * environment (login credentials), failing fast with a message naming the
 * missing piece rather than silently trying a guessed value.
 *
 * Deliberately a copy of plugins/typetags/tests/Support/Config.php rather than a
 * shared file: typetags is a git submodule tracking upstream and must not become
 * a dependency of core plugin code.
 */
class Config
{
    public static function baseUrl(): string
    {
        return getenv('PERSONS_TEST_BASE_URL') ?: 'http://localhost';
    }

    public static function dbHost(): string
    {
        return getenv('PERSONS_TEST_DB_HOST') ?: 'db';
    }

    public static function dbUser(): string
    {
        return getenv('PERSONS_TEST_DB_USER') ?: 'db';
    }

    public static function dbPassword(): string
    {
        return getenv('PERSONS_TEST_DB_PASSWORD') ?: 'db';
    }

    public static function dbName(): string
    {
        return getenv('PERSONS_TEST_DB_NAME') ?: 'db';
    }

    /** The webmaster account the admin-gated suites log in as. */
    public static function username(): string
    {
        return self::required(TestUsers::envVars('persons_webmaster')[0]);
    }

    public static function password(): string
    {
        return self::required(TestUsers::envVars('persons_webmaster')[1]);
    }

    /** An authenticated non-admin, for the [NEG] side of every admin gate. */
    public static function normalUsername(): string
    {
        return self::required(TestUsers::envVars('persons_normal')[0]);
    }

    public static function normalPassword(): string
    {
        return self::required(TestUsers::envVars('persons_normal')[1]);
    }

    private static function required(string $envVar): string
    {
        $value = getenv($envVar);
        if ($value === false || $value === '')
        {
            throw new RuntimeException(
                "Missing required environment variable $envVar. " .
                'Run `ddev exec php plugins/persons/tests/Support/create-test-users.php` to create ' .
                'the test accounts, then source ' . TestUsers::ENV_FILE . ' before running the suite.'
            );
        }
        return $value;
    }
}
