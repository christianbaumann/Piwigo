# 0064 — Normal users read provenance and tag groups on read-only pages

Date: 2026-10-12
Status: accepted
Supersedes decision 4 of [the role permissions research](../research/2026-10-11-role-permissions.md),
for provenance and for the Colored Tags groups.

## Context

Research decision 4 gave a normal user read-only copies of the admin screens it is marked `see`
on, with the action-only buttons shown disabled. Those screens are core admin templates the
plugin injects into. Copies would have to follow core's templates through every upstream merge,
and `admin.php` refuses anyone below administrator, so each copy would need its own entry point
anyway. A normal user needs the data, and a disabled button shows nothing.

## Decision

- One public section of `index.php`, registered on `loc_end_section_init`
  (`plugins/provenance/include/events_page.inc.php`):
  - `index.php?/provenance/album/<id>`: the album's four fields and its history.
  - `index.php?/provenance/photo/<id>`: the photo's four copied values, its own note and its
    history.
- Every logged-in account may open it, `generic` included. A guest is sent to the login page
  (`is_a_guest()`, then core's `access_denied()`; `check_status(ACCESS_CLASSIC)` would refuse a
  generic account, which core ranks with guests).
- An album or photo outside the account's visible categories answers 404, through core's
  `get_sql_condition_FandF()`. A photo is visible through any album it is in; a photo in no album
  is not found.
- The page has no form, input or button. Every change stays on the admin screens.
- The history is `pwg.provenance.getHistory`'s query (`provenance_fetch_history()`), newest 50
  rows (`PROVENANCE_HISTORY_PER_PAGE_DEFAULT`), with the name of the account behind each row.
- A failed file write's history row shows a fixed text to anyone below administrator: exiftool's
  message names server paths.
- Linked from the album page (an index button, `#provenancePageLink`) and from the picture page's
  Herkunft row.

Matrix rows for Normal, against the research note:

| ID | Research | Now |
|---|---|---|
| provenance.album | see | see, on the provenance page |
| provenance.photo-note | see | see, on the provenance page |
| provenance.history | see | see, on the provenance page |
| provenance.apply | see (disabled button) | none |
| provenance.writeback | see (disabled button) | none |
| provenance.move-prompt | see | none |

### Tag groups

- `index.php?/taggroups` (`plugins/typetags/include/taggroups.inc.php`): every colour group with
  its colour, stripes and emoji, and as badges the tags the account can see, from core's
  `get_available_tags()` like core's tags page. Same access rule as above, no control.
- Linked from core's tags page through a prefilter that includes `template/tags_link.tpl`
  (decision 0036).

| ID | Research | Now |
|---|---|---|
| typetags.groups | see | see, on the tag groups page |
| typetags.admin-chips | see | none |
| core.merge-buttons | see | none |
| photoinfo.core-screens | see | none (Normal edits on the picture page, decision 0058) |

## Consequences

- A normal user sees the name of whoever changed a value, administrators included.
- The picture page links only when its Herkunft row is shown. A photo with no provenance values
  has no row and so no link, even when it has a history; the album page's link still leads to
  the album's history.
- Rejected: re-rendered admin templates with inputs disabled, letting normal users into
  `admin.php` with a read-only flag (every core admin handler assumes an administrator), and the
  data inline on the album and picture pages (longer pages).
- Tests: provenance `tests/Unit/PageRouteTest`, `tests/Integration/ReadOnlyPageTest`,
  `tests/e2e/provenance-page.spec.js`; typetags `tests/Unit/GroupsWithTagsTest`,
  `tests/Integration/TagGroupsPageTest`, `tests/e2e/taggroups.spec.js`. No suite has a `generic`
  account; the generic case was checked by hand on 2026-10-12 (the normal test account switched to
  `generic` and back: both pages 200, before the fix 401).
