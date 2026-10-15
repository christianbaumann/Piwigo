<?php
use PHPUnit\Framework\TestCase;

/**
 * The test-user script runs from the command line only: opened in a browser it
 * would create accounts and rotate their passwords for whoever asked.
 */
final class CreateTestUsersOverHttpTest extends TestCase
{
    private const SCRIPT_PATH = '/plugins/photoedit/tests/Support/create-test-users.php';
    private const FORBIDDEN = 403;

    /** [NEG] A request over HTTP is refused and leaves every test account's row as it was. */
    public function testAnHttpRequestChangesNoAccount(): void
    {
        $db = new Db();
        $before = $this->accountRows($db);
        $this->assertCount(count(TestUsers::ROLES), $before, 'the test accounts must exist before the request');

        $ch = curl_init(Config::baseUrl() . self::SCRIPT_PATH);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $body = (string)curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame($before, $this->accountRows($db), 'the request changed a test account');
        $this->assertSame(self::FORBIDDEN, $httpCode);
        foreach (array_keys(TestUsers::ROLES) as $username)
        {
            $this->assertStringNotContainsString($username, $body);
        }
    }

    /** The columns the script writes, per test account. */
    private function accountRows(Db $db): array
    {
        $names = array();
        foreach (array_keys(TestUsers::ROLES) as $username)
        {
            $names[] = "'" . $db->escape($username) . "'";
        }
        $result = $db->query(
            'SELECT u.id, u.username, u.password, u.mail_address, i.status, i.level
             FROM piwigo_users u LEFT JOIN piwigo_user_infos i ON i.user_id = u.id
             WHERE u.username IN (' . implode(', ', $names) . ')
             ORDER BY u.username'
        );
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}
