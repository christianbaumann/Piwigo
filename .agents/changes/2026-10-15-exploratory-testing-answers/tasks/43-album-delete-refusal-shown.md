---
id: 43
dependencies:
- 39
---

# Task 43: Both album delete screens show a refused delete's message

Added 2026-10-17 from task 39's gap (user's answer a). A refused `pwg.categories.delete` (409, decision 0083) shows the server's message on the album properties page and in Alben > Verwaltung instead of only logging it.

## References

* Task 39, decision 0083
* core `admin/themes/default/js/cat_modify.js` (delete button `.catch`), `admin/themes/default/js/albums.js` (`.DeleteAlbumSubmit` `error:`)
* `.claude/rules/fork-local-core-edits.md`

## Work

* [x] Characterize the touched core area first (`[ERR]`, own commit), watch each case go red when the behaviour is broken
  * Nothing meaningful to characterize: the old behaviour is a `console.log` with no visible effect, and the surrounding behaviour (an allowed delete) is covered by the two [HAPPY] specs, which passed before the change. See Deviations.
* [x] Both screens show the server's message (guarded `JSON.parse`, text node), the album stays, the dialog state is usable afterwards
  * `cat_modify.js`: `delete_album()` rejects with an `Error` carrying the parsed `message` (guarded), the `.catch` calls task 35's `show_save_error()` (`.text()`, falls back to the page's own error text). `albums.js`: the `error:` handler puts the message in `.DeleteAlbumErrors` via `.text()`; the dialog stays open, the error is hidden again in `openDeleteAlbumPopIn()`.
* [x] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [x] E2E: deleting a photo's only album with "keep photos" shows the refusal on both screens, the album stays
  **Note:** Verified via galleryrules `album-delete-refusal.spec.js`; both cases red before the JS change (error line / `.DeleteAlbumErrors` hidden), green after. Second case also cancels the dialog, finds the album still in the tree, reopens the dialog and asserts the error is gone.
* [x] E2E: an allowed delete still works on both screens
  **Note:** Verified via the two [HAPPY] cases of the same spec (empty seeded album), green before and after the change.
* [x] provenance and galleryrules suites pass
  **Note:** Under the suite lock, 2026-10-17: galleryrules unit OK (49), integration OK (82), E2E 27 passed; provenance unit OK (307), `album-properties.spec.js` 9 passed. Provenance integration not re-run: no PHP changed. `piwigo_categories` held 5 rows (ids 1-5) after the runs.

**Verification 2026-10-17 (flip checks, under the suite lock, unique backups, EXIT-trap restore, `_data/combined/` cleared):** dropping `show_save_error(...)` in `cat_modify.js` turned the properties case red (1 failed, 8 passed); replacing the `albums.js` `.text(...).show()` with `.text("")` turned the refusal case and the no-body case red (2 failed); dropping only the `statusText` fallback turned the no-body case red (1 failed). Baseline 9 passed. Messages are inserted with `.text()`, so no HTML from the server is interpreted. `delete_orphans` and the allowed deletes share the same handlers and are covered by the happy cases.

## Deviations

- No separate characterization commit: the change is browser-only and the old behaviour is a console line, so the failing new-behaviour spec (red first, 2 of 4) plus the two passing happy-path cases stand in for it. The spec is one commit with the JS.
- The E2E lives in galleryrules (owner of the refusal) with its own page-object methods and the existing `same-name` (refused) and `empty` (allowed) seeds; provenance's `seed.php` was left alone (task 42 under verification).
- A refusal without a parsable body shows task 35's generic save-error text on the properties page and, on the albums screen, the HTTP status text (`xhr.statusText`; no translated generic string exists on that page and none was added). Found empty by verification and fixed; covered by the third [ERR] spec case (500 text/html routed in Playwright).
