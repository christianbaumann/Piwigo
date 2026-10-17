---
id: 24
dependencies: []
---

# Task 24: German overrides for named messages

Named messages read in clean German.

## References

* `design.md#german-messages-give-the-reason`
* `design.md#merge-messages-lose-their-braces`
* `design.md#pwg-update-1-names-digits`
* `design.md#album-properties-move-errors-show-sub-albums-are-not-offered`
* `.claude/rules/handbook.md` (German strings)

## Work

* [x] `local/language/de_DE.lang.php`: "A person needs a name", both merge keys without braces, PWG-UPDATE-1 with digits, "Just now"
* [x] One row each in `plugins/provenance/tests/Unit/GermanOverrideKeyTest.php`

## Verification

* [x] provenance unit suite passes with the new rows
  **Note:** Verified via `ddev exec plugins/provenance/vendor/bin/phpunit --testsuite unit --configuration plugins/provenance/phpunit.xml`: OK (301 tests). The new rows were red before the override existed (6 failures, 1 error). persons unit OK (216); `AddRegionTest::testANamelessRegionIsRefusedInTheAccountsLanguage` and `PersonAdminApiTest::testRenamingToAWhitespaceOnlyNameIsRefused` OK under the suite lock.
* [x] Each row goes red when its literal is renamed
  **Note:** Verified 2026-10-17 by an in-container flip script under `/tmp/pwg-suite.lock` (in-place string swap, revert from an EXIT trap, files checked clean afterwards). Each mutant killed exactly one test:

  | Mutant | Killed by |
  |---|---|
  | `tags.tpl` key `Tag(s)` -> `Tags` | `testTheEmittingFileStillCarriesTheLiteral@Tag(s)...` |
  | `group_list.tpl` key `Group(s)` -> `Groups` | `testTheEmittingFileStillCarriesTheLiteral@Group(s)...` |
  | `site_update.php` `The name` -> `The names` | `testTheEmittingFileStillCarriesTheLiteral@The name of directories...` |
  | `cat_modify.tpl` `Just now` -> `Just now!` | `testTheEmittingFileStillCarriesTheLiteral@Just now` |
  | persons `define` value `...name!` | `testTheEmittingFileStillCarriesTheLiteral@A person needs a name` |
  | override key `Just now` -> `Just now!` | `testTheLanguageFileStillTranslatesTheKey@Just now` |
  | core German PWG-UPDATE-1 wording changed | `testAReplacedCoreStringIsStillTheOneReplaced@The name of directories...` |
  | `ws_functions.inc.php` `l10n()` removed | `AddRegionTest::testANamelessRegionIsRefusedInTheAccountsLanguage` |
  | `index.inc.php` `l10n()` removed | `PersonAdminApiTest::testRenamingToAWhitespaceOnlyNameIsRefused` |
* [x] Spot check in the browser for the merge modal
  **Note:** Verified via `.agent-tests/2026-10-17-german-overrides/merge-modal.js` (Playwright in the container, `typetags_webmaster`, three throwaway tags merged through the page's `mergeGroups()`, deleted afterwards). Title: "Schlagwort(e) Task24 Merge A, Task24 Merge B wurde(n) erfolgreich in »Task24 Merge Ziel« zusammengeführt" - no braces. The group merge modal was not opened in a browser; its key is guarded by the unit row only.

## Deviations from the design

* "A person needs a name" was a raw string in `plugins/persons` (`index.inc.php` rename,
  `ws_functions.inc.php` addRegion), never passed through `l10n()`, so an override alone could not
  reach it. Added `PERSONS_NAME_REQUIRED_MESSAGE` in `plugins/persons/include/functions.inc.php`
  and wrapped both sites in `l10n()`; the translation stays in `local/language/de_DE.lang.php` as
  the task says (persons' own `plugin.lang.php` is not loaded in `ws.php`). Its guard row points at
  the constant's `define`. Integration cases: `AddRegionTest::testANamelessRegionIsRefusedInTheAccountsLanguage`
  (new) and a message assertion in `PersonAdminApiTest::testRenamingToAWhitespaceOnlyNameIsRefused`.
* The merge keys and PWG-UPDATE-1 replace core German strings, so they are entries in
  `REPLACED_CORE` (decision 0052's mechanism) with core's current wording, not plain overrides.
  Decision 0052's "Today:" list is not edited; `REPLACED_CORE` is the current list.
* The group merge message keeps core's `"%s2"` quotes; only the braces go.
* The placeholder test also checks `%s1`/`%s2` in both merge messages (`tags.js`/`group_list.js`
  replace them by name).
* "Just now" is translated ("Gerade eben"); showing it once (`cat_modify.js`) is not part of this task.

