# 0069 — The remote shows no PHP errors

Date: 2026-10-15
Status: accepted
Implements the design section *The remote shows no PHP errors* of change
`.agents/changes/2026-10-15-exploratory-testing-answers`.

## Context

A PHP fatal error showed a visitor a stack trace with server paths (SES-2026-10-13-03). Core's
default is `$conf['show_php_errors_on_frontend'] = true` (`include/config_default.inc.php:554`),
and nothing overrode it in the remote config `tools/deploy` generates.

What the setting does (`include/common.inc.php:92-98`): while `$conf['show_php_errors']` is
non-empty (core default `E_ALL`), core sets `error_reporting` to it, and only if
`show_php_errors_on_frontend` is true does it also call `ini_set('display_errors', true)`. Set to
false, core does **not** turn `display_errors` off; it leaves it untouched, so the server's own PHP
configuration decides. Errors are still reported at `E_ALL` and go to the server's PHP log.

## Decision

- `tools/deploy` writes `$conf['show_php_errors_on_frontend'] = false;` into the remote config it
  generates (`bootstrap.config_php()`), beside `guest_access`.
  `tools/deploy/tests/test_bootstrap.py::test_generated_config_hides_php_errors_from_visitors`
  guards it.
- Locally nothing changes: the git-ignored `local/config/config.inc.php` does not set it, so the
  DDEV install keeps showing errors in the browser.

### What was rejected

- Off everywhere: local debugging would need the server log for every error.
- On everywhere: the state that leaked server paths.
- Writing `@ini_set('display_errors', 0)` into the remote config as well: the generated file holds
  `$conf` lines only, and core's setting is the switch it documents for this.

## Consequences

- On the remote, errors are found only in the server's PHP log.
- If the host's own `display_errors` is on, errors still reach the browser, since core no longer
  turns it on but never turns it off either. Whether all-inkl's default is off has no local oracle;
  it is a hand check on the remote (Werkzeuge → Wartung → Server-Umgebung → phpinfo, see
  `.claude/rules/deployment.md`).
- The line takes effect on the next deploy, which rewrites the remote config.
