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

* [x] Catch `RemoteHttpError` per album in the create loop; record `failed` with the message; record every descendant as `skipped (parent failed)` without calling the server
* [x] Errors are printed after the summary line; exit code ≠ 0 when anything failed
* [x] Unit tests with a fake client that refuses one album: its siblings are created, its children are not requested, exit code ≠ 0
* [x] One line in the README section, including that a re-run does not repair a failed sub-album under a created top album

## Verification

* [x] Against DDEV, a folder whose name the server refuses (e.g. a blank name after trimming, if reachable; otherwise the fake-client test is the evidence) is reported as failed and its siblings exist
  * **Note:** Automated in `test_a_refused_album_fails_the_run_but_not_its_siblings` (`-m integration`): folder " " with a child plus a sibling; exit 7, child skipped, sibling created, albums deleted in `finally`.
  * **Known gap:** on the real server the failure line reads `...ws.php?format=json returned HTTP 500`, not the server's message: `http.UrllibClient` raises on the HTTP status before `ws_call` reads the JSON body. Fixing it means changing `http.py`/`bootstrap.py`, shared with pwg-deploy; left for the owner to decide. The unit test (fake client answering `stat: fail`) does show the server's message.
* [x] `uv run pytest` passes
  * **Note:** Verified via `test_a_refused_album_is_reported_its_children_are_not_requested_and_siblings_are_created`; full run green.
