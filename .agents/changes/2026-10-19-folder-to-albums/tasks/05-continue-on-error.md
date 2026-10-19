---
id: 05
dependencies:
- 01
---

# Task 05: A failed album does not stop the run

When `pwg.categories.add` refuses an album, the run records the failure with the server's message, marks that album's children `skipped (parent failed)`, creates the rest, and exits ≠ 0.

## References

* `design.md#on-an-error-go-on`
* `tools/deploy/pwgdeploy/albums_cli.py`, `tools/deploy/pwgdeploy/albums.py` (from task 01)
* `tools/deploy/pwgdeploy/bootstrap.py` (`ws_call()` raises `RemoteHttpError` with the server's message)

## Work

* [ ] Catch `RemoteHttpError` per album in the create loop; record `failed` with the message; record every descendant as `skipped (parent failed)` without calling the server
* [ ] Errors are printed after the summary line; exit code ≠ 0 when anything failed
* [ ] Unit tests with a fake client that refuses one album: its siblings are created, its children are not requested, exit code ≠ 0
* [ ] One line in the README section, including that a re-run does not repair a failed sub-album under a created top album

## Verification

* [ ] Against DDEV, a folder whose name the server refuses (e.g. a blank name after trimming, if reachable; otherwise the fake-client test is the evidence) is reported as failed and its siblings exist
* [ ] `uv run pytest` passes
