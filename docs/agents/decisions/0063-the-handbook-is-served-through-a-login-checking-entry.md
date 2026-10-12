# 0063 — The handbook is served through a login-checking entry

Date: 2026-10-12
Status: accepted
Refines [0025](0025-handbuch-moves-into-the-application-tree.md): the handbook still ships in the
application tree, but is no longer served as static files. Follows
[0055](0055-guests-are-locked-out.md), which closed the gallery's pages and API to guests.

## Context

`$conf['guest_access'] = false` stops only what runs through Piwigo. `handbuch/` is plain HTML,
CSS and PNG, served by the web server directly, so a guest read every page and every screenshot.
Locally DDEV runs nginx; the remote (all-inkl.com) runs Apache with `.htaccess`.

## Decision

- A root `handbuch.php` boots Piwigo, sends a guest to the login page through core's
  `access_denied()` (the login leads back to the page asked for), and serves one file under
  `handbuch/`. Which file is decided by `handbuch_resolve()` in `include/functions_handbuch.inc.php`:
  only the types in `HANDBUCH_CONTENT_TYPES` (`html`, `css`, `png`), no empty or dot segment (so no
  traversal and no hidden file), nothing under `tools/`, and the real path must stay inside
  `handbuch/`.
- Every request below `/handbuch/` is rewritten to `handbuch.php?f=<rest>`, so the pages, their
  relative links and `handbuch/tools/check.php` stay as they are. Nothing under `handbuch/` is
  served directly:
  - nginx: `.ddev/nginx/handbuch.conf` (`location ^~ /handbuch/`, which also keeps the `\.php$`
    location from running `handbuch/tools/*.php`)
  - Apache: `handbuch/.htaccess`, deployed with the handbook. Its target is built from
    `REQUEST_URI`, so it works whatever path the gallery is installed under.
- Behind the rewrite the browser's URL is `/handbuch/...`, so core's relative login redirect would
  point into `/handbuch/`. `handbuch_root_path()` sets `$page['root_path']` to climb out of it.
- Any logged-in account gets the handbook, `generic` included, whatever `guest_access` says.

## Consequences

- `HandbookAccessTest` (provenance integration suite) runs every case against nginx and against
  the local Apache container (`.ddev/docker-compose.apache.yaml`); `HandbuchResolveTest` is the
  unit half. Both servers' rules were each shown to fail the suite when removed.
- The container runs `mod_php`, the remote PHP through FPM/CGI. One hand check on the remote after
  the first deploy covers that difference (ledger in `docs/agents/TESTING.md`).
- The rules exist twice, once per server; a change to one needs the other.
- Files are sent with the session's no-cache headers, so a browser reloads the stylesheet and
  screenshots with each page. Accepted: the handbook is small and rarely read.
- Under Apache the rewrite sets `REDIRECT_URL=/handbuch/...`, and core's `cookie_path()` reads it:
  a guest's session cookie came back scoped to `/handbuch/`, the browser preferred it over the
  root cookie the login sets, and the login page returned forever. `handbuch.php` unsets
  `REDIRECT_URL` and `REDIRECT_SCRIPT_NAME` before booting, so the cookie path falls back to
  `SCRIPT_NAME`, the install root. `HandbookAccessTest::testAGuestWhoLogsInThenGetsTheHandbook`
  runs guest, login, handbook in one cookie jar. A browser that already holds a `/handbuch/`
  cookie from before the fix loops until that cookie expires or is deleted.
- Only the path picks the file: both rewrites drop the request's own query string.

### What was rejected

- Turning every page into PHP: changes the handbook tooling (`check.php`, `shoot.js`).
- Deny rules alone: a guest would get a 403 rather than a login page, and so would everyone else.
