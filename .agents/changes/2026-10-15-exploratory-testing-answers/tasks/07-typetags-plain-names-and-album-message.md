---
id: 07
dependencies: []
---

# Task 07: typetags: plain tag names in ws answers; clear `addNewTag` refusal

API clients get plain tag names, and `addNewTag` on a photo in no visible album says why.

## References

* `design.md#the-api-returns-plain-tag-names`
* `design.md#addnewtag-keeps-its-album-check`
* `plugins/typetags/main.inc.php` (`typetags_image_visible()`)

## Work

* [x] Colour markup only on rendered pages, never in a `ws.php` answer (`pwg.tags.getList`, `pwg.images.getInfo`, any other tag-returning method)
  **Note:** `typetags_render()` (`include/events_public.inc.php`) returns the plain name whenever `script_basename() == 'ws'`, replacing the old per-method list (`getAdminList`, `rename`). Only admin JS consumes tag-returning ws methods, and admin pages were already plain.
* [x] `addNewTag` refusal text "Foto ist in keinem sichtbaren Album"; decision file for keeping the check
  **Note:** `l10n('Photo is in no visible album')`, code stays 404; German in `local/language/de_DE.lang.php`, guard row in provenance's `GermanOverrideKeyTest`, key count in `.claude/rules/handbook.md` now 25. Decision `docs/agents/decisions/0070-addnewtag-keeps-its-album-check.md`. Deviation: `addTag`/`removeTag` keep `Image not found` (task names only `addNewTag`).
* [-] Submodule commit, pushed
  **Note:** committed `6a5b7b4`; push refused (403, git used account `dark-sith-lord`), same as `b0c63cc`.

## Verification

* [x] Integration: typed tag names in `pwg.tags.getList` and `pwg.images.getInfo` equal the stored names
  **Note:** Verified via `WsPlainTagNameTest` (renamed from `AdminTagListTest`); mutant (ws clause removed) turned 4 of 5 red, the rendered-page anti-vacuity case stayed green.
* [x] Picture page and tag pages still show colours (E2E)
  **Note:** Verified via existing `rendering.spec.js` (picture page) and `taggroups.spec.js`, all 56 E2E green; `index.php?/tags/<id>` badge asserted in integration `WsPlainTagNameTest::testARenderedPageStillGetsTheBadge`.
* [x] Integration: admin `addNewTag` on a photo in no album returns the new text
  **Note:** Verified via `AddNewTagTest::testAPhotoInNoAlbumIsRefusedWithTheReasonEvenForAWebmaster`; red with the old message restored.
* [x] typetags suites pass
  **Note:** Verified 2026-10-15: unit OK (88), integration OK (159), E2E 56 passed; provenance unit OK (276).
