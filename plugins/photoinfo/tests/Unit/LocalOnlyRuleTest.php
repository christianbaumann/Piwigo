<?php
use PHPUnit\Framework\TestCase;

/**
 * Which tags stay in the database and never reach a file. The rule lives in
 * PHOTOINFO_LOCAL_ONLY_TAGS and photoinfo_tag_is_local_only(); the seed file
 * is read, never transcribed.
 */
final class LocalOnlyRuleTest extends TestCase
{
    private const SEED_FILE = PIWIGO_ROOT . 'tools/deploy/tag-groups.json';

    /** Groups in the seed file, at least, measured 2026-10-09. */
    private const MIN_SEEDED_TAGS = 10;

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
     * [ECP] Every seeded tag in a local-only group is itself local-only, so no
     * group meant to stay local leaks a tag into a file.
     */
    public function testEverySeededTagOfALocalOnlyGroupIsLocalOnly(): void
    {
        $seed = json_decode(file_get_contents(self::SEED_FILE), true);
        $this->assertGreaterThanOrEqual(self::MIN_SEEDED_TAGS, count($seed['tags']), 'anti-vacuity: the seed file was not read');

        $local_groups = 0;
        foreach ($seed['tags'] as $tag)
        {
            if (photoinfo_tag_is_local_only($tag['group']))
            {
                $local_groups++;
                $this->assertTrue(photoinfo_tag_is_local_only($tag['name']), $tag['name']);
            }
        }
        $this->assertGreaterThan(0, $local_groups, 'anti-vacuity: no local-only group in the seed file');
    }
}
