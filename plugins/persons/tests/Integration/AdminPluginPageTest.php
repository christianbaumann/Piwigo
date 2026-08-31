<?php
use PHPUnit\Framework\TestCase;

/**
 * Closes the Phase 1 manual box "the plugin appears in Admin > Plugins and
 * activates without error".
 *
 * Both halves have an oracle over HTTP. The plugin list is a rendering of
 * piwigo_plugins joined with the header block parsed out of main.inc.php, so
 * fetching it asserts the header block is well formed - something no unit test
 * can see, because a malformed block yields a plugin that installs perfectly
 * and shows up nameless. And PHP diagnostics render inline into the response
 * body on this install, so the body is a direct oracle for "no notice".
 *
 * The diagnostic check is differential: it compares the pages with the plugin
 * active against the same pages with it deactivated, so pre-existing core noise
 * cannot fail the test and cannot hide a diagnostic the plugin introduces.
 */
final class AdminPluginPageTest extends TestCase
{
    private const PLUGIN_ID = 'persons';

    /** The `Plugin Name:` value in main.inc.php's header block. */
    private const PLUGIN_DISPLAY_NAME = 'Persons';

    /** A rendered page shorter than this is an error page or a redirect, not a listing. */
    private const MIN_PAGE_BYTES = 2000;

    private Db $db;
    private WsClient $ws;

    /** @var string[] resolved once per test, so ids are read rather than assumed */
    private array $pages;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
        $this->setPluginState('activate');

        // The picture page is on the differential from the start: it is where
        // the overlay lands in a later phase, and a baseline taken before any
        // injection exists is the only clean one available.
        $imageId = $this->db->scalar('SELECT MIN(id) FROM piwigo_images');
        if ($imageId === null)
        {
            throw new RuntimeException('This install has no photo, so the picture page cannot be fetched');
        }

        $this->pages = array(
            '/admin.php?page=plugins&tab=installed',
            '/admin.php',
            '/index.php',
            '/picture.php?/' . (int)$imageId,
            );
    }

    protected function tearDown(): void
    {
        $this->setPluginState('activate');
        $this->ws->logout();
    }

    /**
     * [HAPPY] The plugin is listed, by the name its header block declares.
     *
     * Piwigo parses that name out of a comment in the first 2048 bytes of
     * main.inc.php. A malformed block is invisible everywhere else.
     */
    public function testPluginIsListedOnTheAdminPluginsPage(): void
    {
        $res = $this->ws->fetchPage('/admin.php?page=plugins&tab=installed');

        $this->assertSame(200, $res['http_code']);
        $this->assertGreaterThan(
            self::MIN_PAGE_BYTES,
            strlen($res['body']),
            'anti-vacuity: a short body is an error page, on which every containment assertion below is meaningless'
        );

        $this->assertStringContainsString(self::PLUGIN_DISPLAY_NAME, $res['body']);
        $this->assertStringContainsString(self::PLUGIN_ID, $res['body']);
    }

    /**
     * [NEG] Activating the plugin introduces no PHP diagnostic on any page it
     * touches.
     *
     * Differential against the deactivated state, so core's own noise - if any -
     * is subtracted rather than asserted away.
     */
    public function testActivatingThePluginIntroducesNoPhpDiagnostic(): void
    {
        $this->assertSame(
            array('Warning: seeded', 'Notice: seeded'),
            $this->diagnosticsIn(
                'ok<br />' . "\n" . '<b>Warning</b>:  seeded in <b>/x.php</b> on line <b>1</b><br />' .
                "\n" . '<b>Notice</b>:  seeded in <b>/x.php</b> on line <b>2</b><br />'
            ),
            'anti-vacuity: the scanner must actually find diagnostics, or every assertion below passes on nothing'
        );

        $this->setPluginState('deactivate');
        $baseline = $this->diagnosticsAcrossPages();

        $this->setPluginState('activate');
        $withPlugin = $this->diagnosticsAcrossPages();

        foreach ($this->pages as $path)
        {
            $introduced = array_diff($withPlugin[$path], $baseline[$path]);
            $this->assertSame(
                array(),
                array_values($introduced),
                "activating the plugin introduced PHP diagnostics on $path"
            );
        }
    }

    /**
     * [NEG] The plugin's admin entry point, reached with no photo, renders.
     *
     * main.inc.php declares `Has Settings: true`, so the plugin list offers a
     * link to admin.php?page=plugin-persons with nothing else on the query
     * string. The only screen behind it so far wants an image_id, and the URL
     * has to answer with a page saying so rather than a PHP error or a blank
     * response - which is exactly what a missing dispatcher produced before.
     */
    public function testTheSettingsLinkRendersWithoutAnImageId(): void
    {
        $res = $this->ws->fetchPage('/admin.php?page=plugin-persons');

        $this->assertSame(200, $res['http_code']);
        $this->assertGreaterThan(
            self::MIN_PAGE_BYTES,
            strlen($res['body']),
            'anti-vacuity: a short body is an error page or a redirect, not a rendered admin screen'
        );

        $this->assertSame(array(), $this->diagnosticsIn($res['body']));
        $this->assertStringContainsString('class="errors"', $res['body'],
            'the screen renders, but says nothing about the photo it is missing');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** path => list of diagnostics, with every page proven to have really rendered. */
    private function diagnosticsAcrossPages(): array
    {
        $found = array();
        foreach ($this->pages as $path)
        {
            $res = $this->ws->fetchPage($path);
            $this->assertSame(200, $res['http_code'], "$path did not render");
            $this->assertGreaterThan(
                self::MIN_PAGE_BYTES,
                strlen($res['body']),
                "anti-vacuity: $path returned too little to have rendered - scanning it proves nothing"
            );
            $found[$path] = $this->diagnosticsIn($res['body']);
        }
        return $found;
    }

    /**
     * PHP renders diagnostics inline as `<b>Warning</b>:  text in <b>file</b>...`.
     * Returns "Level: text" per hit, so the same notice from the same page
     * compares equal across the two sides of the differential regardless of line
     * numbers shifting.
     */
    private function diagnosticsIn(string $body): array
    {
        $pattern = '#<b>(Warning|Notice|Deprecated|Fatal error|Parse error|Strict Standards)</b>:\s*(.*?)\s+in <b>#s';
        preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);

        $out = array();
        foreach ($matches as $match)
        {
            $out[] = $match[1] . ': ' . $match[2];
        }
        return $out;
    }

    private function setPluginState(string $action): void
    {
        $wanted = $action === 'activate' ? 'active' : 'inactive';
        if ($this->pluginState() === $wanted)
        {
            return;
        }

        $res = $this->ws->call('pwg.plugins.performAction', array(
            'action' => $action,
            'plugin' => self::PLUGIN_ID,
            'pwg_token' => $this->ws->token(),
        ));

        if ($this->pluginState() !== $wanted)
        {
            throw new RuntimeException("Could not $action the plugin: " . $res['body']);
        }
    }

    private function pluginState(): ?string
    {
        $state = $this->db->scalar("SELECT state FROM piwigo_plugins WHERE id = '" . self::PLUGIN_ID . "'");
        return $state === null ? null : (string)$state;
    }
}
