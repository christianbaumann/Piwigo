---
id: 44
dependencies:
- 33
---

# Task 44: The photo Notiz has its own heading and a "Notiz speichern" button

Added 2026-10-17 from task 33's finding (user's answer a): the photo properties page shows two "Einstellungen sichern" buttons, the Notiz's own and core's save bar.

## References

* Task 33 (`plugins/provenance/template/photo_provenance.tpl`, `album_provenance.css`)
* `.claude/rules/handbook.md` (a screenshot or handbook text naming the button)

## Work

* [x] The Notiz block gets a heading and its button reads "Notiz speichern" (language file entry; German override test if a core string is touched)
  * **Note:** done in `f3f576ad9` (`Save note` in provenance's de_DE/en_UK lang files, `photo_provenance.tpl`; no core string touched); boxes ticked 2026-10-18 when the file was found unticked
* [x] Handbook text and screenshots naming the old label updated (`handbook.md` cycle only if a shot shows it)
  * **Note:** done in `f3f576ad9` (`handbuch/03-fototexte.html`, shot 10 re-shot)

## Verification

* [x] E2E: the page shows one "Einstellungen sichern" and one "Notiz speichern"; the Notiz still saves. New test failed first (heading missing), then passed; photo-provenance.spec.js 13 passed, incl. the existing save tests.
* [x] provenance suites pass; `check.php` passes. Unit 307 tests OK; photo E2E spec 13 passed; check.php OK. Integration suite not run (no PHP/ws change).

## Deviations

* Shot 10 now looks different beyond the label: task 33 moved the block outside core's form, so the committed shot predated its layout (wide, label left of the textarea, textarea styled differently). The new shot shows the current page; layout then polished (verify pass 2026-10-17): `album_provenance.css` repeats core's `#picture-content` field look (font, padding, radius, width) for the block, which sits outside that container; the Notiz label is above the textarea and the button below it. Shot 10 re-shot (seed/shoot/restore; other shots reverted, categories back to ids 1-5); photo-provenance.spec.js 13 passed, check.php OK.
* The heading is an element role, not a new text: the block already carried the "Herkunft" label, which the handbook uses as the block's name.
