<?php
use PHPUnit\Framework\TestCase;

/**
 * The plugin deactivates and activates through core's own plugin action, as
 * Administration > Plugins and the deploy do.
 *
 * Deactivates the plugin for the one case and reactivates it in tearDown. A run
 * killed in between leaves it off; reactivate it by hand.
 */
final class PluginActivationTest extends TestCase
{
    private WsClient $ws;
    private FixtureBuilder $fixture;

    protected function setUp(): void
    {
        $this->fixture = new FixtureBuilder(new Db());
        $this->fixture->assertPluginActive();

        $this->ws = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->ws->login($username, $password);
    }

    protected function tearDown(): void
    {
        $this->perform('activate');
        $this->fixture->assertPluginActive();
    }

    /** [ST] active -> inactive -> active. */
    public function testThePluginDeactivatesAndActivatesAgain(): void
    {
        $this->assertSame('ok', $this->perform('deactivate')['stat']);
        $this->assertSame('inactive', $this->stateOf());

        $this->assertSame('ok', $this->perform('activate')['stat']);
        $this->assertSame('active', $this->stateOf());
    }

    private function perform(string $action): array
    {
        $res = $this->ws->call('pwg.plugins.performAction', array(
            'action' => $action,
            'plugin' => 'galleryrules',
            'pwg_token' => $this->ws->token(),
        ));
        $this->assertIsArray($res['json'], $res['body']);

        return $res['json'];
    }

    private function stateOf(): string
    {
        return (string)(new Db())->scalar("SELECT state FROM piwigo_plugins WHERE id = 'galleryrules'");
    }
}
