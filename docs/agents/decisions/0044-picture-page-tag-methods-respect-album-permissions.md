# 0044 — the picture page's tag methods act only on a photo the account may see

Date: 2026-10-09
Status: accepted

## Context

Decision 0005 opens tagging on the picture page to any logged-in account. Until now
`typetags.image.addTag` checked only that the photo existed and `removeTag` checked nothing, so any
account could change the tags of a photo in a private album by its id. Two things raised the stakes
in plan 2026-10-09: `typetags.image.addNewTag` also creates tags, and with photoinfo active every
tag change rewrites the photo's file.

## Decision

- `typetags.image.addTag`, `removeTag` and `addNewTag` refuse a photo the account cannot see,
  through one check, `typetags_image_visible()`. It asks core's own permission condition
  (`get_sql_condition_FandF()` over forbidden and visible albums and visible images), the rule the
  gallery uses to show a photo at all.
- A refused photo is answered `404 Image not found`, exactly like a missing one, so the answer does
  not reveal that a private photo exists.
- Core's rule holds for administrators as well: a private album needs a grant
  (`calculate_permissions()` in `include/functions_user.inc.php`; only locked albums differ). An
  administrator without one cannot tag that photo here either, the same as they cannot open it on
  `picture.php`. The admin screens are not affected.

## Consequences

- `removeTag` on a missing photo is now `404` instead of a silent no-op.
- `plugins/typetags/tests/Integration/VisibilityTest.php` witnesses the refusal on a private album
  for all three methods and the success once the account is granted that album.
