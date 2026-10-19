---
id: 03
dependencies:
- 01
---

# Task 03: `--parent <id>` creates the tree under an existing album

With `--parent <id>`, the top album is created under that album instead of the root, and the skip rule compares against that album's children.

## References

* `design.md#target-root-or---parent-id`
* `design.md#an-existing-album-is-skipped-with-everything-below-it`
* `tools/deploy/pwgdeploy/albums.py` (`plan()`), `tools/deploy/pwgdeploy/albums_cli.py` (from task 01)

## Work

* [x] `plan()` takes the parent id (`None` = root) and matches the top folder against that parent's children
* [x] Add `--parent` (positive int) to the parser; an id not in the remote tree exits ≠ 0 naming it, before anything is created
* [x] Unit tests: top folder new under the parent, top folder existing under the parent, same name existing at the root but not under the parent
* [x] Extend the integration test: a tree under the test root album through `--parent`
* [x] One line in the README section

## Verification

* [x] Against DDEV, `--parent <id>` creates the tree below that album
  **Note:** Verified via integration test_parent_creates_the_tree_below_that_album_and_an_unknown_id_creates_nothing
* [x] An unknown id exits ≠ 0 and creates nothing
  **Note:** Verified via the same integration test
* [x] `uv run pytest` and `uv run pytest -m integration` pass

**Verification note:** mutant "plan() ignores parent_id" killed by the existing-under-parent and same-name-at-root unit tests; production code restored. 616 passed.
