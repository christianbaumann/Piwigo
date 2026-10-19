---
id: 01
dependencies: []
---

# Task 01: Core command creates a folder tree as albums

`uv run pwg-albums deploy.<target>.json <folder>` creates the folder and its sub-folders as an album tree at the remote root and skips an album that already exists, with everything below it. It fixes the module layout and interfaces tasks 02 to 05 build on.

## References

* `design.md#flow`
* `design.md#reuse`
* `design.md#an-existing-album-is-skipped-with-everything-below-it`
* `design.md#album-name-and-folder-selection`
* `design.md#few-checks-and-the-servers-own-refusals`
* `design.md#tests-unit-for-plan-one-integration-test-against-ddev`
* `tools/deploy/pwgdeploy/bootstrap.py` (`login()`, `ws_call()`), `tools/deploy/pwgdeploy/seed.py` (`collated()`), `tools/deploy/pwgdeploy/config.py` (`load_file()`), `tools/deploy/pwgdeploy/cli.py` (`http_client()`)
* `ws.php` (`pwg.categories.add`, `pwg.categories.getList`), `include/ws_functions/pwg.categories.php` (`name_raw`)
* `.claude/rules/testing.md`, `.claude/rules/test-design.md`

## Work

* [x] New module `tools/deploy/pwgdeploy/albums.py` with these interfaces (later tasks extend them):
  * `LocalFolder(name, children)`, `walk(path) -> LocalFolder`: sub-folders only, `.*` skipped, symlinks not followed, names in NFC
  * `RemoteAlbum(id, name, parent_id)`, `read_remote(client, base_url) -> list[RemoteAlbum]`: one `pwg.categories.getList` with `recursive=true`, name from `name_raw` (`name` is HTML-escaped by galleryrules' `render_category_name` handler)
  * `Action(path, name, kind, parent)`, `plan(local, remote) -> list[Action]`: pure, top-down, `create` or `skip (exists)`; match on same parent and `seed.collated()` name; a skipped album's subtree gets no actions
  * `create_album(client, base_url, name, parent_id) -> int`: `pwg.categories.add`, returns the new id
* [x] New CLI `tools/deploy/pwgdeploy/albums_cli.py` with `main()`: load config, check `<folder>` is a directory, log in, walk, read remote, plan, create top-down (the new id becomes the children's parent), print one line per album and a summary line. The first error aborts with exit code ≠ 0
* [x] Entry `pwg-albums = "pwgdeploy.albums_cli:main"` in `tools/deploy/pyproject.toml`
* [x] Unit tests (pytest) for `plan()` and `walk()` (temp dir): new tree, existing top album, existing album with children, case/accent-equal name, hidden folder, symlink, NFD name
* [x] Integration test against DDEV (`deploy.ddev.json`), marker `integration` registered in `pyproject.toml` and excluded from the default run: creates a temp tree under a uniquely named root, checks it through `getList`, runs again and sees only skips, deletes the root in teardown (`pwg.categories.delete` with `pwg_token`). Fails naming `deploy.ddev.json` when missing, never skips
* [x] README section in `tools/deploy/README.md`: what it does, the command, the skip rule, the integration test command

## Verification

* [x] `cd tools/deploy && uv run pytest` passes and does not run the integration test
  * **Note:** Verified: 613 passed; `tests/conftest.py` leaves `tests/integration/` out unless `-m integration` is given (also with `-m "not integration"`, fixed in verification)
* [x] `cd tools/deploy && uv run pytest -m integration` passes with DDEV up
  * **Note:** Verified: 1 passed
* [x] Each new unit test was seen to fail before the code it covers existed
  * **Note:** `tests/test_albums.py` was run before `albums.py` existed (ImportError at collection). Verified afterwards by breaking the code: removing the subtree skip, comparing names without `collated()`, following symlinks and dropping NFC each turn the matching tests red (4, 2-4, 1, 1 failures); production file restored byte for byte
* [x] Running the command against DDEV with a sample folder creates the matching tree; a second run creates nothing
  * **Note:** Verified via the integration test and ad hoc: 3 created, then 0 created 1 skipped; album deleted afterwards
* [x] A non-directory `<folder>` and a wrong password each exit ≠ 0 with a message naming the problem
  * **Note:** Verified ad hoc: non-directory exits 1 (`... is not a directory`), wrong password exits 7 (`Invalid username/password`)
