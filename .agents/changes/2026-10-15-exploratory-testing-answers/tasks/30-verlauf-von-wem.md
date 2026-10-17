---
id: 30
dependencies: []
---

# Task 30: The Verlauf column reads "Von wem"

The Verlauf header matches the handbook.

## References

* `design.md#the-verlauf-column-reads-von-wem`

## Work

* [x] `plugins/provenance/language/de_DE/plugin.lang.php:37` `$lang['By']` = `'Von wem'`
* [x] Update tests that expect "Von"; re-shoot only screenshots showing it (`.claude/rules/handbook.md`)

## Verification

* [x] Read-only page shows "Von wem"
  **Note:** Verified via `ReadOnlyPageTest::testNormalAccountSeesTheAlbumValuesAndHistory` (new `<th>Von wem</th>` assertion; red before the string change, green after) and screenshot 27.
* [x] provenance suites pass; `check.php` passes
  **Note:** Verified via provenance integration (371 tests, 3 skipped, OK) and `ddev exec php handbuch/tools/check.php` (OK). Unit suite had 2 failures in `BuildArgfileTest`, caused by task 34's in-progress edit, unrelated.

## Deviations

* No existing test expected "Von"; added `<th>Von wem</th>` to `ReadOnlyPageTest` (album page case).
* `handbuch/03-fototexte.html` said `<strong>Von</strong> wem`; changed to `<strong>Von wem</strong>` so it matches the header.
* Re-shot only `27-herkunft-seite.png`; the other 15 churned screenshots were reverted.
* Verified 2026-10-17: ledger row added to `docs/agents/TESTING.md`.
* `handbuch/04-schlagworte.html` is modified in the tree by another agent, not by this task.
