---
id: 02
dependencies: []
---

# Task 02: Deploy runs `pwg.persons.rescan`

After every deploy the remote's person index is rebuilt from the image files, with no manual step, and the run reports how many photos were read.

## References

* `design.md#pwgpersonsrescan-becomes-a-deploy-step`
* `design.md#persons-rescan-runs-before-the-photoinfo-rescan`
* `docs/agents/decisions/0020-persons-index-is-derived-the-file-is-the-source-of-truth.md`
* `tools/deploy/pwgdeploy/bootstrap.py` (`run()`, `rescan_photoinfo()`, `image_ids()`, `chunks()`, `RESCAN_CHUNK`)
* `plugins/persons/main.inc.php:173` (method registration, `PERSONS_WRITEBACK_MAX_CHUNK`)
* `plugins/persons/include/ws_functions.inc.php` (`ws_persons_rescan`, answer `scanned` / `failed`)
* `tools/deploy/README.md`, `tools/deploy/tests/test_readme.py`
* `.claude/rules/deployment.md` (*The deploy rescans photoinfo's values*, *Photo edits travel as files*)

## Work

* [x] Find out whether the order of the two rescans matters (does photoinfo's tag rescan read anything the persons index provides?); record the answer in a comment at the call site and in this change
  **Note:** It matters; persons first. Indexing a photo makes its mirrored person tags match its regions and **removes** one without a region (`persons_sync_image_tags()`); photoinfo's rescan only adds what the keywords name. Persons first: the photo ends with every tag its file names, and photoinfo's `not_in_file` sees the region tags. Photoinfo first: persons would strip a keyword-only person tag photoinfo just restored. The design's order was right, its stated reason was not (tag rows are found/created by name either way). Recorded at the call site in `bootstrap.run()` and in `.claude/rules/deployment.md`
* [x] Write failing tests in `tests/test_bootstrap.py` against the fakes: persons rescan called in chunks no larger than `PERSONS_WRITEBACK_MAX_CHUNK`, read from the PHP source; skipped when persons was not activated; failed photos are reported, not fatal
* [x] Add `rescan_persons()` and a `BootstrapResult` field in `pwgdeploy/bootstrap.py`, called from `run()` in the order found above, reusing `image_ids()` and `chunks()`
  **Note:** reuses `RescanResult` (tag fields stay at their defaults) instead of a new type; each rescan lists the photos itself, so `pwg.categories.getImages` is called twice per deploy
* [x] Add the report line in `cli.py`, with the same exit-0 policy as the photoinfo rescan
* [x] Document the step in `tools/deploy/README.md` and `.claude/rules/deployment.md`; drop "run `pwg.persons.rescan`" from the manual steps there; remove the backlog item

## Verification

* [x] A deploy against a fake with persons active calls `pwg.persons.rescan` for every photo id exactly once
  **Note:** Verified via `test_a_run_rebuilds_the_person_index_of_every_photo_the_sync_registered`, `test_persons_rescan_sends_every_photo_once_in_chunks_the_method_accepts`; removing the call in `run()` turned 5 tests red
* [x] A chunk larger than `PERSONS_WRITEBACK_MAX_CHUNK` is never sent; changing the PHP constant turns a test red
  **Note:** Verified: `PERSONS_WRITEBACK_MAX_CHUNK` set to 9 in the PHP turned 4 tests red; the fake refuses an oversized chunk
* [x] A photo the rescan could not read appears by id in the report and the exit code stays 0
  **Note:** Verified via `test_a_photo_the_persons_rescan_could_not_read_is_reported_by_id` and `test_a_photo_the_persons_rescan_could_not_read_is_named_and_the_run_succeeds`
* [x] `cd tools/deploy && uv run pytest` passes
  **Note:** 594 passed after merging with task 01, 2026-10-11
* [x] Hand check against the DDEV install (`deploy.ddev.json` or equivalent), recorded in `docs/agents/TESTING.md`: persons regions are present after the step
  **Note:** Done 2026-10-11 with the persons overlay seed: index rows deleted, `bootstrap.rescan_persons()` via `deploy.ddev.json` read 106/106 and restored both regions byte-identically (md5); no gallery file changed. Ledger row in `docs/agents/TESTING.md`. The remote has not been deployed with the step yet
