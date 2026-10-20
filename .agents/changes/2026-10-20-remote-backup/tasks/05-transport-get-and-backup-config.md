---
id: 05
dependencies: []
---

# Task 05: Download over FTPS and the backup config section

The transport can download a remote file to a local path, and the credential file can name the
backup folder.

## References

* `design.md#interfaces`
* `design.md#storage-location-no-encryption`
* `tools/deploy/pwgdeploy/transport.py` (`Transport`, `FtplibTransport.put()`)
* `tools/deploy/tests/fakes.py`
* `tools/deploy/pwgdeploy/config.py` (`OPTIONAL_SECTIONS`, `_reject_unknown_keys()`)
* `tools/deploy/deploy.example.json`, `tools/deploy/README.md` (credential table)

## Work

* [x] Write failing tests, then add `get(remote_path, local)` to the `Transport` protocol and `FtplibTransport` (binary `RETR` into a temporary file beside `local`, renamed when complete; `TransportError` naming the path on failure)
* [x] Add `get()` to the fake transport in `tests/fakes.py`
* [x] Add an optional `backup` section to `config.py` with `dir` (`~` expanded, must be absolute after expansion, must not be inside the repository); add it to `deploy.example.json` and the README's credential table
* [x] Tests for the new config rules: missing section, relative path, path inside the repository, unknown key

## Verification

* [x] A failed download leaves no file at `local`
  **Note:** Verified via test_transport.py::test_a_download_cut_off_midway_leaves_no_file, test_a_refused_download_leaves_no_file_and_names_the_path
* [x] A `backup.dir` inside the repository is refused, naming the field
  **Note:** Verified via test_config.py::test_a_backup_dir_inside_the_repository_is_refused and test_a_symlink_into_the_repository_is_refused (resolve() follows symlinks)
* [x] A credential file without `backup` still loads for `pwg-deploy` and `pwg-albums`
  **Note:** Verified via test_config.py::test_the_backup_section_is_optional; pwg-deploy, pwg-albums and smoke all load through config.load_file
* [x] `cd tools/deploy && uv run pytest` passes (including `test_readme.py`)
  **Note:** all pass (642) except test_seed.py::test_the_emoji_limit_is_the_one_typetags_enforces, which fails only because the typetags submodule is not initialised in the worktree (unrelated)
