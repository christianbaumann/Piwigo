---
id: 14
dependencies: []
---

# Task 14: photoedit: copies before replace and on delete

Every overwritten or deleted photo state stays recoverable under `_data/photoedit/originals/`.

## References

* `design.md#a-restore-through-replace-keeps-a-copy-of-the-edited-state`
* `design.md#copies-of-a-deleted-photo-move-aside`
* `design.md#_original-sidecars-move-aside-on-delete`

## Work

* [x] Copy the current file to `_data/photoedit/originals/` before a replace overwrites it
* [x] On `delete_elements`, move the photo's copies and its exiftool `_original` sidecar to `originals/deleted/<id>-<deletion time>/`
* [x] Add `galleries/**/*_original` to `.gitignore`
* [x] Update `.claude/rules/piwigo-dev-environment.md` (`_data/photoedit/`)

## Verification

* [x] Integration: replace leaves a copy byte-equal to the file before **Note:** Verified via `CopiesTest::testAReplaceLeavesACopyOfTheFileBefore` (pwg.images.upload update mode); red before the change, killed by removing the core trigger
* [x] Integration: delete leaves no copy or sidecar under the old names, all under `deleted/` **Note:** Verified via `CopiesTest::testADeleteMovesCopiesAndSidecarAside` and `testADeleteThatKeepsTheFileKeepsItsSidecar`; mutants on the matcher and the sidecar condition killed
* [x] `git status` lists no `_original` under `galleries/` **Note:** Verified via `git status --short galleries` (only the four modified PNGs)
* [x] photoedit suites pass **Note:** Verified via photoedit unit (107 OK), integration (108 OK, then CopiesTest 3/3 OK), E2E (34 passed); persons `PhotoDeletionTest` 2/2 OK
