---
id: 08
dependencies:
- 01
---

# Task 08: New albums come out in folder order

`pwg-albums` creates sibling albums in the order `walk()` lists the folders (sorted by name), but core's `$conf['newcat_default_position'] = 'first'` puts each new album first, so siblings came out reversed (seen on the remote 2026-10-19, fixed there by hand with `pwg.categories.setRank`). Passing `position=last` to `pwg.categories.add` makes new siblings appear in folder order, after any albums already in the parent. Added after the design on the user's request (2026-10-19).

## References

* `design.md#album-name-and-folder-selection` (core defaults; this changes the position only)
* `ws.php` (`pwg.categories.add`: `position` = `first` | `last`), `admin/include/functions.php` (`newcat_default_position`)
* `tools/deploy/pwgdeploy/albums.py` (`create_album()`)

## Work

* [x] `create_album()` sends `position=last`
* [x] Unit test (fake client): `pwg.categories.add` is called with `position=last`
* [x] Integration test: siblings of a created tree come back from `pwg.categories.getList` in folder order (by `global_rank`), with at least three siblings whose sorted order differs from the reverse
* [x] One phrase in the README section: siblings are created in folder name order, after existing albums

## Verification

* [x] Against DDEV, three or more new sibling albums appear in the folder name order; this test fails without `position=last`

  **Note:** Verified via `test_siblings_are_created_in_folder_name_order` (4 siblings); failed first with `['Dora', 'Clara', 'Berta', 'Anna'] != ['Anna', ...]`, green after the change. Unit `test_create_album_asks_for_the_last_position` failed first with KeyError 'position'.
* [x] `uv run pytest` and `uv run pytest -m integration` pass; the README test count matches

  **Note:** Verified: `uv run pytest` 628 passed (+1 unit), `uv run pytest -m integration` 5 passed (+1); README and deployment.md say 628.
