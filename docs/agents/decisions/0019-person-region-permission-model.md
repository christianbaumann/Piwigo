# 0019 — Person regions gate on non-guest + token + per-image visibility

Date: 2026-08-30
Status: accepted
Supersedes nothing. Closes the question left open by
[0005-tag-assignment-permission-model.md](0005-tag-assignment-permission-model.md).

## Context

`plugins/persons` writes face regions into image files and mirrors each person as an ordinary
Piwigo tag. Decision 0005 recorded that the typetags picture-page methods gate on *not a guest* and
a matching `pwg_token`, and nothing else — any authenticated user can tag any `image_id`, including
one in an album they cannot open. It explicitly left open whether a per-image visibility check
belonged there.

Faces are personal data. "Who is in this photo" is a stronger disclosure than "this photo has a
colour tag", and the answer leaks even when the photo itself does not render.

## Decision

The seven `pwg.persons.*` methods gate as follows:

| Gate | Applies to |
|---|---|
| not a guest | every method |
| matching `pwg_token` | every method that writes |
| the photo passes `get_sql_condition_FandF()` for the caller | every photo-level method |
| `admin_only` | `rename`, `delete`, `rescan` |

The third row is new and goes beyond decision 0005 deliberately. `persons_user_can_see_image()`
in `plugins/persons/include/ws_functions.inc.php` is the single implementation.

`rename`, `delete` and `rescan` are `admin_only` because each reaches past a single photo — a
rename rewrites every file carrying that person — so a per-image gate could not bound them.

### A hidden photo answers 404, never 403

`persons_no_such_image()` is the only refusal for a photo the caller may not have, and it returns
the same code and the same message whether the photo is hidden or absent. A 403 on a forbidden id
and a 404 on an unknown one would let any authenticated user enumerate the gallery by id.
`VisibilityTest::testTheRefusalDoesNotRevealWhetherTheImageExists` asserts the two answers are
indistinguishable.

## Consequences

- typetags is **not** changed. Decision 0005 stands for that plugin; this decision governs
  `plugins/persons` only. Two plugins therefore apply different gates to the same picture page,
  which is a known and accepted inconsistency — narrowing typetags is a separate change with its
  own regression risk, and its data is not personal in the same way.
- A caller who legitimately cannot see a photo gets an answer indistinguishable from a typo. That
  is the intended trade.
- `admin_only` is enforced by core, which answers **401**, not 403 (`include/ws_core.inc.php:515`).
  The Phase 4 plan text expected 403; core does not do that, and the plugin does not reimplement
  the gate to change the number. `AdminOnlyTest` records 401 as an `[ERR]` characterization of
  core.
