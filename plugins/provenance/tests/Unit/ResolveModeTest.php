<?php
use PHPUnit\Framework\TestCase;

/**
 * The one place that turns a request value into a provenance mode.
 *
 * Core offers no way to tell a move from a plain association at the only
 * trigger the plugin can reach, so the choice travels as an explicit request
 * parameter instead: the Batch Manager's move panel and the album-delete prompt
 * post it, and an unattended API call omits it. Both paths read it through this
 * resolver, which is why the default lives here and nowhere else.
 *
 * Every unusable value resolves to 'keep' - the mode that destroys nothing. A
 * caller with a typo gets the safe behaviour, never a silent clear.
 */
final class ResolveModeTest extends TestCase
{
    /** [HAPPY] [ECP] Each move mode on the list resolves to itself. */
    public function testEveryAllowedMoveModeResolvesToItself(): void
    {
        $this->assertNotEmpty(provenance_move_modes(), 'anti-vacuity: an empty list would assert nothing');

        foreach (provenance_move_modes() as $mode)
        {
            $this->assertSame(
                $mode,
                provenance_resolve_mode(array(PROVENANCE_MOVE_MODE_PARAM => $mode), PROVENANCE_MOVE_MODE_PARAM, provenance_move_modes())
            );
        }
    }

    /** [HAPPY] [ECP] The delete prompt's shorter list works the same way. */
    public function testEveryAllowedDeleteModeResolvesToItself(): void
    {
        $this->assertNotEmpty(provenance_delete_modes(), 'anti-vacuity: an empty list would assert nothing');

        foreach (provenance_delete_modes() as $mode)
        {
            $this->assertSame(
                $mode,
                provenance_resolve_mode(array(PROVENANCE_DELETE_MODE_PARAM => $mode), PROVENANCE_DELETE_MODE_PARAM, provenance_delete_modes())
            );
        }
    }

    /** [DT] A mode valid for a move is not valid for a delete, and falls back. */
    public function testAModeOutsideTheAllowedListFallsBackToKeep(): void
    {
        $this->assertContains(PROVENANCE_MODE_REPLACE, provenance_move_modes());
        $this->assertNotContains(PROVENANCE_MODE_REPLACE, provenance_delete_modes());

        $this->assertSame(
            PROVENANCE_MODE_KEEP,
            provenance_resolve_mode(
                array(PROVENANCE_DELETE_MODE_PARAM => PROVENANCE_MODE_REPLACE),
                PROVENANCE_DELETE_MODE_PARAM,
                provenance_delete_modes()
            )
        );
    }

    /** [BVA] An unattended call sends no parameter at all. */
    public function testAnAbsentParameterResolvesToKeep(): void
    {
        $this->assertSame(
            PROVENANCE_MODE_KEEP,
            provenance_resolve_mode(array(), PROVENANCE_MOVE_MODE_PARAM, provenance_move_modes())
        );
    }

    /** [BVA] An empty string is an absent parameter, not a mode. */
    public function testAnEmptyValueResolvesToKeep(): void
    {
        $this->assertSame(
            PROVENANCE_MODE_KEEP,
            provenance_resolve_mode(array(PROVENANCE_MOVE_MODE_PARAM => ''), PROVENANCE_MOVE_MODE_PARAM, provenance_move_modes())
        );
    }

    /** [ECP] Surrounding whitespace is a form artefact, not part of the value. */
    public function testSurroundingWhitespaceIsIgnored(): void
    {
        $this->assertSame(
            PROVENANCE_MODE_CLEAR,
            provenance_resolve_mode(
                array(PROVENANCE_MOVE_MODE_PARAM => "  " . PROVENANCE_MODE_CLEAR . "\n"),
                PROVENANCE_MOVE_MODE_PARAM,
                provenance_move_modes()
            )
        );
    }

    /** [NEG] Matching is case-sensitive, so a near miss does not clear anything. */
    public function testAWrongCaseValueResolvesToKeep(): void
    {
        $this->assertSame(
            PROVENANCE_MODE_KEEP,
            provenance_resolve_mode(
                array(PROVENANCE_MOVE_MODE_PARAM => strtoupper(PROVENANCE_MODE_CLEAR)),
                PROVENANCE_MOVE_MODE_PARAM,
                provenance_move_modes()
            )
        );
    }

    /** [NEG] An unknown word never becomes a destructive mode. */
    public function testAnUnknownValueResolvesToKeep(): void
    {
        $this->assertSame(
            PROVENANCE_MODE_KEEP,
            provenance_resolve_mode(
                array(PROVENANCE_MOVE_MODE_PARAM => 'incinerate'),
                PROVENANCE_MOVE_MODE_PARAM,
                provenance_move_modes()
            )
        );
    }

    /** [NEG] A crafted array parameter is not a string and resolves to keep. */
    public function testANonStringValueResolvesToKeep(): void
    {
        $this->assertSame(
            PROVENANCE_MODE_KEEP,
            provenance_resolve_mode(
                array(PROVENANCE_MOVE_MODE_PARAM => array(PROVENANCE_MODE_CLEAR)),
                PROVENANCE_MOVE_MODE_PARAM,
                provenance_move_modes()
            )
        );
    }

    /** [HAPPY] Keep is the first mode of both lists, so a UI renders it as the default. */
    public function testKeepIsTheFirstModeOfBothLists(): void
    {
        $this->assertSame(PROVENANCE_MODE_KEEP, provenance_move_modes()[0]);
        $this->assertSame(PROVENANCE_MODE_KEEP, provenance_delete_modes()[0]);
    }

    /** [DT] Both new write paths have a history source of their own. */
    public function testTheMoveAndDeletePathsHaveTheirOwnHistorySources(): void
    {
        $this->assertContains(PROVENANCE_HISTORY_SOURCE_MOVE, provenance_history_sources());
        $this->assertContains(PROVENANCE_HISTORY_SOURCE_ALBUM_DELETE, provenance_history_sources());
        $this->assertNotSame(PROVENANCE_HISTORY_SOURCE_MOVE, PROVENANCE_HISTORY_SOURCE_ALBUM_DELETE);
    }
}
