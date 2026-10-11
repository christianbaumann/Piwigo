# 0055 — Guests are locked out

Date: 2026-10-11
Status: accepted
Supersedes [0031](0031-person-names-are-visible-to-guests-as-ordinary-tags.md): a guest no longer
reaches the `Schlagworte` row or the tag page, so the trade-off 0031 records no longer exists.
Implements research decision 1 of
[the role permissions research](../research/2026-10-11-role-permissions.md).

## Context

The gallery holds recovered family scans of identifiable private people. The owner wants no
visitor who is not logged in to see any of it. Core has a switch for the pages,
`$conf['guest_access']` (`include/config_default.inc.php`): off, `get_access_type_status()` gives a
guest `ACCESS_FREE` (`include/functions_user.inc.php:1484`) and every page that checks
`ACCESS_GUEST` sends the guest to the login page.

The same switch also closes the API. `ws.php:13` checks only `ACCESS_FREE`, but core registers
`ws_isInvokeAllowed()` (defined `include/ws_functions.inc.php:13`, registered
`include/ws_init.inc.php:14`, fired `include/ws_core.inc.php:591`) on `ws_invoke_allowed`, and it
refuses every method except `pwg.session.*` and `reflection.*` with `401 Access denied` to a caller
below `ACCESS_GUEST` - a guest, once the switch is off. The checks before it still run first, so a
guest can see `405` (`post_only`) or a parameter error instead; never data. The design for this change assumed the API stayed
open and planned a provenance handler for it; that handler was written, found redundant against
core's, and removed before commit.

## Decision

- `$conf['guest_access'] = false`. `tools/deploy` writes it into the remote config it generates
  (`bootstrap.config_php()`). Locally it goes into the git-ignored `local/config/config.inc.php`,
  documented in `.claude/rules/piwigo-dev-environment.md`.
- Nothing else. Core's own `ws_isInvokeAllowed()` closes the API with the same switch. The deploy
  is not affected: its preflight logs in before it calls `pwg.getVersion`
  (`tools/deploy/pwgdeploy/preflight.py`), which a guest can no longer call.

### What was rejected

- A `ws_invoke_allowed` handler in provenance refusing guests. It duplicated core's handler; its
  only effect beyond core's would have been on an install with guest pages open.
- A core change in `ws.php`: not needed.

## Consequences

- A fresh clone runs with guest pages and API open until someone sets the local line.
- `reflection.getMethodList` stays callable by a guest. It lists method names, no gallery data.
- The plugins' `is_a_guest()` gates stay in their code but no request reaches them any more, so no
  test covers them; they matter again only if the switch is turned back on. The plugins' guest
  `[NEG]` cases on methods without `admin_only` stay green, but witness core's refusal now, not the
  plugin's gate.
- Static files stay reachable by URL, see [0056](0056-static-image-files-stay-reachable-by-url.md).
- Tests that read gallery pages as a guest were removed or now read them as the normal account;
  provenance's `GuestLockoutTest` asserts the lockout.
