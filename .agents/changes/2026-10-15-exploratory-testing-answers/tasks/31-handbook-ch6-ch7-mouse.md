---
id: 31
dependencies: []
---

# Task 31: Handbook ch6 and ch7; marking stays mouse-only

ch6 names the restore path, ch7 describes the narrow window as it works, and the keyboard gap is recorded.

## References

* `design.md#ch6-names-the-restore-path`
* `design.md#ch7-describes-the-narrow-window-as-it-works`
* `design.md#marking-a-person-stays-mouse-only`

## Work

* [x] `handbuch/06-drehen.html`: webmaster restores via the replace option; user names photo and edit time
* [x] `handbuch/07-fotoseite.html:58-66`: "knapp das linke/rechte Drittel"; − and + hidden, keys still zoom, zoom kept
* [x] `docs/agents/decisions/0078-marking-a-person-needs-a-mouse.md` (0069 was taken)
* [x] Hand-check rows in `docs/agents/TESTING.md`

## Verification

* [x] `ddev exec php handbuch/tools/check.php` passes
  **Note:** OK, 8 pages, 74 references, 28 screenshots.
* [x] Ledger rows dated
  **Note:** three rows dated 2026-10-17 in `docs/agents/TESTING.md`; reading quality stays a hand check.
* [x] Relevant suites pass, each named with the command that ran (`.claude/rules/plugin-test-suites.md`)
  **Note:** provenance integration `--filter HandbookAccessTest` under the suite lock: OK (27 tests, 454 assertions).

## Deviations

* The decision file is `0078-marking-a-person-needs-a-mouse.md`; 0069 is `the-remote-shows-no-php-errors`.
* The design's line numbers (`07-fotoseite.html:58-66`) are off; the paragraphs are found by text.
* ch6: the design says "through the replace option". Verified against the code: the replace matches a photo of the same file name in the album, and the kept copy is named `<id>-<timestamp>.<ext>`. The text therefore says the webmaster renames the copy like the photo's current file before uploading. The replace also copies the state it overwrites (decision 0072), which the text mentions.
* ch7: code agrees with the design (side zones 0.3/0.7 in `photo.autosize.js`; `.zoomStep` hidden under `html:not(.wide)`; keys independent of layout; mode kept on resize). The sentence says "-" and "+" are missing in the narrow window next to the existing "only Einpassen and 100 %" sentence.
* No screenshot changed, none re-shot.
