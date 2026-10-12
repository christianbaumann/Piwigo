---
id: 06
dependencies:
- 11
---

# Task 06: Handbook behind login

The German handbook is served only to logged-in users, through `handbuch.php`, locally and on the remote; its pages and links stay unchanged.

## References

* `design.md#the-handbook-is-served-through-a-login-checking-entry`
* `handbuch/` (index, 7 pages, `assets/handbuch.css`, `assets/screenshots/`)
* `.ddev/nginx_full/nginx-site.conf` (generated, untracked) -> add a tracked `.ddev/nginx/` snippet instead
* `tools/deploy/pwgdeploy/fileset.py` (`EXCLUDED_BASENAMES`, confirm `handbuch/.htaccess` is published)
* `.claude/rules/handbook.md`, `.claude/rules/deployment.md`
* Remote host is all-inkl.com shared hosting: Apache with `.htaccess` and `mod_rewrite`
* Task 11: local Apache container for the `.htaccess` cases

## Deviation from the design

* The pure decision (which file, and the root path behind the rewrite) lives in a new
  `include/functions_handbuch.inc.php`, so it is unit-testable without Piwigo; `handbuch.php` only
  boots, checks the login and sends the file.
* `handbuch_root_path()` is not in the design: behind the rewrite core's relative login redirect
  pointed to `/handbuch/identification.php` (found on the first curl run). It sets
  `$page['root_path']` to climb out of the rewritten URL.
* The tests live in the provenance integration suite (`HandbookAccessTest`) and unit suite
  (`HandbuchResolveTest`), where core's other tests live; the handbook has no PHPUnit suite.

## Work

* [x] Add root `handbuch.php`: Piwigo bootstrap, login check (guest -> login page), serve a file under `handbuch/` by whitelisted extension (`html`, `css`, `png`) with correct content type, refuse traversal and `handbuch/tools/`; keep the whitelist in one constant
  **Note:** whitelist `HANDBUCH_CONTENT_TYPES` in `include/functions_handbuch.inc.php`; refusal is a 404; guests go through core's `access_denied()`, whose login leads back to the page
* [x] Rewrite `/handbuch/*` to `handbuch.php` and deny direct access: `.ddev/nginx/handbuch.conf` (tracked) and `handbuch/.htaccess` (remote)
  **Note:** `.ddev/nginx/handbuch.conf` (`location ^~ /handbuch/` plus a 301 for `/handbuch`), `handbuch/.htaccess` (target built from `REQUEST_URI`, works under a sub-path); applied locally with `ddev exec nginx -s reload`
* [x] Make sure the deploy publishes `handbuch/.htaccess`; add a fileset test
  **Note:** `test_the_handbook_login_entry_and_its_rewrite_ship` in `tools/deploy/tests/test_fileset.py`; test count in README and deployment.md now 596
* [x] Run the guest / logged-in / traversal cases against both the nginx URL and the Apache URL (task 11)
  **Note:** `HandbookAccessTest`, every case per server (`http://localhost`, `http://apache`); 23 tests green. Watched fail: guard removed -> 8 failures, `.htaccess` removed -> 6, nginx rule removed -> 6; reverted -> green
* [ ] (manual testing required) After the first deploy, check once by hand on the remote that a guest gets no handbook page and a logged-in user does; record it in the ledger in `docs/agents/TESTING.md` (covers the FPM/CGI difference task 11 cannot)
  **Note:** ledger row added to `docs/agents/TESTING.md` as *Pending*; needs a deploy
* [x] Update `handbuch/tools/shoot.js` / `check.php` only if they reach pages over HTTP and break
  **Note:** no change needed: `check.php` reads files on disk, `shoot.js` only photographs gallery pages
* [x] Decision file: handbook entry
  **Note:** `docs/agents/decisions/0063-the-handbook-is-served-through-a-login-checking-entry.md`
* [x] Update `.claude/rules/handbook.md`
  **Note:** new section *Only logged-in users read it*; also `deployment.md` (`.htaccess` ships), `piwigo-dev-environment.md`, `CLAUDE.md`

## Verification

* [x] On both the nginx and the Apache URL, a guest requesting `/handbuch/` or `/handbuch/01-alben.html` lands on the login page
  **Note:** Verified via `HandbookAccessTest::testAGuestLandsOnTheLoginPage` (also `/handbuch/assets/handbuch.css` and `/handbuch`), asserting the final URL is the root `identification.php` with `redirect=` back to the page
* [x] A logged-in normal user gets every page, the stylesheet and the screenshots; relative links work
  **Note:** Verified via `testANormalUserGetsEveryFile` (byte-identical body, content type, 8 pages, CSS, 26 screenshots) and `testEveryRelativeReferenceOfAPageIsServed`, as `provenance_normal`
* [x] `handbuch.php?f=../local/config/database.inc.php` and `?f=tools/check.php` are refused
  **Note:** Verified via `testARefusedFileIsNotServed` (also `/handbuch/tools/check.php`, `seed.php`, `.htaccess`) and the unit `HandbuchResolveTest` (13 refusal classes)
* [x] `ddev exec php handbuch/tools/check.php` passes
  **Note:** `OK  8 pages, 66 references, 26 screenshots`
* [x] `uv run pwg-deploy --list-files` contains `handbuch/.htaccess` and not `handbuch/tools/`; `uv run pytest` passes
  **Note:** run with a temporary index holding the new files as intent-to-add (they are uncommitted): lists `handbuch.php`, `handbuch/.htaccess`, `include/functions_handbuch.inc.php`, nothing under `handbuch/tools/`; `uv run pytest` 596 passed

## Review fixes (2026-10-12)

* Login loop under Apache: core's `cookie_path()` read the rewrite's `REDIRECT_URL` and scoped a guest's cookie to `/handbuch/`, shadowing the login's root cookie. `handbuch.php` unsets `REDIRECT_URL`/`REDIRECT_SCRIPT_NAME` before booting. `HandbookAccessTest::testAGuestWhoLogsInThenGetsTheHandbook` (red on Apache before the fix). Decision 0063 corrected.
* nginx passed the request's own query string on (`?f=` could override the path); the rewrite now ends in `?`, like the `.htaccess`. `testAQueryStringDoesNotPickTheFile` (red on nginx before). The `/handbuch` 301 is relative (`absolute_redirect off` in that location only).
* The extension whitelist had no case of its own; `testATypeOutsideTheWhitelistIsRefused`, shown to fail with the check deleted. `tools/` is refused in any case (`stripos`), `testToolsIsRefusedInAnyCase` (red first).
* Known, local only: the Apache container's bind mount is case-insensitive (documented in piwigo-dev-environment.md); its `/handbuch` 301 points to `http://...:8443` because the router terminates TLS. The remote is not affected.
