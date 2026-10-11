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

* [ ] Find out whether the order of the two rescans matters (does photoinfo's tag rescan read anything the persons index provides?); record the answer in a comment at the call site and in this change
* [ ] Write failing tests in `tests/test_bootstrap.py` against the fakes: persons rescan called in chunks no larger than `PERSONS_WRITEBACK_MAX_CHUNK`, read from the PHP source; skipped when persons was not activated; failed photos are reported, not fatal
* [ ] Add `rescan_persons()` and a `BootstrapResult` field in `pwgdeploy/bootstrap.py`, called from `run()` in the order found above, reusing `image_ids()` and `chunks()`
* [ ] Add the report line in `cli.py`, with the same exit-0 policy as the photoinfo rescan
* [ ] Document the step in `tools/deploy/README.md` and `.claude/rules/deployment.md`; drop "run `pwg.persons.rescan`" from the manual steps there; remove the backlog item

## Verification

* [ ] A deploy against a fake with persons active calls `pwg.persons.rescan` for every photo id exactly once
* [ ] A chunk larger than `PERSONS_WRITEBACK_MAX_CHUNK` is never sent; changing the PHP constant turns a test red
* [ ] A photo the rescan could not read appears by id in the report and the exit code stays 0
* [ ] `cd tools/deploy && uv run pytest` passes
* [ ] Hand check against the DDEV install (`deploy.ddev.json` or equivalent), recorded in `docs/agents/TESTING.md`: persons regions are present after the step
