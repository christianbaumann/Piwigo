---
id: 01
dependencies: []
---

# Task 01: Guest lockout

Guests reach no gallery page and no API method except login, session status and version, locally and on the remote.

## References

* `design.md#guests-are-locked-out-with-cores-switch`
* `design.md#the-api-refuses-guests-through-one-handler`
* `design.md#static-image-files-stay-reachable-by-url`
* `include/config_default.inc.php:628`, `include/functions_user.inc.php:1484` (`guest_access`)
* `ws.php:13` (`ACCESS_FREE`), `include/ws_core.inc.php:591` (`ws_invoke_allowed`)
* `tools/deploy/pwgdeploy/bootstrap.py` `config_php()`
* `docs/agents/decisions/0031-person-names-are-visible-to-guests-as-ordinary-tags.md`
* `.claude/rules/deployment.md`, `.claude/rules/testing.md`

## Work

* [ ] Add `$conf['guest_access'] = false;` to the remote config that `config_php()` generates; test it in `tools/deploy/tests/`
* [ ] Set the same line in the local `local/config/config.inc.php` (git-ignored) and document the step where fresh-clone setup is documented
* [ ] Add a `ws_invoke_allowed` handler in `plugins/provenance` that returns a `PwgError` for a guest on every method except `pwg.session.login`, `pwg.session.getStatus`, `pwg.getVersion`; keep the allowed list in one named constant
* [ ] Update tests that assume guest access (0031 cases, guest E2E specs) to assert the lockout instead; map each removed assertion to its successor in the commit message
* [ ] Decision file: guest lockout, superseding 0031
* [ ] Decision file: static files under `galleries/`, `upload/`, `_data/i/` stay reachable by URL (accepted gap)
* [ ] Update `CLAUDE.md` / rules where they state guest visibility (e.g. persons names as tags)

## Verification

* [ ] A guest requesting `index.php` or `picture.php` is redirected to the login page
* [ ] A guest calling `pwg.categories.getImages`, `pwg.tags.getList`, `pwg.persons.getList` gets an error, not data
* [ ] A guest can still call `pwg.session.login`, `pwg.session.getStatus`, `pwg.getVersion`
* [ ] A logged-in normal user calling `pwg.categories.getImages` still gets data
* [ ] `uv run pwg-deploy --dry-run` shows the generated config contains `guest_access = false`; `uv run pytest` in `tools/deploy` passes
* [ ] Provenance unit, integration and E2E suites pass, and every other plugin suite that had guest cases passes
