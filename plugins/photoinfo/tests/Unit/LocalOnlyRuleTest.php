<?php
use PHPUnit\Framework\TestCase;

/**
 * Which tags stay in the database and never reach a file. The rule lives in
 * PHOTOINFO_LOCAL_ONLY_TAGS and photoinfo_tag_is_local_only(), which looks at
 * the tag's name and its group's (decision 0051); the seed file is read, never
 * transcribed.
 */
final class LocalOnlyRuleTest extends TestCase
{
    private const SEED_FILE = PIWIGO_ROOT . 'tools/deploy/tag-groups.json';

    /** Groups in the seed file, at least; 12 measured 2026-10-09. */
    private const MIN_SEEDED_GROUPS = 10;

    /** [ECP] Named in the list, a question mark, or neither. */
    public function testTheRuleCoversTheListAndTheQuestionMark(): void
    {
        $this->assertTrue(photoinfo_tag_is_local_only('Ausstellung'));
        $this->assertTrue(photoinfo_tag_is_local_only('Name ?'));
        $this->assertTrue(photoinfo_tag_is_local_only('?'));
        $this->assertFalse(photoinfo_tag_is_local_only('Kirmes'));
        $this->assertFalse(photoinfo_tag_is_local_only('Ausstellungen'));
    }

    /**
     * [ECP] A tag in a local-only group stays local whatever its own name:
     * the group is in the list, or holds a question mark. Owner decision Q20,
     * decision 0051.
     */
    public function testATagInALocalOnlyGroupIsLocalOnly(): void
    {
        $this->assertTrue(photoinfo_tag_is_local_only('Vernissage 1987', 'Ausstellung'));
        $this->assertTrue(photoinfo_tag_is_local_only('Vernissage 1987', 'Name ?'));
        $this->assertTrue(photoinfo_tag_is_local_only('Vernissage 1987', '?'));
    }

    /** [BVA] [NEG] A group whose name only starts like a listed one is not local-only. */
    public function testAGroupNamedLikeAListedOneIsNot(): void
    {
        $this->assertFalse(photoinfo_tag_is_local_only('Vernissage 1987', 'Ausstellungen'));
        $this->assertFalse(photoinfo_tag_is_local_only('Kirmes', 'Feste'));
    }

    /**
     * [BVA] [ERR] A name or group with surrounding whitespace or a control
     * character is judged as photoinfo_file_keywords() writes it, cleaned and
     * trimmed. MariaDB compares a stored "Ausstellung " equal to "Ausstellung",
     * so a raw row read back from the database must give the same answer as
     * the write, or the rescan reports the tag the write left out and prune
     * removes it.
     */
    public function testWhitespaceAroundANameOrGroupDoesNotChangeTheAnswer(): void
    {
        $this->assertTrue(photoinfo_tag_is_local_only('Vernissage 1987', 'Ausstellung '));
        $this->assertTrue(photoinfo_tag_is_local_only('Vernissage 1987', " Ausstellung\n"));
        $this->assertTrue(photoinfo_tag_is_local_only('Ausstellung ', null));
        $this->assertTrue(photoinfo_tag_is_local_only("\tAusstellung", 'Feste'));
        $this->assertTrue(photoinfo_tag_is_local_only('Vernissage 1987', "Ausstellung\x1F"), 'a control character trim() leaves');
        $this->assertFalse(photoinfo_tag_is_local_only('Vernissage 1987', 'Aus stellung'));
        $this->assertFalse(photoinfo_tag_is_local_only('Kirmes ', 'Feste '));
    }

    /**
     * [ECP] A listed name in any letter case is local-only, as a tag name or
     * a group name: the tags and typetags tables compare names with
     * utf8mb3_general_ci, so `ausstellung` names the same group as
     * `Ausstellung` there. Owner decision Q25, decision 0051.
     */
    public function testALowerUpperOrMixedCaseListedNameIsLocalOnly(): void
    {
        $this->assertTrue(photoinfo_tag_is_local_only('ausstellung'));
        $this->assertTrue(photoinfo_tag_is_local_only('AUSSTELLUNG'));
        $this->assertTrue(photoinfo_tag_is_local_only('aUSSTELLUNG', 'Feste'));
        $this->assertTrue(photoinfo_tag_is_local_only('Vernissage 1987', 'ausstellung'));
        $this->assertTrue(photoinfo_tag_is_local_only('Vernissage 1987', 'AUSSTELLUNG'));
        $this->assertTrue(photoinfo_tag_is_local_only('Vernissage 1987', ' AusStellung '));
    }

    /** [BVA] [NEG] Case folding does not widen the match: `ausstellungen` is still another name. */
    public function testALowerCaseNameThatOnlyStartsLikeAListedOneIsNot(): void
    {
        $this->assertFalse(photoinfo_tag_is_local_only('ausstellungen'));
        $this->assertFalse(photoinfo_tag_is_local_only('Vernissage 1987', 'ausstellungen'));
        $this->assertFalse(photoinfo_tag_is_local_only('Vernissage 1987', 'AUSSTELLUNGEN'));
    }

    /** [ECP] The tag's own name still decides inside an ordinary group, and without one. */
    public function testTheNameRuleHoldsWithAndWithoutAGroup(): void
    {
        $this->assertTrue(photoinfo_tag_is_local_only('Wer?', 'Feste'));
        $this->assertTrue(photoinfo_tag_is_local_only('Ausstellung', 'Feste'));
        $this->assertTrue(photoinfo_tag_is_local_only('Ausstellung', null));
        $this->assertFalse(photoinfo_tag_is_local_only('Vernissage 1987', null));
    }

    /**
     * [ECP] Every listed name is a group the deploy seeds, so the group rule
     * watches a group that exists; a renamed group would silently let its
     * tags into files.
     */
    public function testEveryListedNameIsASeededGroup(): void
    {
        $seed = json_decode(file_get_contents(self::SEED_FILE), true);
        $this->assertGreaterThanOrEqual(self::MIN_SEEDED_GROUPS, count($seed['groups']), 'anti-vacuity: the seed file was not read');
        $this->assertGreaterThan(0, count(PHOTOINFO_LOCAL_ONLY_TAGS), 'anti-vacuity: the list is empty');

        $groups = array_column($seed['groups'], 'name');
        foreach (PHOTOINFO_LOCAL_ONLY_TAGS as $name)
        {
            $this->assertContains($name, $groups);
        }
    }
}
