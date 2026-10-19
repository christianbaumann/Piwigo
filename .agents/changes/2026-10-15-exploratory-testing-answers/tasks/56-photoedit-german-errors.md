---
id: 56
dependencies: []
---

# Task 56: Photoedit failures are reported in German

Added 2026-10-19 (brainstorm Q7 / 5a). Today a failed save shows the server's English message in an alert. After this task, every message a logged-in user can see from `pwg.photoedit.apply` is German.

## References

* `design-handbook-gap-fix.md`, key decision *Photoedit failures are German*
* `plugins/photoedit/include/ws_functions.inc.php` (lines 33, 39, 55: where messages leave the server), `include/pipeline.inc.php` (`photoedit_failure()` callers), `include/functions.inc.php` (crop and plan errors)
* `plugins/photoedit/language/de_DE/plugin.lang.php`, `language/en_UK/plugin.lang.php`
* `plugins/photoedit/template/editor.js` (`window.alert(error.message)`)
* photoedit `tests/Integration/` (`ApplyCropTest`, `FailedWriteTest`, `ExclusionTest`)

## Work

* [ ] Integration tests first, red: as a logged-in account, each user-reachable failure answers a German message: crop too small, nothing to do, file missing or not writable, edit still running, unsupported type, no image library
* [ ] Translate at the ws boundary with `l10n()`; internal messages and log text stay English, so existing assertions on internal results do not change
* [ ] de_DE and en_UK strings for every message; a message with a number uses `sprintf`
* [ ] The persons refusal message (decision 0065) stays as it is (already German)

## Verification

* [ ] New tests failed before the change and pass after it
* [ ] An English-language account still gets the English text
* [ ] photoedit unit, integration and E2E suites pass; commands named in the notes
