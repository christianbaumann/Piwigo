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

* [x] Integration tests first, red: as a logged-in account, each user-reachable failure answers a German message: crop too small, nothing to do, file missing or not writable, edit still running, unsupported type, no image library
* [x] Translate at the ws boundary with `l10n()`; internal messages and log text stay English, so existing assertions on internal results do not change
* [x] de_DE and en_UK strings for every message; a message with a number uses `sprintf`
* [x] The persons refusal message (decision 0065) stays as it is (already German)

## Verification

* [x] New tests failed before the change and pass after it

  **Note:** Verified via a red run of `GermanErrorsTest`/`GermanMessageTest` (English text returned; `photoedit_localize_message()` undefined), then green after the change.
* [x] An English-language account still gets the English text

  **Note:** Verified via `GermanErrorsTest::testAnEnglishAccountGetsTheEnglishText` (account switched to en_UK, restored in tearDown).
* [x] photoedit unit, integration and E2E suites pass; commands named in the notes

  **Note:** Verified via phpunit unit (107 ok), phpunit integration (133 ok), `npx playwright test` (34 passed), all inside the suite lock.

* [x] Every fixed message in pipeline/validation/ws source has a de_DE and en_UK key

  **Note:** Verified via `GermanMessageTest::testEveryFixedMessageInTheSourceHasATranslation` (added by the verifier, 13-message lower bound); a flip renaming one pipeline message went red naming it, restored, `git diff` empty.

## Notes

* "No image library" cannot be forced over HTTP (core falls back to another library); it is covered in-process by `GermanMessageTest`, as are the failed-write messages.
* Existing assertions on English texts in `ApplyCropTest` and `ApplyTurnTest` now expect the German text (the accounts are German). `WsClient` timeout raised 30 to 45 s so the locked case (the edit waits 30 s) can answer.
