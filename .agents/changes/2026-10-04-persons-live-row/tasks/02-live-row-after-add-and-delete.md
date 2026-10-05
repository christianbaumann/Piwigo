---
id: 02
dependencies:
- 01
---

# Task 02: Update the Personen row live after adding or deleting a person

A user who tags or removes a person sees the Personen row change at once, without a reload.
This includes the first face on a photo that had none.

## References

* `design.md#the-empty-row-container-is-always-emitted`
* `design.md#plain-text-names-in-the-live-row`
* `design.md#rebuild-the-row-from-the-returned-region-list`
* `design.md#delete-updates-the-row-too`
* `plugins/persons/template/public_persons.tpl`
* `plugins/persons/include/render.inc.php` — `persons_assign_overlay()` (the selection rule to mirror)
* `plugins/persons/include/ws_functions.inc.php` — `persons_regions_payload()` (returned by both methods)
* `plugins/persons/template/editor.js` — `commit()`, the delete click handler

## Work

* [x] `public_persons.tpl`: when `$display_info.persons` is on, always emit `#Persons`, with
  the `hidden` attribute while `$PERSONS_NAMES` is empty. Server-rendered content stays as it is
  otherwise
  **Note:** needed an extra `#Persons[hidden] { display: none }` in `overlay.css` - the theme's
  `.imageInfo { display: table-row }` beats the `hidden` attribute (seen red without it)
* [x] `editor.js`: one function that rebuilds the row's `<dd>` from `data.result.regions`
  (`type === 'Face'`, first occurrence per name, region-id order, plain text via `textContent`)
  and toggles `hidden`. It does nothing when `#Persons` is absent (row switched off, admin
  screen)
  **Note:** `renderPersonRow()`; keeps the list's order (both PHP and ws use
  `persons_indexed_regions()`, `ORDER BY r.id`) instead of re-sorting
* [x] Call it after a successful `pwg.persons.addRegion` and a successful `pwg.persons.deleteRegion`.
  Do not call it on `stat: "fail"`
* [x] Integration (written first, watched red): `[ECP]` a face-less photo has a hidden, empty
  `#Persons`. Replaces the `[ERR]` case from task 01. Record the mapping in the commit message
  **Note:** `testAPhotoWithNoFaceCarriesNoPersonRow` -> `testAPhotoWithNoFaceCarriesAHiddenEmptyPersonRow`;
  `testThePersonRowIsRenderedInsideTheStandardInfoList` also asserts a row with names is not hidden
* [x] E2E (written first, watched red), replacing task 01's "no live update" case:
  * `[HAPPY]` a saved name appears in `#Persons` with no navigation
  * `[BVA]` first face on an empty photo unhides the row; deleting the last face hides it
  * `[ECP]` a second box with an already listed name does not list it twice
  * `[NEG]` a refused save leaves the row unchanged
  * `[ECP]` row text before `page.reload()` equals row text after it (drift check between the
    PHP and JS rules)
  * `[NEG]` markup in a name renders as text in the live row

  **Note:** describe `the person row while tagging`, 6 specs; task 01's two row `[ERR]` specs
  removed, its exit specs moved to `leaving tagging mode (current behaviour)`. The refused save is
  a real server refusal (`setExiftool('missing')` after load); the markup case rewrites the
  `addRegion` response via `page.route` because the write path strips tags. Watched red: 5 of 6
  on unchanged code; the `[NEG]` refused-save spec holds today and was seen red against a break
  (row emptied on `stat: fail`). Further breaks, each killing exactly its spec: `innerHTML` ->
  markup; `unshift` -> happy + drift; no dedup -> duplicate; never hidden / CSS rule dropped -> BVA.
  Drift spec deletes before it adds - see the deviation below
* [x] Clear `_data/templates_c/` after the template change (prefilter-embedded template)

## Verification

* [x] On the public page, tagging a new person shows the name in Personen without reload
  **Note:** E2E `a saved name appears in the row with no navigation` (asserts `sameDocument()`)
* [x] Deleting the only face hides the row; the next face shows it again
  **Note:** E2E `the first face unhides the row and deleting the last one hides it`
* [x] With the Personen row switched off in the admin settings, nothing appears and no JS error is logged
  **Note:** verified with a temporary spec (key switched off in the DB, save + delete, no
  `pageerror`/console error, editor message not in error state, no `#Persons`). It went red with
  the `!personRow` guard removed. The TypeError then lands in the promise's `.catch`, so the
  editor's error state is what shows it, not the console. Spec deleted after the run
* [x] A guest still gets no `#Persons` in the page source
  **Note:** `PicturePageSourceTest::testAGuestSeesNoOverlay`, unchanged and green
* [x] The admin tagging screen still saves and deletes with no console error
  **Note:** promoted to a regression spec during /verify: `admin.spec.js` `saving and deleting
  here, with no person row, reports no failure`. Green; red with the `!personRow` guard removed
* [x] Persons unit, integration and E2E suites pass twice in a row
  **Note:** unit 114 OK; integration 105 OK (1 skipped); E2E 39 passed, 1 skipped (the new
  `test.fixme`), both runs

## Deviations and findings

* **CSS rule added** (`overlay.css`, not in the design's file list): needed because of the theme
  rule noted under the template item above.
* **Pre-existing bug found, not fixed:** every write re-indexes the photo and renumbers all its
  region ids. Boxes already on the page keep their stale ids, so after the first save, deleting
  another box is refused. `commit()`'s "first unknown id" pick probably gives a new box a
  *different* region's id. Recorded in `docs/backlog.md` (section *persons*) and as the skipped
  spec `a box rendered before a save can still be deleted after it`, which was seen red with the
  skip lifted. The drift spec deletes before it adds so it does not depend on this bug.
  **Fixed afterwards on user request** ([decision 0033](../../../../docs/agents/decisions/0033-region-ids-survive-a-reindex.md)):
  the reindex carries the id of an unchanged region (`persons_carry_region_ids()`). The skip is
  lifted, a spec for the worse variant was added (seen red: deleting the box just added removed a
  seeded face from the file), and the backlog entry was removed.
  Commits `6dad23355` and `73ee20590`. The second follows a review: the reindex now takes the photo's lock,
  `commit()` adopts the highest unknown id, and the boundary tests cover every coordinate column.
  Verified 2026-10-04: unit 130, integration 108 (1 skipped), E2E 43 passed.
* **Known PHP/JS rule difference, accepted:** `persons_assign_overlay()` leaves out a face whose box
  `persons_display_box()` cannot draw (centre off the photo, zero size); the API list does not.
  Such a name shows in the live row until "Done". Documented in `renderPersonRow()`; the drift
  spec cannot see it because the seed writes only in-frame regions.

## Review (/verify)

Subagent review found no bugs. Applied: re-added the anti-vacuity guard in the refused-save spec;
dropped the redundant `setExiftool('present')` from `afterEach` (`restore()` deletes that row
unconditionally); documented the rule difference above. Not applied: a null check for the row's
`<dd>`, because the plugin owns that template. Row describe re-run green, 8/8. The admin-screen
check was promoted to a regression spec.

**Task approved** after /verify.
