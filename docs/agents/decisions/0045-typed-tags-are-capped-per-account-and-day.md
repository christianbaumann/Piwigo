# 0045 — an account may type in at most 255 new tags in 24 hours

Date: 2026-10-09
Status: accepted

## Context

`typetags.image.addNewTag` (plan 2026-10-09, Phase 5) lets any logged-in account create tags; in
core only administrators can. `piwigo_tags.id` is `smallint unsigned`: once 65535 ids are used, no
tag can be created anywhere in the install, by an administrator either. Ids of deleted tags are not
reused.

## Decision

- An account may create at most `TYPETAGS_NEW_TAGS_PER_DAY` = 255 tags through `addNewTag` in the
  last 24 hours (a sliding window, not a calendar day). The 256th is answered `429` with
  "You have typed in too many new tags today" and nothing is created.
- Linking a tag that already exists is never refused, so the field keeps working at the cap. "Exists"
  is checked as core's `tag_id_from_tag_name()` looks first: by name, then by URL name.
- The count comes from core's activity log: `addNewTag` records each tag it creates with
  `pwg_activity('tag', id, 'add')`, which stores the method name in `details`. No table of the
  plugin's own; core never purges `piwigo_activity`.

## Consequences

- At 255 a day, one account alone needs over eight months of daily maximum use to exhaust the ids.
  An administrator still deletes unwanted tags on the tags page; that does not give ids back.
- The cap counts only this method. Tags created in the admin screens are not limited.
- Removing activity rows by hand (core's activity page offers no delete) would reset the count.
- `plugins/typetags/tests/Integration/CreationCapTest.php` witnesses the boundary, the window and
  the per-account count. Test fixtures delete the activity rows of the tags they remove, so test
  runs do not fill the test accounts' allowance.
