<?php
use PHPUnit\Framework\TestCase;

/**
 * photoinfo writes through provenance's runner and lock, so it refuses to
 * activate without it. Requirement: design "photoinfo requires provenance and
 * reuses its writer".
 *
 * Deactivates both plugins for the one case and reactivates them in tearDown.
 * A run killed in between leaves them off; reactivate them by hand.
 */
final class PluginActivationTest extends TestCase
{
    private WsClient $ws;
    private FixtureBuilder $fixture;

    protected function setUp(): void
    {
        $this->fixture = new FixtureBuilder(new Db());
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');

        $this->ws = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->ws->login($username, $password);
    }

    protected function tearDown(): void
    {
        $this->perform('activate', 'provenance');
        $this->perform('activate', 'photoinfo');
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');
    }

    /** [NEG] Without provenance, activation fails with a message naming it. */
    public function testActivationWithoutProvenanceFailsNamingIt(): void
    {
        $this->assertSame('ok', $this->perform('deactivate', 'photoinfo')['stat']);
        $this->assertSame('ok', $this->perform('deactivate', 'provenance')['stat']);

        $res = $this->perform('activate', 'photoinfo');

        $this->assertSame('fail', $res['stat']);
        $this->assertStringContainsString('Provenance', implode(' ', (array)$res['message']));
        $this->assertSame('inactive', $this->stateOf('photoinfo'));
    }

    /** [HAPPY] With provenance active, activation succeeds. */
    public function testActivationWithProvenanceSucceeds(): void
    {
        $this->assertSame('ok', $this->perform('deactivate', 'photoinfo')['stat']);

        $this->assertSame('ok', $this->perform('activate', 'photoinfo')['stat']);
        $this->assertSame('active', $this->stateOf('photoinfo'));
    }

    private function perform(string $action, string $plugin): array
    {
        $res = $this->ws->call('pwg.plugins.performAction', array(
            'action' => $action,
            'plugin' => $plugin,
            'pwg_token' => $this->ws->token(),
        ));

        // Core answers a refused activation with a PHP warning ahead of the JSON
        // (PwgError gets the error list as an array); the JSON is the answer.
        $json = json_decode(substr($res['body'], (int)strpos($res['body'], '{"stat"')), true);
        $this->assertIsArray($json, $res['body']);

        return $json;
    }

    private function stateOf(string $plugin): string
    {
        return (string)(new Db())->scalar("SELECT state FROM piwigo_plugins WHERE id = '" . $plugin . "'");
    }
}
