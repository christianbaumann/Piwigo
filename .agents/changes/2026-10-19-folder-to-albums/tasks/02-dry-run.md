---
id: 02
dependencies:
- 01
---

# Task 02: `--dry-run` prints the plan and creates nothing

With `--dry-run`, `pwg-albums` logs in, reads the remote tree and prints the plan with `would create` and `skipped (exists)` lines, without creating an album.

## References

* `design.md#--dry-run-reads-the-remote`
* `tools/deploy/pwgdeploy/albums_cli.py`, `tools/deploy/pwgdeploy/albums.py` (from task 01)

## Work

* [ ] Add `--dry-run` to the `albums_cli.py` parser; with it, stop after `plan()` and print each action as `would create` or `skipped (exists)`
* [ ] Unit test: with `--dry-run`, a fake client receives no `pwg.categories.add`
* [ ] Extend the integration test: a dry run over a new tree leaves the remote unchanged
* [ ] One line in the README section

## Verification

* [ ] A dry run against DDEV prints the plan and `getList` shows no new album afterwards
* [ ] A real run afterwards creates exactly the albums the dry run listed as `would create`
* [ ] `uv run pytest` and `uv run pytest -m integration` pass
