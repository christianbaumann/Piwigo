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

* [ ] Add a tracked `.ddev/docker-compose.apache.yaml`: service `apache`, DDEV labels, the project mounted at `/var/www/html`, exposed through the DDEV router on its own port (`HTTP_EXPOSE` / `HTTPS_EXPOSE`), same DDEV network as `db`
* [ ] Add a tracked build context (e.g. `.ddev/apache-build/Dockerfile`): `php:8.4-apache` with `mysqli` (and whatever else Piwigo's bootstrap needs to render a page), `a2enmod rewrite`, `AllowOverride All` on the docroot
* [ ] Confirm `git ls-files .ddev` lists both new files and `ddev restart` does not overwrite them
* [ ] Add a structural test (in the suite that will own the handbook tests, task 06) that fails naming the container when the Apache URL does not answer, so a test needing Apache never passes by skipping
* [ ] Document the URL, the start command and the purpose in `.claude/rules/piwigo-dev-environment.md` and one line in `CLAUDE.md` (stay under 100 lines)
* [ ] State the known difference: all-inkl runs PHP through FPM/CGI behind Apache, the container uses `mod_php`; rewrite and access rules behave the same, PHP-handler directives in `.htaccess` do not

## Verification

* [ ] After `ddev restart`, `ddev describe` lists the `apache` service as OK
* [ ] The Apache URL serves the gallery's start page with the same albums as `https://piwigo.ddev.site`, and a login there works against the shared database
* [ ] `curl -sI` on the Apache URL answers `Server: Apache`
* [ ] A throwaway `.htaccess` deny rule in a scratch directory returns 403 on the Apache URL and is ignored on the nginx URL; removed afterwards
* [ ] The nginx site and every existing plugin suite are unaffected
