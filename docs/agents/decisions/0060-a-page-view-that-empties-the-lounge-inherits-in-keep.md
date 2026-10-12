# 0060 — A page view that empties the lounge inherits in keep, without history

Date: 2026-10-12
Status: accepted
Implements the design decision *A lounge emptying from a page view inherits in mode keep* of the
role permissions change (`.agents/changes/2026-10-11-role-permissions/design.md`). Narrows the
move-mode rule of the inheritance handler (`plugins/provenance/include/events_inherit.inc.php`).

## Context

The lounge is active on this install, so every upload waits there until it is emptied. Core
empties it in two places: `pwg.images.uploadCompleted` at the end of an administrator's upload,
and `check_lounge()` on **any** request once the oldest photo waited longer than
`lounge_max_duration`. `check_lounge()` runs at `include/common.inc.php:202`, before
`include/user.inc.php` loads `$user`.

Both paths reach provenance through `associate_images_to_categories`, whose handler read the move
mode (`provenance_move_mode`) from `$_POST` and wrote history rows. So any visitor's request that
happened to empty the lounge could carry `replace` or `clear` and rewrite the provenance of the
released photos, and the history rows it wrote named no user. The handler cannot ask `is_admin()`
at that point: there is no user yet.

## Decision

- `provenance_inherit_policy($user, $request)` (`include/functions.inc.php`, pure) decides: with
  no `$user['id']` the inheritance runs in mode `keep` whatever the request carries, and writes no
  history row. With a user loaded the request's mode applies and history is written, as before.
- `provenance_inherit_into()` uses it whenever its caller names no mode;
  `provenance_write_inherited()` takes the history flag and skips the insert when it is false.
- `keep` still gives a freshly uploaded photo the album values, because it carries none yet. Only
  `replace`/`clear` and the history row go away.

## Consequences

- An administrator's own page view that empties the lounge is treated the same way: keep, no
  history row. The administrator's normal path, `pwg.images.uploadCompleted`, runs with `$user`
  loaded and is unchanged.
- The values a page view gives a released photo appear in no history. The photo had none before,
  so the trail misses one "empty to album value" step per field and nothing it already recorded.
- Tests: `tests/Unit/InheritPolicyTest` (the decision table);
  `InheritTest::testAPageViewThatEmptiesTheLoungeOnlyFillsPhotosWithoutProvenance` (a POST to
  `index.php` carrying `replace` and `clear` empties an overdue lounge: fresh photo filled, a photo
  with provenance untouched, no history row) and
  `InheritTest::testUploadCompletedStillRecordsTheInheritanceAgainstTheAdministrator`.
