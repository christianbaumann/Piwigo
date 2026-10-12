---
id: 11
dependencies: []
---

# Task 11: Local Apache container

A second web container serves the same checkout with Apache, `mod_rewrite` and `.htaccess`, so the remote's web-server rules (all-inkl.com, Apache) can be tested locally next to DDEV's nginx.

## References

* `design.md#apache-rules-are-tested-against-a-local-apache-container`
* `.ddev/config.yaml` (`webserver_type: nginx-fpm`, PHP 8.4), `.ddev/.gitignore` (DDEV-managed; custom files must not match its patterns)
* `local/config/database.inc.php` (`db_host` `db`, reachable from any container on the DDEV network)
* DDEV custom services: https://ddev.readthedocs.io/en/stable/users/extend/custom-compose-files/
* `.claude/rules/piwigo-dev-environment.md`, `CLAUDE.md` *Development Environment*

## Work

* [x] Add a tracked `.ddev/docker-compose.apache.yaml`: service `apache`, DDEV labels, the project mounted at `/var/www/html`, exposed through the DDEV router on its own port (`HTTP_EXPOSE` / `HTTPS_EXPOSE`), same DDEV network as `db`
  **Note:** service `apache`, `HTTP_EXPOSE=8480:80`, `HTTPS_EXPOSE=8443:80` -> https://piwigo.ddev.site:8443; `${DDEV_APPROOT}` bind-mounted at `/var/www/html`; reached as `http://apache` from the web container
* [x] Add a tracked build context (e.g. `.ddev/apache-build/Dockerfile`): `php:8.4-apache` with `mysqli` (and whatever else Piwigo's bootstrap needs to render a page), `a2enmod rewrite`, `AllowOverride All` on the docroot
  **Note:** `.ddev/apache-build/Dockerfile`: `mysqli`, `gd` (jpeg, freetype), `exif`, `a2enmod rewrite`, `AllowOverride All` via a conf snippet
* [x] Confirm `git ls-files .ddev` lists both new files and `ddev restart` does not overwrite them
  **Note:** `git check-ignore` matches neither file (DDEV's `.ddev/.gitignore` does not cover them); both survive `ddev restart` unchanged. They are untracked until committed. The first `ddev restart` failed once on a container-name conflict, the second came up clean (recorded in piwigo-dev-environment.md)
* [x] Add a structural test (in the suite that will own the handbook tests, task 06) that fails naming the container when the Apache URL does not answer, so a test needing Apache never passes by skipping
  **Note:** `HandbookAccessTest::testTheApacheContainerAnswers` (provenance integration suite, which owns the handbook tests): 200 plus `Server: Apache`, message names the service and `ddev restart`
* [x] Document the URL, the start command and the purpose in `.claude/rules/piwigo-dev-environment.md` and one line in `CLAUDE.md` (stay under 100 lines)
  **Note:** new section *The Apache container* in `.claude/rules/piwigo-dev-environment.md`; one sentence in `CLAUDE.md` *Development Environment* (86 lines)
* [x] State the known difference: all-inkl runs PHP through FPM/CGI behind Apache, the container uses `mod_php`; rewrite and access rules behave the same, PHP-handler directives in `.htaccess` do not
  **Note:** in the same section, plus the ledger row for the remote hand check (task 06)

## Verification

* [x] After `ddev restart`, `ddev describe` lists the `apache` service as OK
  **Note:** `ddev describe` lists `apache` OK at http://piwigo.ddev.site:8480
* [x] The Apache URL serves the gallery's start page with the same albums as `https://piwigo.ddev.site`, and a login there works against the shared database
  **Note:** logged in as `provenance_webmaster` through `pwg.session.login` on both URLs; `index.php` lists the same album ids (1-5 plus a test album) on both
* [x] `curl -sI` on the Apache URL answers `Server: Apache`
  **Note:** `server: Apache/2.4.68 (Debian)`
* [x] A throwaway `.htaccess` deny rule in a scratch directory returns 403 on the Apache URL and is ignored on the nginx URL; removed afterwards
  **Note:** `Require all denied` in `_data/htaccess-probe/`: Apache 403, nginx 200; directory removed
* [x] The nginx site and every existing plugin suite are unaffected
  **Note:** after the container was added, 2026-10-12, under the suite lock: provenance unit 273 / integration 262 (3 skipped, pre-existing) / E2E 55; typetags 88 / 119 / 46; photoedit 95 / 91 / 31; photoinfo 268 / 188 / 57; persons unit 163 / integration 125 (1 skipped) / E2E 155 of 156 (task 08's run; the one failure, `picture-fit.spec.js:285`, comes from photoedit's menu entry of task 04)
