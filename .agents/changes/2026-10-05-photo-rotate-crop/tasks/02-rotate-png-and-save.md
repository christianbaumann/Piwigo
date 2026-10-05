---
id: 02
dependencies:
- 01
---

# Task 02: Turn a PNG and save it into the file

A webmaster turns a PNG in 90° steps and saves; the file, its metadata, the database row, the COI and the derivatives all reflect the turn after the reload.

## References

* `design.md#web-service-pwgphotoeditapply`
* `design.md#events`
* `design.md#write-pipeline`
* `design.md#change-the-file-not-the-database`
* `design.md#backup-in-_data-no-undo-in-v1`
* `design.md#the-centre-of-interest-is-transformed`
* `design.md#deploy`
* `admin/include/image.class.php` (`pwg_image::rotate()`, `write()`), `admin/include/functions.php:3124` (`delete_element_derivatives()`)
* `admin/picture_coi.php` (`coi` format, `fraction_to_char()`)
* `plugins/persons/include/exiftool.inc.php` (lock, operation dir, binary lookup), `plugins/persons/include/functions.inc.php` (`persons_rotate_region()` as the reference for the turn math)
* `include/ws_functions/pwg.categories.php:755` (WS handler including admin helpers)
* `.claude/rules/deployment.md`

## Work

* [x] Pure functions in `include/functions.inc.php`: request validation (`turns`, `crop`), point/box turn for the COI
  **Note:** Also `photoedit_turned_size()` and `photoedit_rotate_angle()` (`pwg_image::rotate()` turns counter-clockwise). `crop` is validated but the web service does not take it yet; task 03 adds the parameter.
* [x] `pwg.photoedit.apply` (POST, `admin_only`, `is_webmaster()`, `pwg_token`) with `turns`; `dry_run` returns without writing
  **Note:** `post_only` is core's own option (`include/ws_core.inc.php:510`). Errors: 403 not webmaster / bad token, 1003 invalid params or unsupported type, 404, 409 locked, 500 write failed.
* [x] `include/pipeline.inc.php`: own lock, `photoedit_begin`/`photoedit_end` (`end` in `finally` with `ok`), backup to `_data/photoedit/originals/`, rotate via `pwg_image`, temp file + rename, `exiftool -tagsFromFile` from the backup, DB update (`width`, `height`, `filesize`, `md5sum` if set, `coi`), `delete_element_derivatives()`
* [x] Refuse cleanly when exiftool is missing (structured error, editor disabled with the reason); a failed step leaves the original file in place
  **Note:** `$conf['photoedit_exiftool_path']` (like persons'). The button stays, greyed, with the reason as its title; a click shows the reason. The same applies to a non-PNG photo until task 05. The temp file is built in `_data/photoedit/work/<op>/` and renamed over the original only after exiftool has copied the metadata back, so every failure before the rename leaves the file untouched.
  **Note (deviation, small):** a GD guard in the pipeline converts a palette image to truecolour before turning. libgd aborts the whole PHP process on a 180° turn of a palette PNG (measured 2026-10-05; real scans are truecolour and unaffected). Core's own GD path has the same crash; only photoedit's is guarded.
* [x] `editor.js`: `↺ ↻` turn the preview, `Speichern` posts, then reloads
  **Note:** CSS `rotate()` + `scale()` on `#theMainImage` (the theme zooms by layout size, not transform, so they don't collide). Save posts a dry run first and asks for confirmation only when `lost_regions` is non-empty (always empty until task 04). Save with no turn just leaves edit mode.
* [x] Integration fixtures: a copied PNG carrying an XMP caption and a set COI
  **Note:** Generated rather than copied: `FixtureBuilder::createMarkedImage()` makes a 300x200 palette PNG with a red 30 px marker top-left, an XMP caption, COI `fakj` and an md5sum, and checks each precondition. A copied scan has no marker to locate a turn by. Also `seed.php --scenario=marked` for E2E.
* [x] Document the edit-locally → commit → deploy → remote rescan flow in `.claude/rules/deployment.md`, including that `PLUGINS_TO_ACTIVATE` in `tools/deploy/pwgdeploy/bootstrap.py` leaves photoedit off on the remote on purpose (edits are made locally)
  **Note (design gap found):** the deploy's sync re-reads metadata only for photos new to the remote (`sync_meta` without `meta_all`, `admin/site_update.php:841`). An edited photo already on the remote keeps its old `width`/`height` there. Documented as a manual Batch Manager step ("Synchronisieren von Metadaten") in `.claude/rules/deployment.md`; not fixed in the deploy tool. The remote's derivatives must be deleted by hand too ("Mehrfache Bildgrößen entfernen"): core links an existing derivative directly and never compares it with the source's mtime (corrected in review; the first version of the doc wrongly said `i.php` rebuilds them).

## Verification

* [x] `[HAPPY]` After one `↻` and save, the file's width/height are swapped, read back by ImageMagick `identify`
  **Note:** Verified by `ApplyTurnTest::testAQuarterTurnIsWrittenIntoTheFileAndTheRow` (server) and `turn-save.spec.js` `[HAPPY]` (clicks ↻ and Save, after the reload the shown file is portrait).
* [x] `[ECP]` `turns` 1, 2, 3 each give the expected orientation (a pixel marker in the fixture lands in the expected corner)
  **Note:** Verified by `ApplyTurnTest::testTheMarkerLandsInTheTurnedCorner`, 3 turns × 3 libraries (ext ImageMagick, Imagick, GD, forced via the `graphics_library` config row), pixels read with `convert … info:`. A clockwise angle instead of counter-clockwise turned 6 of 9 red.
* [x] The XMP caption is still in the file, read by ImageMagick (`convert <file> xmp:-`)
  **Note:** Verified by `ApplyTurnTest::testTheCaptionIsStillInTheFile` per library. With ImageMagick alone, removing the exiftool copy-back *survived*: ImageMagick keeps PNG metadata by itself. Only GD drops it, so the GD case is the one that kills that mutant.
* [x] DB `width`/`height`/`filesize` match the new file; the COI is turned; old derivatives are gone and the picture page shows the turned photo
  **Note:** Verified by `ApplyTurnTest` (`…IntoTheFileAndTheRow` incl. `md5sum` and `rotation`, `testTheCentreOfInterestTurns` `fakj`→`qfzk`, `testOldDerivativesAreDeleted` with an i.php-made derivative as anti-vacuity) and `turn-save.spec.js`.
* [x] A backup with the original bytes exists under `_data/photoedit/originals/`
  **Note:** Verified by `ApplyTurnTest::testTheOriginalIsBackedUp` (md5 of the backup equals the original's).
* [x] `[NEG]` `photoedit_admin`, `photoedit_normal`, a GET request and a missing token are refused, and the file is unchanged (md5)
  **Note:** Verified by `ApplyTurnTest::testOtherAccountsAreRefused`, `testAGetRequestIsRefused`, `testABadTokenIsRefused` (missing and wrong). Removing the webmaster check, the token check or `post_only` each turned its test red.
* [x] `[BVA]` `turns` = -1 and 4 are refused; `turns` = 0 with no crop is refused as "nothing to do"
  **Note:** Verified by `ValidateRequestTest` (unit, plus fractions, text, arrays and malformed crops) and `ApplyTurnTest::testTurnsOutsideTheRangeAreRefused` for the wiring. `>`→`>=` and `and`→`or` mutants killed.
* [x] `[ERR]` A failing write (e.g. read-only file) leaves the original file and DB row unchanged and fires `photoedit_end` with `ok = false`
  **Note:** Verified in-process by `FailedWriteTest` (`tests/Support/PiwigoRuntime.php`). A read-only *file* would not fail: the rename only needs the directory. So the photo's directory is made read-only, and the rename fails. A control test proves `ok = true` on success; `$ok = true` as a mutant turned it red.
* [x] `dry_run = 1` changes nothing (file md5, DB row)
  **Note:** Verified by `ApplyTurnTest::testADryRunChangesNothing` (md5, whole row, no backup) and `FailedWriteTest::testADryRunFiresNoWriteEvents`.
* [x] All three photoedit suites green twice and in reverse order
  **Note:** After the review fixes: unit 51, integration 36 (`--order-by=default` and `reverse`), E2E 18 twice (2026-10-05). Persons and provenance suites were not re-run: no file of theirs changed.
* [x] (added in verify) A real-size scan copy turns, saves and fits the viewport in a real browser
  **Note:** Ad-hoc Playwright run, 2026-10-05: 3540x2383 truecolour copy, default library, save 3.1 s, portrait afterwards and inside 1440x900. Not kept as a regression test (duplicates `turn-save.spec.js`).
* [x] (added in review) A later visit shows the turned photo, not the one the browser cached before the edit
  **Note:** Verified by `turn-cache.spec.js`, which failed before the fix (cached 1008x678 landscape shown) and passes after it, and `ApplyTurnTest::testTheEditedPhotosUrlsCarryTheNewVersion` (`?v=` in the page source).

## Mutants run (by hand, per `.claude/rules/mutation-testing.md`)

All killed after waiting for Mutagen **and** opcache's `revalidate_freq` (2 s; without the wait the webmaster-check mutant falsely survived, now recorded in the rule): no exiftool copy-back (GD caption case), no webmaster check, no token check, `post_only` off, no COI update, no derivative deletion, `photoedit_end` always ok, no md5 update, dry run writes, no backup, no GD palette guard (FPM aborts → 502), clockwise angle, `turns >` → `>=`, nothing-to-do `and` → `or`, crop `l >= r` → `l > r`, box turn `t` edge. The full table for the plan goes in task 06.

## Review (2026-10-05)

A subagent reviewed the task. Fixed:

* **Stale photo in the browser** (confirmed by a failing E2E first): an edited photo keeps its URLs, and nginx sends derivatives with only `Last-Modified`. **Design addition:** the plugin hooks `get_derivative_url` / `get_src_image_url` and appends `?v=<12 hex of the file's md5>` to the photos it edited. The versions live in the `photoedit_versions` config row (JSON). A `delete_elements` handler drops deleted photos from it. Locally only: the plugin is inactive on the remote.
* deployment.md claimed the remote's derivatives rebuild by themselves; they do not. Corrected (manual delete step).
* Row re-read after taking the lock; `catch (Throwable)`; `photoedit_begin` inside the `try`.
* Backup name carries microseconds and refuses an existing file (no backup overwrite).
* Pre-check is the folder's writability (what the rename needs); the new file keeps the original's permissions.
* A stored `rotation` is refused until task 05.
* exiftool probe for the button cached per session.
* Error messages to the client carry no server paths; the details go to the log.
* editor.js: a turn while saving is ignored (Enter on a focused control); the requested turns are captured at Save; after the write was sent, any error reloads the page instead of offering a second save; `response.ok` is checked.
* Template: dead `data-saving` removed, token escaped.
* Tests: every refusal asserts its error code; `FailedWriteTest` fails after `photoedit_begin` (read-only backups folder), checks the work dir is gone and the lock is free; read-only photo folder is a separate up-front `[NEG]`; `photoedit_turn_coi` moved to the pure file with unit cases (null, empty, full box); E2E four-turn wrap, in-flight turn, unreadable write answer.

Not fixed, with reason:

* The plugin's `Save` / `Cancel` language keys shadow core's identical keys; same wording, no visible effect.
* Re-read after the lock, Throwable catch and backup naming have no dedicated test: forcing two writers or a library exception is an apparatus out of proportion to the change; the code paths are short.
* Mutant "no `photoedit_lock_release()` in finally" **survives** and is equivalent: `$lock` goes out of scope when `photoedit_apply()` returns and PHP closes the handle, which releases the `flock`.

Further mutants, all killed: no work-dir removal, file-writable instead of folder-writable pre-check, editor.js turn guard removed, editor.js reload-after-write removed.

**Status: approved 2026-10-05.**
