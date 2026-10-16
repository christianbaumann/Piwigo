---
id: 08
dependencies:
- 01
---

# Task 08: Typed tag names are NFC and match by name only

Typing a tag never makes an invisible duplicate or assigns a marker tag, and the user sees which stored tag the photo got.

## References

* `design.md#typed-tag-names-are-nfc-and-match-by-name-only`
* `plugins/typetags/main.inc.php:395`, `:475`
* core `tag_id_from_tag_name()` `admin/include/functions.php:1709`, `get_tag_ids`

## Work

* [x] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
  **Note:** `CoreTypedTagNameCharacterizationTest` (3 cases through `pwg.images.setInfo` `tag_list`: case/accent variant links, url_name-only match links, NFD creates a second tag). Each watched red by inverting its assertion. After the core change the NFD case went red as intended and was replaced, in its own cycle, by the requirement case `testADecomposedNameLinksTheComposedTag`.
* [x] NFC-normalize input in typetags `addNewTag` and core `get_tag_ids`
  **Note:** `typetags_nfc()` (`include/functions.inc.php`, unit `TypedTagNfcTest`); core `get_tag_ids()` inline. Both skip normalization when PHP has no intl (`Normalizer`) and keep the input for invalid UTF-8.
* [x] typetags matches by name only, no `url_name` fallback; `typetags_tag_exists()` follows
  **Note:** Deviation: `typetags_tag_exists()` became `typetags_tag_id_named()` (returns the id, used once for both the cap and the link); a new name is created with core's `create_tag()` instead of `tag_id_from_tag_name()`, so neither the url_name nor the `get_tag_name_like_where` lookup applies. The cap is checked only when the name is new.
* [x] Answer and badge carry the stored tag name
  **Note:** Already true before this task (answer `name` read from the row, the JS badge uses it); now pinned by `TypedNameMatchTest` and the E2E case `a name the server calls equal shows the stored tag` in `add-new-tag.spec.js`.
* [x] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [x] Unit/integration: NFD `Häuser` assigns the NFC tag; `Name` creates `Name`, not `Name ?`; `Kategorie` creates `Kategorie`
  **Note:** Verified via typetags `TypedNameMatchTest` (red before the change: 4 of 5 failed), `TypedTagNfcTest`, provenance `CoreTypedTagNameCharacterizationTest::testADecomposedNameLinksTheComposedTag`.
* [x] `Häuser Ortsansichten` assigns `hauser ortsansichten` and the answer names it
  **Note:** Verified via `TypedNameMatchTest::testACaseAndAccentVariantLinksTheStoredTagAndTheAnswerNamesIt`.
* [x] Daily-limit count unchanged for an existing tag
  **Note:** Verified via `TypedNameMatchTest::testAtTheCapAVariantOfAnExistingTagIsLinkedAndNotCounted` and `testAtTheCapANameMatchingOnlyAUrlNameIsRefused`.
* [x] typetags and provenance suites pass
  **Note:** Verified 2026-10-16 under the suite lock: typetags unit 91 OK, integration 164 OK, E2E 57 passed; provenance unit 276 OK, integration 343 OK (3 skipped, pre-existing). The first typetags integration run failed `CreationCapTest::testTheLastTagOfTheDayIsCreated`: `TypedNameMatchTest` deleted its tags without their activity rows, which then counted against the normal account's cap. Fixed in its tearDown; the leaked rows of deleted tags were removed.
* [x] The remote host has PHP's intl extension (`Normalizer`); without it neither typetags nor core normalizes, and NFD input still makes a second tag there. The deploy probes did not ask for it.
  **Note:** Verified 2026-10-16 via the remote's phpinfo (read-only, deploy login): intl enabled, ICU 74.2; ledger row in `docs/agents/TESTING.md`
