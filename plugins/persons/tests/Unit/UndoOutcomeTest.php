<?php
use PHPUnit\Framework\TestCase;

require_once PERSONS_PATH . 'include/events_undo.inc.php';

/**
 * What persons answers photoinfo after a region undo: the outcome of
 * persons_apply_change(), with the keyword writes that followed it, so the
 * caller can tell a photo whose file kept the old keywords.
 */
final class UndoOutcomeTest extends TestCase
{
    /** [HAPPY] A good change carries the write results of the listeners. */
    public function testTheTagWritesTravelWithTheAnswer(): void
    {
        $writes = array(array('ok' => false, 'message' => 'File is not writable'));

        $answer = persons_undo_outcome(array('ok' => true, 'regions' => 1, 'message' => '', 'tag_writes' => $writes));

        $this->assertTrue($answer['ok']);
        $this->assertSame($writes, $answer['tag_writes']);
    }

    /** [ECP] An outcome without writes (nothing notified) answers an empty list, not a missing key. */
    public function testNoWritesAnswerAnEmptyList(): void
    {
        $answer = persons_undo_outcome(array('ok' => true, 'regions' => 1, 'message' => ''));

        $this->assertSame(array(), $answer['tag_writes']);
    }

    /** [NEG] A failed change keeps its message and is not forbidden. */
    public function testAFailedChangeKeepsItsMessage(): void
    {
        $answer = persons_undo_outcome(array('ok' => false, 'regions' => 0, 'message' => 'Not written', 'tag_writes' => array()));

        $this->assertFalse($answer['ok']);
        $this->assertFalse($answer['forbidden']);
        $this->assertSame('Not written', $answer['message']);
    }
}
