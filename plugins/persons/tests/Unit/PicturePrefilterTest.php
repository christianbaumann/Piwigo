<?php
use PHPUnit\Framework\TestCase;

require_once PERSONS_PATH . 'include/events_public.inc.php';

/**
 * Where persons_picture_prefilter() puts the person row and the editor.
 *
 * The editor needs the stage (the editor scripts bail out without it) but
 * belongs in the information panel, after <dl id="standard">. That is a second
 * anchor, and on a template without it the editor must not vanish while the
 * boxes it edits are still drawn.
 */
final class PicturePrefilterTest extends TestCase
{
    private function stage(): string
    {
        return '<div id="persons-stage">' . PERSONS_TPL_INJECT_POINT;
    }

    /** [HAPPY] The person row goes in front of the anchor, the editor's row after it. */
    public function testThePersonRowPrecedesTheAnchorAndTheEditorRowFollowsIt(): void
    {
        $out = persons_picture_prefilter(PERSONS_TPL_INJECT_POINT . "\n" . PERSONS_TPL_ROW_INJECT_POINT);

        $anchorAt = strpos($out, PERSONS_TPL_ROW_INJECT_POINT);
        $this->assertNotFalse($anchorAt, 'the row anchor must be kept for the other prefilters');

        $rowAt = strpos($out, persons_template_include('public_persons.tpl'));
        $this->assertNotFalse($rowAt);
        $this->assertLessThan($anchorAt, $rowAt, 'the person row belongs inside the list');

        $editor = '<div id="PersonsTagging" class="imageInfoTable">'
            . persons_template_include('public_editor.tpl') . '</div>';
        $this->assertSame(1, substr_count($out, $editor), 'the editor row is not injected exactly once');
        $this->assertGreaterThan($anchorAt, strpos($out, $editor), 'the editor row belongs after the list');
    }

    /** [NEG] Without the row anchor, the editor stays with the stage rather than disappearing. */
    public function testWithoutTheRowAnchorTheEditorStaysInTheStage(): void
    {
        $out = persons_picture_prefilter(PERSONS_TPL_INJECT_POINT);

        $include = persons_template_include('public_editor.tpl');
        $this->assertSame(1, substr_count($out, $include), 'the editor was dropped with its anchor');

        $stageAt = strpos($out, $this->stage());
        $this->assertNotFalse($stageAt, 'anti-vacuity: the stage itself was not injected');
        $this->assertGreaterThan($stageAt, strpos($out, $include));
        $this->assertLessThan(strpos($out, '</div>', $stageAt + strlen($this->stage())), strpos($out, $include), 'the editor is not inside the stage');
    }
}
