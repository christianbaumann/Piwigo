<?php
use PHPUnit\Framework\TestCase;

/**
 * Structural guard: the Batch Manager prefilter injects the move-mode choice by
 * string match against batch_manager_global.tpl. Nothing else in the stack
 * notices when that string moves - Smarty compiles the untouched template, the
 * page renders, the radios are simply absent, and every move silently takes the
 * unattended default.
 *
 * The anchor is read from the production constant, never typed again here
 * (.claude/rules/test-design.md, *do not transcribe production data into a test*).
 */
final class BatchTemplateAnchorTest extends TestCase
{
    private const TPL = 'admin/themes/default/template/batch_manager_global.tpl';

    /** A template shorter than this is a stub or a failed read, on which a count assertion says nothing. */
    private const MIN_TPL_BYTES = 10000;

    private function template(): string
    {
        $path = PIWIGO_ROOT . self::TPL;
        $this->assertFileExists($path, 'the Batch Manager template the prefilter targets is gone');

        $content = (string)file_get_contents($path);
        $this->assertGreaterThan(
            self::MIN_TPL_BYTES,
            strlen($content),
            'anti-vacuity: too little was read for the occurrence count below to mean anything'
        );

        return $content;
    }

    /**
     * [HAPPY] The anchor is present exactly once.
     *
     * Zero occurrences means the injection silently does nothing; more than one
     * would give the page several radio groups of the same name.
     */
    public function testAnchorOccursExactlyOnceInTheBatchTemplate(): void
    {
        $this->assertSame(
            1,
            substr_count($this->template(), PROVENANCE_TPL_BATCH_MOVE_ANCHOR),
            'the move panel anchor no longer matches batch_manager_global.tpl - the prefilter injects nothing'
        );
    }

    /**
     * [BVA] The anchor opens the move panel, so the injected radios land inside
     * the div the Batch Manager shows only when 'move' is the chosen action.
     */
    public function testAnchorSitsInsideTheFormThatCarriesTheMoveSelect(): void
    {
        $content = $this->template();

        $anchorAt = strpos($content, PROVENANCE_TPL_BATCH_MOVE_ANCHOR);
        $selectAt = strpos($content, 'name="move"');

        $this->assertNotFalse($selectAt, 'the move album select is gone; the panel assumption no longer holds');
        $this->assertGreaterThan($anchorAt, $selectAt, 'the anchor must open the panel the select lives in');
    }

    /** [HAPPY] The radios the template renders carry the name the resolver reads. */
    public function testTheInjectedBlockPostsTheParameterTheResolverReads(): void
    {
        $injection = (string)file_get_contents(PROVENANCE_PATH . 'template/batch_move_provenance.tpl');
        $this->assertGreaterThan(
            0,
            strlen($injection),
            'anti-vacuity: an empty injection would satisfy nothing below'
        );

        $this->assertSame(
            count(provenance_move_modes()),
            substr_count($injection, 'name="' . PROVENANCE_MOVE_MODE_PARAM . '"'),
            'one radio per mode, all posting the parameter provenance_resolve_mode() reads'
        );
    }
}
