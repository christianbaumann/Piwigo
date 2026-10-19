---
id: 57
dependencies: []
---

# Task 57: "Auf N Fotos anwenden" uses the nearest ancestor and warns before overwriting

Added 2026-10-19 (brainstorm 4b, 10a). Today apply on a sub-album without its own Herkunft blanks the values its photos inherited, and overwrites another album's Herkunft without notice. After this task, the sub-album applies its nearest ancestor's values, and a confirm names the photos that would be overwritten.

## References

* `design-handbook-gap-fix.md#the-apply-rule-task-57`, key decision *"Auf N Fotos anwenden" applies the nearest ancestor's values and warns before overwriting*
* `plugins/provenance/include/ws_functions.inc.php` (`ws_provenance_applyToPhotos()`), `include/events_inherit.inc.php` (`provenance_inherited_album_values()`), `template/album_provenance.js` (apply runner), `include/functions.inc.php`, language files
* provenance `tests/Integration/ApplyTest.php`, `InheritTest.php`; provenance E2E album screen specs
* `design.md#only-new-album-links-inherit-herkunft` (task 20), `docs/agents/decisions/`

## Work

* [x] Decision file `0086` (next free number): the rule, the confirm, the accepted trade-off (a sub-album cleared on purpose no longer clears its photos while an ancestor has values)
* [x] Integration tests first, red: `dry_run=1` answers the count and changes no row and no history; sub-album with four empty fields under a parent with values: apply writes the parent's values; sub-album with its own values: unchanged; album with no values anywhere: clears as today; history rows name the applying album
* [x] `ws_provenance_applyToPhotos()` takes its values from `provenance_inherited_album_values()` when the album's own four fields are empty
* [x] `pwg.provenance.applyToPhotos` takes `dry_run=1`: it answers the count of photos whose Herkunft is not empty and differs in at least one field from what apply would write, and writes nothing (no new method, so no new role-matrix row; the existing `apply` rows cover it)
* [x] `album_provenance.js` asks for confirmation with that count before applying when it is above 0; Abbrechen applies nothing. German and English strings
* [x] E2E first, red: the confirm appears with the right count, and cancelling leaves the photos unchanged

## Verification

* [x] New tests failed before the change and pass after it

  **Note:** Verified via ApplyTest (5 red before: inherited values null, no `would_overwrite`) and the confirm E2E (red with `preflight` disabled: no dialog), green after.
* [x] With a count of 0, apply runs without a confirm, as today

  **Note:** Verified via E2E `with nothing to overwrite no confirm appears`.
* [x] provenance unit, integration and E2E suites pass; `RoleMatrixTest` guard passes; commands named in the notes

  **Note:** unit 316 OK; integration 442 tests OK (3 skipped, pre-existing); E2E 82 passed (all under `flock /tmp/pwg-suite.lock`).

## Verifier review (2026-10-19)

* APPROVED. Dry run goes through the same function and registration (`admin_only`, `post_only`, guest and token checks run before `dry_run` is read); `dry_run` is `WS_TYPE_BOOL`, so core parses '0'/'false'. The 3 skipped tests are hard-coded `markTestSkipped` (1:1 album relation, update() re-entry, divergence detection), none related to this task.
