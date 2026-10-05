<?php
/**
 * Environment-driven test configuration. No hardcoded credentials - every value
 * is either a safe, documented DDEV default (host/DB) or must come from the
 * environment (login credentials), failing fast with a message naming the
 * missing piece rather than silently trying a guessed value.
 *
 * A copy of plugins/persons/tests/Support/Config.php rather than a shared file:
 * each plugin's suite stands on its own.
 */
class Config
{
    public static function baseUrl(): string
    {
        return getenv('PHOTOEDIT_TEST_BASE_URL') ?: 'http://localhost';
    }

    public static function dbHost(): string
    {
        return getenv('PHOTOEDIT_TEST_DB_HOST') ?: 'db';
    }

    public static function dbUser(): string
    {
        return getenv('PHOTOEDIT_TEST_DB_USER') ?: 'db';
    }

    public static function dbPassword(): string
    {
        return getenv('PHOTOEDIT_TEST_DB_PASSWORD') ?: 'db';
    }

    public static function dbName(): string
    {
        return getenv('PHOTOEDIT_TEST_DB_NAME') ?: 'db';
    }

    /**
     * Username and password of one TestUsers role.
     *
     * @return array username, password
     */
    public static function credentials(string $role): array
    {
        list($userVar, $passVar) = TestUsers::envVars($role);
        return array(self::required($userVar), self::required($passVar));
    }

    private static function required(string $envVar): string
    {
        $value = getenv($envVar);
        if ($value === false || $value === '')
        {
            throw new RuntimeException(
                "Missing required environment variable $envVar. " .
                'Run `ddev exec php plugins/photoedit/tests/Support/create-test-users.php` to create ' .
                'the test accounts, then source ' . TestUsers::ENV_FILE . ' before running the suite.'
            );
        }
        return $value;
    }
}
