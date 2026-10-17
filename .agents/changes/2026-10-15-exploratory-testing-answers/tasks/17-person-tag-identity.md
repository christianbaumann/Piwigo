---
id: 17
dependencies:
- 16
---

# Task 17: persons: a person's tag is exact, re-created when missing, removed on delete

A person always has exactly its own tag while it exists, and loses it on delete.

## References

* `design.md#a-persons-tag-is-found-by-exact-name-and-re-created-when-missing`
* `design.md#deleting-a-person-removes-the-persons-tag`
* `index.inc.php:334`, `:501`

## Work

* [x] Resolve the tag by exact name, no `url_name` fallback
  **Note:** `persons_tag_id_named()` (`plugins/persons/include/index.inc.php`) replaces core's `tag_id_from_tag_name()`: `name =` under the tags table's collation, `ORDER BY id LIMIT 1`, else a new tag with core's `render_tag_url` URL name.
* [x] Re-create the tag when `persons.tag_id` points at none; `persons_person_gallery_url()` checks the tag exists
  **Note:** `persons_ensure_own_tag()` runs on every resolve of an existing person (`persons_person_id_from_name()`), and before a rename and a delete. It keeps `lastmodified` so a repair does not reorder the picker. `persons_person_gallery_url()` reads the tag row and links with the tag's own `url_name`, or returns `''`.
* [x] Person delete removes the tag from photos, the tag list, and the keywords from the files (through photoinfo, persons lock first)
  **Note:** core's `delete_tags()` (activity row, `delete_tags` event, user-cache count reset) after the person row goes; the keywords through the existing `persons_tags_changed` to photoinfo, for the region photos and every photo carrying the tag. Handbook ch5 *Löschen* says the tag goes too.

## Verification

* [x] Integration: person `Name` gets tag `Name`, not `Name ?`
  **Note:** Verified via persons `PersonTagTest::testANewPersonGetsATagOfItsOwnNameNotAMarkerTag` (marker tag with the same `url_name`, asserted) and `testAPersonLinkedToAMarkerTagGetsItsOwnAtTheNextMark` (the person-146 shape). All five `PersonTagTest` cases ran red against HEAD's `index.inc.php` (flip inside the container under the suite lock, restored, checksum checked) for the expected reasons, green after.
* [x] Integration: after its tag row is deleted, the next mark re-creates it and the link answers 200
  **Note:** Verified via `PersonTagTest::testADeletedTagIsRecreatedByTheNextMarkAndItsLinkAnswers` (no box link while the tag is gone; after the mark the box label's href is fetched: 200, the tag page names the person).
* [x] Integration: delete removes tag rows and keywords from every affected file
  **Note:** Verified via `PersonTagTest::testDeletingAPersonRemovesItsTag` (tag row and every `image_tag` row, also a hand-applied one, gone), `testDeletingAPersonLinkedToAMarkerTagLeavesTheMarker` ([NEG]) and photoinfo `TagWriteTest::testAPersonsDeleteRemovesTheName`, extended to two photos: keywords gone from both files, tag row gone.
* [x] persons and photoinfo suites pass
  **Note:** 2026-10-17 (task 38), each under the suite lock with `ddev exec flock /tmp/pwg-suite.lock bash -c 'set -a; . local/config/<p>-test.env; set +a; plugins/<p>/vendor/bin/phpunit --configuration plugins/<p>/phpunit.xml --testsuite integration [--order-by=reverse]'`: typetags 179 OK twice and once `--order-by=reverse`; persons 209 (1 skipped, `PluginActivationTest`'s recorded gap) OK in default order and then `--order-by=reverse`, after a first run with 2 failures in task 18's then-uncommitted `TagToolGuardTest` (a fatal in its new `include/tag_guard.inc.php`, mid-edit by that agent; `IndexRebuildTest` passed in all three); photoedit 109 OK; photoinfo 219 OK; provenance 353 OK (3 skipped). E2E suites not run
  **Note:** 2026-10-16: persons unit OK (206), photoinfo unit OK (269), photoinfo integration OK (214). persons integration: 190 tests; 5 RoleMatrix errors were a parse error in `plugins/typetags/include/events_public.inc.php` from a parallel agent's mid-edit (re-run of `RoleMatrixTest` alone: OK, 36 tests). Remaining failure: `IndexRebuildTest`, on the region-less leftover person 146 `Name` (the only person with no region), which no rescan can restore; task 38 decides. Repair command, not run (real data): `ddev mysql -e "DELETE FROM piwigo_persons WHERE id = 146 AND name = 'Name' AND NOT EXISTS (SELECT 1 FROM piwigo_person_region WHERE person_id = 146);"` - touches no file and leaves the typetags marker tag `Name ?` (13835, on photo 56012) alone. E2E not run.

## Deviations

* "Own tag" is stricter than "points at none": a tag whose name differs from the person's (the
  link the `url_name` fallback made, person 146 `Name` -> marker tag `Name ?`) counts as not the
  person's too. The person is pointed at its own tag at the next resolve, rename or delete; the
  other tag is never renamed, removed from a photo or deleted by persons. Before this a rename of
  146 would have renamed `Name ?`, and a delete would have taken `Name ?` off photo 56012.
* The old behaviour "the tag row is left to core's orphan-tag mechanism" (plan
  `2026-08-30-person-face-tagging.md`, the old `persons_delete_person()` comment) is replaced, as
  the design's *Deleting a person removes the person's tag* decides; no separate decision file.
