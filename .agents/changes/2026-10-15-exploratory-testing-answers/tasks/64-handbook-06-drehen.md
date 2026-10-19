---
id: 64
dependencies:
- 56
---

# Task 64: Chapter 06 warns what a restore brings back and describes failed saves and keys

Added 2026-10-19. Chapter 06 matches the code for every 06 finding of the 2026-10-19 review.

## References

* `design-handbook-gap-fix.md#the-findings-and-where-they-go` (rows for chapter 06)
* `handbuch/06-drehen.html` (*Rückgängig machen*, lines 136-144)
* Decision 0084; task 56; `plugins/photoedit/template/editor.js` (`NAV_KEYS`, `CTRL_NAV_KEYS`)

## Work

* [x] *Rückgängig machen*: the restored copy brings back its own Info, date, tags and person marks; fields the copy lacks keep the gallery's value (decision 0084 table), so only differing changes made after the edit are lost
  **Note:** Verified via photoinfo `UploadMergeTest` (testAFileThatCarriesAllThreeWins, testAFileWithOnlyAnInfoTextKeepsTheGalleryDateAndTags, testAFileWithOnlyADateKeepsTheGalleryInfoAndTags, testABareReplaceWritesTheGalleryValuesIntoTheFile) and persons `UploadMergeTest::testARestoredPreEditCopyIsIndexedFromItsFile`; the browser cannot copy a file back, no ad-hoc check run.
* [x] A failed save shows a message with the reason (German after task 56); edit mode stays open when the write was not yet sent, else the page reloads (`editor.js` `written`)
  **Note:** Verified in the browser 2026-10-19 (photoedit seed `--scenario=neighbours` as photoedit_webmaster, upload folder made read-only): alert "Die Bilddatei fehlt oder ihr Ordner ist nicht beschreibbar.", `.photoedit-active` still set, no reload.
* [x] In edit mode the left/right arrow keys do not change the photo (also Ctrl+Home/End/Up, not mentioned in the text)
  **Note:** Verified in the same run: ArrowRight/ArrowLeft in edit mode left the URL unchanged and edit mode open (also covered by `edit-mode.spec.js`).
* [x] Each new statement checked once in the browser on the demo album; result as a note here
  **Note:** A demo album was already seeded by another agent, so photoedit's own throwaway seed was used instead (restored with `--restore`, `{"restored":true}`).

## Verification

* [x] `check.php` passes for 06
  **Note:** `check.php` reports 3 failures, all unreferenced screenshots 29-31 (other tasks' chapters); none concerns 06.
* [x] Every quoted German string matches the language files
  **Note:** The only new quoted strings are none; the message text is not quoted in the handbook (design).

* [x] Verifier 2026-10-19: ran photoinfo and persons `UploadMergeTest` (9 tests / 4 tests, green); corrected the failed-save paragraph (reload after a failed write), the key claim (left/right only) and the restore paragraph (keywords only when the copy carries the marker, later-added tags lost)
