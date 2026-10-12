# 0068 — Shadowed permission gates stay

Date: 2026-10-12
Status: accepted
A decision not to fix. Answers the gate flips of change
`.agents/changes/2026-10-12-role-test-coverage`, recorded in `docs/agents/TESTING.md`
(*Role matrix gate flips*).

## Context

Each gate was flipped once by hand to prove the role-matrix rows can fail. Some flips turned
nothing red, because a second check refuses the same roles with the same answer first or after:

- persons: `admin_only` on `pwg.persons.rename`, `delete` and `rescan` (`main.inc.php`), behind
  which the handlers' `is_webmaster()` answers the same 401
- persons: `check_status(ACCESS_ADMINISTRATOR)` in `admin/photo.php` and `admin/persons.php`,
  behind core's own admin page check
- provenance: the `is_a_guest()` checks in the four write handlers of `include/ws_functions.inc.php`,
  behind `admin_only` and core's guest lockout ([0055](0055-guests-are-locked-out.md))
- typetags: the guest checks of `typetags.image.addTag`, `addNewTag` and `removeTag`, behind core's
  `ws_isInvokeAllowed()` (0055)
- core: `check_status(ACCESS_ADMINISTRATOR)` in `admin.php`, repeated by every admin page the matrix
  requests (`admin/tags.php`, `album.php`, `photo.php`, `batch_manager.php`, `cat_modify.php`,
  `picture_modify.php`)

## Decision

- The shadowed checks stay. They are defence in depth: each one keeps its handler or page safe if
  the check in front of it is ever removed or loosened.
- No test is written for them alone. A test cannot tell two equal refusals apart, and building one
  that reaches past the outer check would need the outer check disabled, which tests nothing real.

## Consequences

- Removing an outer check leaves the inner one as the only gate, and the matrix rows still witness
  the refusal, now through the inner check.
- Removing a shadowed check alone fails no test. Anyone tempted to delete one as dead code should
  cite this file.
