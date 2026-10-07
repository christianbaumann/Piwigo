---
id: 02
dependencies:
- 01
---

# Task 02: Info text, end to end

An administrator clicks the Info row in `#imageInfos`, edits the photo's description in a
textarea, saves it, and finds it on the page and in the image file, ahead of the provenance text.
This task also creates `plugins/photoinfo` and its test infrastructure.

## References

* `design.md#a-new-plugin-pluginsphotoinfo`
* `design.md#photoinfo-requires-provenance-and-reuses-its-writer`
* `design.md#info-text-is-cores-comment`
* `design.md#click-to-edit-per-field-administrators-only`
* `design.md#replace-cores-rows-move-the-description-into-the-panel`
* `design.md#one-composed-caption-for-info-and-provenance`
* `design.md#the-file-carries-photoinfos-values-separately-and-a-rescan-rebuilds-them`
* `design.md#ui`
* `.claude/rules/piwigo-architecture.md` (plugin system, web services, code style, security patterns)
* `docs/agents/decisions/0036-prefilters-include-plugin-templates.md`
* `plugins/provenance/main.inc.php`, `plugins/provenance/include/events_public.inc.php` (row injection pattern)
* `plugins/persons/include/` and commit `c85d93519` (an editable row in `#imageInfos`)
* `themes/default/template/picture.tpl:146-330`, `picture.php:830` (`COMMENT_IMG`)
* `plugins/persons/tests/Support/` (test users script, `FixtureBuilder`, `WsClient` to copy the shape from)

## Work

Read the *Handoff to task 02* section of `01-characterize-provenance-writeback.md` first: the argfile, composer, worker, picture-row and `-config` traps it lists all sit on this task's path.

* [x] Create `plugins/photoinfo` (`main.inc.php` header, `maintain.class.php`, core code style; no license banners, matching the sibling plugins); add its `!` entry to `.gitignore`
* [x] Refuse activation when provenance is not active, with a message naming it
* [x] Provenance: add `trigger_change('provenance_caption_parts', $parts, $image)` where the caption is composed, and an optional exiftool config parameter to `provenance_exiftool_run()`; task 01's tests stay green
* [x] photoinfo handles `provenance_caption_parts`: its part (info text) goes first, separated from the provenance part by a blank line
* [x] Declare the `pwginfo` XMP namespace in `plugins/photoinfo/exiftool/pwginfo.config` with an `Info` tag
* [x] `pwg.photoinfo.setInfo` (`admin_only`, `post_only`, `pwg_token`): saves `images.comment`, then writes the composed caption to the five slots and `XMP-pwginfo:Info` under provenance's lock; reports a failed write without losing the saved text
* [x] Picture page: an Info row in `#imageInfos` via prefilter `{include}`; read-only for everyone, click-to-edit textarea for administrators; rendered like core renders `comment` (`render_element_description`)
* [x] The description is no longer shown above the photo
* [x] Test infrastructure: `photoinfo_webmaster`, `photoinfo_admin`, `photoinfo_normal` via a committed `create-test-users.php`; throwaway-install marker; `FixtureBuilder` with a copied photo; PHPUnit and Playwright configs
* [x] Tests at the lowest layer: caption part composition (unit), WS permissions and file content (integration), click-to-edit and save in the browser (E2E)

### Implementation notes (2026-10-07)

Deviations from the design and handoff, with reasons:

* **The filter carries caption blocks, not labelled fields.** `trigger_change('provenance_caption_parts', array('provenance' => <provenance text>), $image)` fires in `provenance_write_back()` only (so the Provenienz row stays provenance-only, as the handoff asked); photoinfo prepends `'photoinfo' => <info text>`, and `provenance_join_caption_blocks()` joins the non-empty blocks with a blank line. `provenance_compose_caption()` is unchanged, so its pinned foreign-key test still holds.
* **No `#[CSTR]`.** The handoff suggested it; measured against exiftool 13.25 it turns `$` into `\$` (and `@` into `\@`). A multi-line value goes into a value file in the operation directory instead, named on its line as `-TAG<=file` ([decision 0038](../../../../docs/agents/decisions/0038-multi-line-exiftool-values-travel-in-value-files.md)). Single-line values keep their plain `-TAG=value` line byte for byte; `BuildArgfileTest::testNewlineInTheCaptionNeverProducesASecondLine` was replaced by `testAMultiLineCaptionNamesAValueFileInEverySlot` (deliberate change: the caption now keeps the blank line).
* **Provenance's own block stays one line** (`provenance_caption_block()` collapses a multi-line note, as before), so a photo without info text gets exactly the old caption.
* **`pwginfo.config` stands alone**, it does not load `pwgprov.config`: photoinfo's runs write the standard caption slots and `XMP-pwginfo:Info`, never a `pwgprov` tag, and provenance's runs never write `pwginfo`.
* **The info text goes into `XMP-pwginfo:Info` as stored** (markup included where `allow_html_descriptions` keeps it), so a later rescan can restore `comment` without loss; the caption slots get it without markup, like provenance's text.

Accepted after review:

* Two concurrent saves of one photo can leave the database with one text and the file with the other: the row is updated, and the caption composed, outside provenance's lock. The database is what the page shows, and the next save repairs the file.
* A provenance write-back runs one `SELECT comment` per photo (at most `PROVENANCE_WRITEBACK_MAX_CHUNK` = 10), because provenance's query does not select `comment`.
* A request without `info` clears the description: `info` defaults to `''`, because core's ws layer treats an empty string as a missing required parameter (`Missing parameters: info`, measured 2026-10-07), so a required `info` could never clear. An `info` that is not a string is refused.
* Saving an empty text on a photo with no provenance deletes all five caption slots, including a caption some other tool put there. Clearing must clear the file (design, *UI*), and a provenance write-back already overwrites those slots.
* **Request values arrive slashed** (`include/common.inc.php` adds slashes to every request value); `setInfo` strips them before saving. Provenance's `setPhotoInfo`/`setAlbumInfo` do not - see *Found, not fixed* below.
* **Row anchor without its tab:** core's `Template::prefilter_white_space` strips leading whitespace before plugin prefilters run, so the anchor is `{if $display_info.posted_on}`.
* The concurrency worker now loads `include/functions_plugins.inc.php`.

Found, not fixed (outside this task): provenance's `setPhotoInfo` escapes the already-slashed value again - `a"b\c'd` is stored as `a\"b\\c\'d` (measured 2026-10-07). Recorded in `docs/backlog.md` and reproduced by the skipped `SetPhotoInfoTest::testQuotesAndBackslashesAreStoredAsTyped` (watched red when un-skipped).

## Verification

* [x] An administrator edits the info text on the picture page; after a reload the new text shows in the Info row and not above the photo **Note:** `e2e/info-edit.spec.js` [HAPPY]; `PicturePageSourceTest::testTheDescriptionShowsInTheRowOnly`
* [x] exiftool reads the info text, then a blank line, then the provenance text from `XMP-dc:Description`, `IPTC:Caption-Abstract` and `EXIF:ImageDescription`, and the info text alone from `XMP-pwginfo:Info` **Note:** `SetInfoTest::testTheInfoTextGoesIntoTheRowAndAheadOfTheProvenanceCaption` (plain exiftool read-back; text with `$`, `@`, quotes, backslash, line break)
* [x] A provenance write-back (album "apply to photos") keeps the info text first in the caption **Note:** `SetInfoTest::testAProvenanceWriteBackKeepsTheInfoTextFirst`, through `pwg.provenance.writeBack`, the method the album screen's write button calls
* [x] A normal user and a guest see the row read-only, and `pwg.photoinfo.setInfo` refuses them **Note:** `SetInfoTest::testOthersAreRefused`, `PicturePageSourceTest` (no form in the source), `e2e/info-edit.spec.js` [NEG]
* [x] Clearing the text removes it from the database and the file **Note:** `SetInfoTest::testClearingRemovesTheTextFromRowAndFile`, `::testClearingTheOnlyTextLeavesNoCaption`
* [x] Activating photoinfo with provenance inactive fails with a message naming provenance **Note:** `PluginActivationTest`
* [x] Provenance suites and photoinfo unit, integration and E2E suites pass twice in a row **Note:** 2026-10-07, both runs: provenance unit 206 OK, integration 188 OK (3 pre-existing skips), photoinfo PHPUnit 38 OK, E2E 6 passed. After the review fixes: photoinfo PHPUnit 43 OK, E2E 6 passed; mutants on caption order, the writable check and the markup strip each killed by exactly their tests
