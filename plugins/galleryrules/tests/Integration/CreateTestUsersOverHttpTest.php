<?php
use PHPUnit\Framework\TestCase;

/**
 * create-test-users.php sits under the web root. Requested over HTTP it must
 * refuse before touching anything: run, it would reset the test accounts'
 * passwords and rewrite the credentials file.
 */
final class CreateTestUsersOverHttpTest extends TestCase
{
    private const SCRIPT = '/plugins/galleryrules/tests/Support/create-test-users.php';
    private const REFUSED = 403;

    /** [NEG] A guest's request is refused and changes no account and no credentials file. */
    public function testARequestOverHttpChangesNoAccount(): void
    {
        $db = new Db();
        FixtureBuilder::assertThrowawayInstall($db);
        $before = $this->passwordHashes($db);
        $this->assertCount(count(TestUsers::ROLES), $before);
        $envBefore = (string)file_get_contents(PIWIGO_ROOT . TestUsers::ENV_FILE);
        $this->assertNotSame('', $envBefore);

        $res = (new WsClient())->get(self::SCRIPT);

        $this->assertSame(self::REFUSED, $res['status']);
        $this->assertSame($before, $this->passwordHashes(new Db()));
        $this->assertSame($envBefore, (string)file_get_contents(PIWIGO_ROOT . TestUsers::ENV_FILE));
    }

    /** @return array username => password hash */
    private function passwordHashes(Db $db): array
    {
        $names = array();
        foreach (array_keys(TestUsers::ROLES) as $username)
        {
            $names[] = "'" . $db->escape($username) . "'";
        }

        $hashes = array();
        $result = $db->query('SELECT username, password FROM piwigo_users WHERE username IN (' . implode(', ', $names) . ') ORDER BY username');
        while ($row = $result->fetch_assoc())
        {
            $hashes[$row['username']] = $row['password'];
        }
        return $hashes;
    }
}
