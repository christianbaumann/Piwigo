---
id: 37
dependencies:
- 01
---

# Task 37: modus: long words wrap, zoom scroll bar in the window

No long word widens a page, and the zoomed photo's scroll bar is visible.

## References

* `design.md#album-description-keeps-lines-long-words-wrap`
* `design.md#the-zooms-scroll-bar-sits-inside-the-window`
* decision 0032

## Work

* [x] Characterize the touched core area first: the existing persons zoom/fit/pan specs and provenance `roles.spec.js` are the net for the zoom (passing before the change, 59 + 14 passed); a CSS overflow defect has no passing as-is characterization, so the new-behaviour specs were written failing first (see Deviations)
* [x] `overflow-wrap: anywhere` for tag names and `.additional_info` (`themes/modus/css/base.css.tpl`)
* [x] `themes/modus/js/photo.zoom.js:98` subtracts `#theImage` padding-bottom from `max-height`
* [x] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [x] E2E: 255-character tag and long description word, no horizontal page scroll
  **Note:** Verified via provenance `long-words.spec.js` (album page, tag letter list at 1920, tag's photo list, at 1920 and 390): 5 red with the rule neutralised, 9 green with it, repeated 3x green
* [x] E2E: zoomed photo's horizontal scroll bar bottom within the window at 1080 and 900 high
  **Note:** Verified via persons `picture-zoom-scrollbar.spec.js`: red before (1100 > 1080, 920 > 900), green after
* [x] provenance E2E `roles.spec.js` zoom still passes
  **Note:** Verified via `cd plugins/provenance && npx playwright test long-words roles`: 14 passed
* [x] Relevant suites pass, each named with the command that ran (`.claude/rules/plugin-test-suites.md`)
  **Note:** persons `npx playwright test picture-zoom-scrollbar picture-zoom picture-fit picture-pan`: 59 passed; provenance `npx playwright test long-words roles`: 14 passed; both under `flock /tmp/pwg-suite.lock`

## Deviations

* No `[ERR]` characterization commit: a long word widening the page and a scroll bar below the window are defects, not behaviour worth recording. The new specs were written first and watched red instead; the zoom behaviour itself was already covered by persons' picture-zoom/fit/pan specs and provenance's `roles.spec.js`, green before the change.
* Selectors beyond the design's "tag names and `.additional_info`": `.titrePage H2` (the tag page's title) and `#selected-tags-container .selected-related-tag` (the chip on the tag's photo list) were the elements that actually widened the page; `.tagLetterContent a` for the letter list.
* Not fixed, no test: the tag cloud (`tags.css`: `#fullTagCloud SPAN { white-space: nowrap }` defeats `overflow-wrap`) because the cloud shows only the top 200 tags by count, so a one-photo seed tag is not reliably in it; and the tag letter list is 938 px wide on a 390 px phone with or without a long tag (its column table), so its phone case is not in the spec.
* The E2E for the wrap lives in provenance (owner of core characterization) with its own seed `tests/e2e/support/wrap-seed.php`, because `seed.php` was being edited by other tasks; it creates one album, one photo copy and one tag, and `--restore` removes them. The zoom E2E is a new file in persons, whose `PicturePage` already has `zoomToNatural` and the seed `empty` gives a photo.
* The scroll bar's thickness is not asserted: headless Chromium draws overlay bars of no layout width; the oracle is the area's bottom edge against the window.
* `plugin-test-suites.md` (498/500 lines) not extended; the new specs are named in `fork-local-core-edits.md` instead.
* Both gaps above are in `docs/backlog.md` (misc).
* Verification pass: with the padding subtraction removed under the suite lock, `picture-zoom-scrollbar` failed at 1080 and 900 high, file restored byte-identical. `wrap-seed.php` now writes its state after every creation (album, photo, tag), so a seed killed halfway is restorable; seed, second seed (refused, exit 1) and `--restore` leave 18 albums / 16 tags / 106 photos, as before.
