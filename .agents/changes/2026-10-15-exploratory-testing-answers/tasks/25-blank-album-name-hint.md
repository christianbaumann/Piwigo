---
id: 25
dependencies:
- 02
---

# Task 25: A blank album name shows a hint

Saving a new album without a name tells the user why nothing happened.

## References

* `design.md#a-blank-album-name-shows-a-hint`

## Work

* [x] Show "Name fehlt" next to the field in the create-album dialog
  * `plugins/galleryrules/include/events_album_name.inc.php`: `loc_begin_admin_page` on
    `admin.php?page=albums` registers a prefilter on core's `albums.tpl` that puts
    `{include file='<abs>/template/album_name_hint.tpl'}` right after the create dialog's name field
    (`GALLERYRULES_ALBUM_NAME_FIELD`, decision 0036). No core edit.
  * `template/album_name_hint.tpl`: a hidden `<span class="galleryrules-album-name-missing">` ("Name
    fehlt", `language/de_DE/plugin.lang.php`) and a script with capture-phase listeners that run
    before core's jQuery handlers: Hinzufügen (click) or Enter with a name that trims to empty shows
    the hint and stops core's handler, so nothing is sent; typing a non-blank name, or opening the
    dialog again, hides it.
  * Cause found: core's dialog sends the blank name to `pwg.categories.add`, which refuses it with
    an HTTP error; `albums.js` only logs that error (`console.log`), and its own
    "Der Albumname darf nicht leer sein" is reached only on an HTTP 200 failure.

## Verification

* [x] E2E: blank name shows the hint
  **Note:** Verified via `tests/e2e/album-name-hint.spec.js` (empty name + Hinzufügen; spaces + Enter;
  hint hidden again by typing, then the album is created and removed by `--restore`). Integration
  `AlbumNameHintTest::testTheCreateDialogHoldsTheHiddenHint` (admin, webmaster: hidden hint once, in
  German, inside `#AddAlbum`). Unit `AlbumNameHintPrefilterTest` (4: structural guard that core's
  field occurs once, include right after it and before the rename dialog, include path exists, other
  template unchanged). Flips in the container under the suite lock, EXIT-trap restore: guard no-op
  -> 3 E2E red; prefilter on a wrong handle -> 2 integration + 3 E2E red; Enter listener off -> the
  Enter case red; hide-on-input off -> the typing case red; include before the field -> 1 unit red;
  anchor without the label text -> 2 unit red. Surviving: dropping the replace limit `1` (the anchor
  matches once in core, which the guard test pins; equivalent).
* [x] Integration/E2E: no album created
  **Note:** Verified via E2E (no `pwg.categories.add` request sent, album count from the database
  unchanged, `seed.php --album-count`) and `[ERR]` integration `testCoreCreatesNoAlbumForABlankName`
  (empty and spaces only through `pwg.categories.add`: refused, count unchanged).
* [x] galleryrules suites pass
  **Note:** Verified 2026-10-17 under `flock /tmp/pwg-suite.lock`: unit OK (42 tests), integration OK
  (67) forward and `--order-by=reverse`, Playwright 14 passed. Hand look at the dialog
  (`.agent-tests/2026-10-17-album-name-hint/hint.png`): "Name fehlt" in red directly under the name
  field.

## Deviations from the design

- The hint stops the save in the browser; no request is sent. The server's refusal (core's
  `create_virtual_category()`) stays the backstop and is pinned by an `[ERR]` integration case.
- "Next to the field" is directly under it (the label stacks its input); the hint is the plugin's own
  element, not core's `.AddAlbumErrors` box below the Position radios.
- Only the create dialog of Alben > Verwaltung (`admin.php?page=albums`) is covered, the dialog the
  session used. The add-album dialog of Fotos hinzufügen / Batch Manager (`include/add_album.inc.tpl`)
  was not examined.
