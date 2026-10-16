---
id: 11
dependencies: []
---

# Task 11: Tag writes clear the whole user cache

Tag list, tag page and Schlagwort-Gruppen are current right after any persons or typetags tag write.

## References

* `design.md#tag-writes-clear-the-whole-user-cache`
* decision 0004
* `plugins/persons/include/index.inc.php:450`, `plugins/typetags/main.inc.php:367`, `:429`, `:598`

## Work

* [x] Replace `nb_available_tags = NULL` with `invalidate_user_cache()` in persons and typetags
  **Note:** persons: `persons_invalidate_tag_cache()` body; typetags: new
  `typetags_invalidate_tag_lists()` called by addTag, removeTag, addNewTag. Both include
  `admin/include/functions.php` (ws.php does not load it).
* [x] Decision superseding 0004
  **Note:** `docs/agents/decisions/0071-tag-writes-clear-the-whole-user-cache.md`; the
  non-coverage row in `docs/agents/TESTING.md` now cites it.
* [x] typetags part as submodule commit, pushed
  **Note:** typetags `18549d1`. Push refused (403, see task 09); local only.

## Deviations

* `invalidate_user_cache()` purges `$persistent_cache`, which persons' CLI test runtime did not
  create: 53 persons integration cases calling `persons_apply_change()` in-process died with
  "purge() on null". `plugins/persons/tests/Support/PiwigoRuntime.php` now creates it as
  `common.inc.php` does (test support only; every web entry has it).
* typetags' `CacheInvalidationTest` add/remove cases (asserted the nulled column) are replaced by
  cases asserting what a reader sees on `tags.php`; `testCacheIsRestoredAfterRun` stays.

## Verification

* [x] Integration: a user who read the tag list before sees the new count right after a mark add, a mark delete, a typetags add and remove
  **Note:** persons `TagListFreshnessTest` (normal reads `tags.php`, webmaster marks, then deletes
  the region); typetags `CacheInvalidationTest::testAnAddedTagIsListedAtOnce` /
  `testARemovedTagLeavesTheListAtOnce`.
* [x] Each test goes red with the old update
  **Note:** typetags cases red in the full run before the change ("the list is stale after the +/x");
  persons case red with the function body flipped back to the old UPDATE ("stale after the mark").
* [x] persons and typetags suites pass
  **Note:** 2026-10-16, under the suite lock: persons unit 206 OK, integration 191 with 1 failure,
  `IndexRebuildTest` (known, task 38; not this change), persons E2E 163 passed; typetags unit 91
  OK, integration 179 OK; photoinfo `TagWriteTest|FreitextAssignTest|RoleMatrixTest` 72 OK; photoedit
  `ExclusionTest|ApplyRegionsTest` 16 OK.
