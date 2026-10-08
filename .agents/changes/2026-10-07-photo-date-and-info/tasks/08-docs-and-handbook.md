---
id: 08
dependencies:
- 05
- 06
- 07
---

# Task 08: Documentation and handbook

The German handbook explains how to set a photo's date and info text, and the project docs
describe the new plugin, so the next person or agent finds both.

## References

* `design.md` (all key decisions)
* `.claude/rules/handbook.md` (seed, shoot, restore, `check.php`; never photograph the real gallery)
* `.claude/rules/backpressure.md` (decision log, length budgets, keep instructions honest)
* `CLAUDE.md`, `.claude/rules/plugin-test-suites.md`, `.claude/rules/piwigo-dev-environment.md`, `.claude/rules/deployment.md`
* `docs/agents/decisions/`, `docs/agents/TESTING.md`
* `local/language/de_DE.lang.php`, `plugins/provenance/tests/Unit/GermanOverrideKeyTest.php`

## Work

* [x] Decision records for the design's key decisions that later work must not re-argue (at least: date model, camera-metadata rule, caption composition, photoinfo requires provenance) **Note:** `docs/agents/decisions/` 0040 (date model, EDTF), 0041 (camera-metadata rule, re-sync protection, the `use_iptc` gap), 0042 (caption composition), 0043 (requires provenance, rescan, the five EDTF forms it reads, the deploy's rescan). 0038 and 0039 came from tasks 02 and 06
* [x] `CLAUDE.md`: photoinfo in the fork overview (no core change, depends on provenance); keep under 100 lines **Note:** the existing line now names the date and "no core change" and links decisions 0040-0043; 82 lines
* [x] `.claude/rules/plugin-test-suites.md`: photoinfo suite commands, test accounts, what the suites mutate **Note:** commands and accounts were already there from tasks 02-07; added `RescanTest`'s `provenance_exiftool_path` row and the scope of `SyncMetadataTest`'s syncs
* [x] `.claude/rules/piwigo-dev-environment.md`: the `.gitignore` entry, any `_data/` use **Note:** `.gitignore` entry was already listed; added the rescan's `rescan.xml` under `_data/provenance/args/<op>/`
* [x] `.claude/rules/deployment.md`: rescan after deploy **Note:** new section *The deploy rescans photoinfo's values*; test count 472
* [x] `docs/agents/TESTING.md`: hand checks with no automated oracle **Note:** three ledger rows (handbook text against the code, screenshots, German wording: open for the owner) and three *Open* rows (exiftool 12.76 on the remote, third-party viewers, `use_iptc`)
* [x] Handbook: a page or section for Datum and Info; demo album extended if needed; screenshots via `seed.php`/`shoot.js`/`--restore`; `check.php` **Note:** sections in `handbuch/03-fototexte.html` rather than a new page: Datum and Info are core's Aufnahmedatum and Beschreibung, which that page already documents, and its "where texts appear" part had to change anyway. Demo album unchanged (two demo photos already carry a date and a description; the range in shot 24 is typed and cancelled, never saved). New shots 23, 24, 25 (new `shootSpan()` in `shoot.js`); 11 and 22 re-shot because photoinfo changed those screens; 11 churn re-shots reverted

### Deviation: the deploy runs the rescan (decided by the user, 2026-10-08)

Task 07 left `pwg.photoinfo.rescan` without a caller on the remote. Asked, the user chose a deploy step over a documented manual loop or an admin button. `tools/deploy/pwgdeploy/bootstrap.py` now lists every photo after the sync (`pwg.categories.getImages`, `recursive`, `order=id`, 500 per page) and posts them in chunks of 10; `cli.py` reports a `rescan` line with up to 10 failed ids and reasons. Failed photos never change the exit code; every photo failing is a `(warning)`, exit 0 (like sync errors and a missing SITE CHMOD); a failed ws call exits 7. `test_the_sync_runs_last` was replaced by `test_the_rescan_follows_the_sync` (deliberate: something now follows the sync). pytest collects 472 (was 437), new tests watched red first by the subagent; five hand mutants killed (page-loop stop `>=`, chunk size 11, photoinfo check forced true, warning condition negated, `[]` handling). Checked against the local install: `getImages` as webmaster lists 105 of the table's 105 photos.

### Found: `SyncMetadataTest` leaked an album per physical-photo case

The handbook run found 42 empty `photoinfo-test-*` albums (5 real ones expected). `SyncMetadataTest::tearDown()` called `destroyTestImages()` but not `destroyTestAlbums()`, so each of its two `createPhysicalTestImage()` cases left an album row. Fixed in the test; the 42 rows (all empty, no directories under `galleries/`) were removed through core's `pwg.categories.delete`. After the fix a `SyncMetadataTest` run leaves the count at 5 (it went up by 2 per run before).

## Verification

* [x] `ddev exec php handbuch/tools/check.php` passes **Note:** "OK 7 pages, 58 references, 25 screenshots all referenced, 8 admin routes resolve", 2026-10-08, after the last text edit and again at the end of the suite run
* [x] Screenshots show only the demo album, and the install is back to its 5 albums / 105 photos after `--restore` **Note:** `shoot.js` reported "none from outside the demo album"; 23, 24, 25, 11 and 22 opened as images (ledger, `TESTING.md`). After `--restore` the install had 105 photos but 47 albums: 42 leaked by `SyncMetadataTest` (see above), fixed and removed; 5 / 105 after the fix and again after the full suite run below
* [x] `CLAUDE.md` is under 100 lines and every rules file under 500 **Note:** `wc -l`: `CLAUDE.md` 82, longest rules file `plugin-test-suites.md` 300
* [x] Every suite command written in the docs was run once as written **Note:** 2026-10-08, sequentially, as written in `plugin-test-suites.md` (script and logs in the git-ignored `.agent-tests/2026-10-08-docs-suites/`): for each of the five plugins `create-test-users.php`, unit, integration, E2E - typetags 56 / 56 / 36, provenance 206 / 201 (4 skipped) / 54, persons 153 (1 skipped) / 112 (1 skipped) / 144, photoedit 95 / 81 / 26, photoinfo 216 / 119 / 30, all green; `bash tools/test-hooks.sh` all cases passed; `check.php` OK; `cd tools/deploy && uv run pytest` 472 passed. The handbook's seed / shoot / `--restore` cycle ran once in the handbook step. Install at 5 albums / 105 photos afterwards

## Review (2026-10-08, /verify)

Approved 2026-10-08. The one remaining manual check is whether the new German handbook text reads naturally to a native end user (ledger, `docs/agents/TESTING.md`).

Automated in this pass: **the remote's exiftool 12.76.** The CPAN tarball, linked as `/usr/local/bin/exiftool` in the web container and removed afterwards: integration photoinfo 119, provenance 201 (4 skipped), persons 112 (1 skipped), photoedit 81 OK, photoinfo E2E 30 passed. A logging wrapper showed php-fpm calling it (25 of 36 calls in one `SetInfoTest` run). Recorded in the ledger; the *Open* row and `deployment.md`'s "no suite has run against 12.76" are gone.

A review subagent's findings, all fixed:

* The rescan's photo list honours album permissions: a photo only in an album made private on the remote is skipped and not counted. Documented in the README and `deployment.md` (sync creates public albums, so it needs a hand-made private album)
* README said a failed photo keeps an *empty* date and info text; it keeps what it had, and an unreadable date still restores the info text
* ws.php *clamps* `per_page`, it does not refuse it: comment fixed, and the fake now clamps
* `RESCAN_CHUNK` / `IMAGE_PAGE_SIZE` were compared against hand-typed copies in the fake. `fakes.php_value()` now reads `PHOTOINFO_RESCAN_MAX_CHUNK` and `$conf['ws_max_images_per_page']` out of the PHP; changing the PHP chunk to 11 turned `test_the_chunk_is_the_one_the_server_accepts` red (reverted, checksums compared)
* `shootSpan()` checked only the two rows, though its clip is their bounding rectangle. It now refuses a clip that any other element touches (not a row, inside one, or a container of one). Watched: spanning `#PhotoDate` to `#datepost` fails with "the span also takes in div#PhotoInfo, …"; the real pair passes. Ledger wording corrected
* Decision 0041 named the wrong handler function
* `deployment.md`'s rescan section repeated the README; it now links it and keeps only agent-facing facts
* Handbook: non-admins "can only read" the rows (the date is a calendar link, not plain text); "Veröffentlicht am" is "further down", not directly under Datum
* `image_ids()` sent `recursive` as if it mattered; without `cat_id` core selects every album anyway. Parameter and its assertion dropped

After the fixes: `uv run pytest` 472 passed; handbook seed / shoot / `--restore` cycle re-run (churn re-shots reverted, 11 and 22 kept), `check.php` OK, install at 5 albums / 105 photos.
