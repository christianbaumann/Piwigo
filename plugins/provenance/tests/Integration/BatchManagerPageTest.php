<?php
use PHPUnit\Framework\TestCase;

/**
 * The move-mode choice as the Batch Manager actually serves it.
 *
 * The unit guard says the anchor still matches the core template; this says the
 * prefilter is registered on the right event and that the radios reach the
 * page. Two different failures, and the first cannot see the second: a prefilter
 * hung on an event that never fires leaves the anchor test perfectly green.
 */
final class BatchManagerPageTest extends TestCase
{
    private const PAGE = '/admin.php?page=batch_manager&mode=global';

    /** A rendered Batch Manager shorter than this is an error page or a redirect. */
    private const MIN_PAGE_BYTES = 2000;

    private WsClient $ws;

    protected function setUp(): void
    {
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
    }

    protected function tearDown(): void
    {
        $this->ws->logout();
    }

    private function page(): string
    {
        $res = $this->ws->fetchPage(self::PAGE);
        $body = (string)$res['body'];

        $this->assertGreaterThan(
            self::MIN_PAGE_BYTES,
            strlen($body),
            'anti-vacuity: the Batch Manager did not render, so the counts below say nothing'
        );

        return $body;
    }

    /** [HAPPY] One radio per mode reaches the rendered page. */
    public function testTheRenderedPageCarriesOneRadioPerMode(): void
    {
        $this->assertSame(
            count(provenance_move_modes()),
            substr_count($this->page(), 'name="' . PROVENANCE_MOVE_MODE_PARAM . '"'),
            'the move-mode radios are not on the Batch Manager'
        );
    }

    /** [DT] Every mode the resolver accepts is offered, and nothing else. */
    public function testEveryModeTheResolverAcceptsIsOffered(): void
    {
        $body = $this->page();

        $this->assertNotEmpty(provenance_move_modes(), 'anti-vacuity: an empty list would assert nothing');

        foreach (provenance_move_modes() as $mode)
        {
            $this->assertStringContainsString(
                'name="' . PROVENANCE_MOVE_MODE_PARAM . '" value="' . $mode . '"',
                $body,
                "the Batch Manager offers no way to choose $mode"
            );
        }
    }

    /** [BVA] Keep is pre-selected, so submitting the form unchanged destroys nothing. */
    public function testKeepIsThePreSelectedChoice(): void
    {
        $this->assertStringContainsString(
            'value="' . PROVENANCE_MODE_KEEP . '" checked="checked"',
            $this->page(),
            'the safe mode must be the one already chosen when the panel opens'
        );
    }

    /** [BVA] The radios land inside the move panel, not loose on the page. */
    public function testTheRadiosLandInsideTheMovePanel(): void
    {
        $body = $this->page();

        $panelAt = strpos($body, 'id="action_move"');
        $radioAt = strpos($body, 'name="' . PROVENANCE_MOVE_MODE_PARAM . '"');
        $dissociateAt = strpos($body, 'id="action_dissociate"');

        $this->assertNotFalse($panelAt, 'the move panel is gone from the rendered page');
        $this->assertNotFalse($dissociateAt, 'the panel after it is gone; the containment check is meaningless');
        $this->assertGreaterThan($panelAt, $radioAt, 'the radios must follow the panel that opens');
        $this->assertLessThan($dissociateAt, $radioAt, 'the radios must stay inside it');
    }
}
