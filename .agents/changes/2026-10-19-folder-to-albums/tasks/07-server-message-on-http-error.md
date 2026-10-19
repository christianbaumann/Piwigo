---
id: 07
dependencies:
- 05
---

# Task 07: A refused ws call reports the server's own message

When `ws.php` refuses a call with an HTTP error status (e.g. `pwg.categories.add` with a blank name answers HTTP 500 and a JSON body `{"stat":"fail","err":...,"message":"..."}`), the raised `RemoteHttpError` carries the server's message, so the `pwg-albums` report and terminal show the reason instead of only `returned HTTP 500`. Added after the design on the user's request (2026-10-19); the gap is recorded in task 05.

## References

* `design.md#few-checks-and-the-servers-own-refusals` (the server's message is meant to pass through)
* `tasks/05-continue-on-error.md` (known gap)
* `tools/deploy/pwgdeploy/http.py` (`UrllibClient._request`, the `HTTPError` branch), `tools/deploy/pwgdeploy/bootstrap.py` (`ws_call()`)
* `tools/deploy/tests/test_http.py`

## Work

* [x] Reproducing test first, in `tests/test_http.py`: an `HTTPError` whose body is a ws JSON failure envelope must raise `RemoteHttpError` naming the status and the server's `message`; watch it fail
* [x] In the `HTTPError` branch of `UrllibClient._request`, read the error body; when it is a JSON object with a `message`, add that message to the `RemoteHttpError` text. Otherwise keep today's text. Same exception type and exit code, so `pwg-deploy`'s behaviour stays unchanged apart from the longer message
* [x] Reading the body is capped (`MAX_ERROR_BODY_BYTES`) and an unreadable body (OSError, IncompleteRead) keeps today's text; tested
* [x] Test: a non-JSON error body (e.g. an HTML 404 page) keeps today's message
* [x] Extend the blank-folder integration test in `tests/integration/test_albums_ddev.py`: the report's `message` for the refused album contains the server's text

## Verification

* [x] Against DDEV, a refused blank-named folder is reported with the server's message (e.g. "The name of an album must not be empty"), not only `HTTP 500`
  * **Note:** Verified via `test_a_refused_album_fails_the_run_but_not_its_siblings` (DDEV answers in German: "Der Name eines Albums darf nicht leer sein.")
* [x] An HTTP error without a JSON body reports as before
  * **Note:** Verified via `test_an_error_body_that_is_not_a_ws_envelope_keeps_the_plain_message`
* [x] `uv run pytest` and `uv run pytest -m integration` pass; the README test count matches
