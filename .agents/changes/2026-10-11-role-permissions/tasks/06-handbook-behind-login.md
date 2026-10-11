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

## Work

* [ ] Add root `handbuch.php`: Piwigo bootstrap, login check (guest -> login page), serve a file under `handbuch/` by whitelisted extension (`html`, `css`, `png`) with correct content type, refuse traversal and `handbuch/tools/`; keep the whitelist in one constant
* [ ] Rewrite `/handbuch/*` to `handbuch.php` and deny direct access: `.ddev/nginx/handbuch.conf` (tracked) and `handbuch/.htaccess` (remote)
* [ ] Make sure the deploy publishes `handbuch/.htaccess`; add a fileset test
* [ ] Run the guest / logged-in / traversal cases against both the nginx URL and the Apache URL (task 11)
* [ ] After the first deploy, check once by hand on the remote that a guest gets no handbook page and a logged-in user does; record it in the ledger in `docs/agents/TESTING.md` (covers the FPM/CGI difference task 11 cannot)
* [ ] Update `handbuch/tools/shoot.js` / `check.php` only if they reach pages over HTTP and break
* [ ] Decision file: handbook entry
* [ ] Update `.claude/rules/handbook.md`

## Verification

* [ ] On both the nginx and the Apache URL, a guest requesting `/handbuch/` or `/handbuch/01-alben.html` lands on the login page
* [ ] A logged-in normal user gets every page, the stylesheet and the screenshots; relative links work
* [ ] `handbuch.php?f=../local/config/database.inc.php` and `?f=tools/check.php` are refused
* [ ] `ddev exec php handbuch/tools/check.php` passes
* [ ] `uv run pwg-deploy --list-files` contains `handbuch/.htaccess` and not `handbuch/tools/`; `uv run pytest` passes
