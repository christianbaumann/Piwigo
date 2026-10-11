---
date: 2026-10-11
git_commit: eb68a6c36
branch: master
topic: "Who may see and do what among the fork's functions: today's access and the owner's target"
tags: [research, permissions, roles, typetags, provenance, persons, photoinfo, photoedit, guests]
status: decided 2026-10-11, not implemented
---

# Role permissions: today and target

The owner set a target access level for every function the fork added since it was forked
(2026-04-19, fork point `ab91a3961^`; typetags since upstream `aaf0c0e`). Today's levels were read
from the gates in code on 2026-10-11. Nothing here is implemented yet. Each change that gets built
needs its own decision file where it replaces an existing one (0019, 0031, 0034).

Levels: `none` hidden or refused, `see` visible read-only, `edit` can execute or change.
Roles: Guest; Normal (includes core's `generic` status, since the plugins only check
`is_a_guest()`); Admin (administrator, not webmaster); Webmaster.

## Decisions

1. **Guests see nothing and may do nothing.** Done with core's `$conf['guest_access'] = false`
   (`include/config_default.inc.php:628`, used at `include/functions_user.inc.php:1484`): guests are
   sent to the login page. Set as a recorded decision, and the deploy tool sets it on the remote.
2. **The handbook `/handbuch/` requires a login.** It is static HTML and `guest_access` does not
   reach it. Serve it through a small PHP entry that checks the login; a web-server rule blocks
   the static files (nginx locally, `.htaccess` on the remote).
3. **A page view must not trigger provenance inheritance.** `check_lounge()` runs on every request
   (`include/common.inc.php:202`), before any login check, and can empty the lounge, which fires
   `associate_images_to_categories`. Provenance ignores a lounge emptying triggered by a non-admin
   request: mode "keep", no history row.
4. **Normal users get read-only copies of the admin screens they were marked `see` on**: tag colour
   groups, admin tag chips, the tag screen's merge buttons, album provenance, photo provenance,
   the Batch Manager move prompt, provenance history, core's photo screens that photoinfo writes
   from. Action-only buttons (*Apply to N photos*, *Write to N files*) appear disabled. Core's
   `admin.php` refuses anyone below administrator (`admin.php:27`), so each needs its own entry
   point.
5. **Normal users' tag changes keep going into the file** (`photoinfo.tag-keywords` stays `edit`).
   Not writing them would let the deploy's prune remove them (decision 0046).
6. **No new editing on display-only items.** The cells marked webmaster/admin `edit` on the
   Herkunft row, the region overlay, person names as tags, the name picker, the date/info rows and
   the tag-write warning stay `see`.
7. **Normal users may delete only regions they drew themselves.** Needs a "drawn by" column on
   regions; regions that exist before it count as drawn by nobody, so only admins can delete them.
   Admins and webmasters may delete any region. Replaces part of decision 0019.
8. **The persons admin screen: Admin sees, Webmaster edits.** Rename, delete and rescan become
   webmaster-only; an admin keeps the list and counts.
9. **Normal users may edit a photo's date and info text** on the picture page
   (`pwg.photoinfo.setDate` / `setInfo` lose `admin_only`).
10. **Turn and crop for Normal, Admin and Webmaster**, and whoever may edit also gets the
    lost-region warning, the re-encode note and the disabled reason. Photoedit stays inactive on
    the remote, so decision 0034's reason (an edit there changes a file the next deploy overwrites)
    still holds there. Replaces part of decision 0034.
11. **The sub-album count stays as it is.** Normal users never had the wrong count.

## Matrix

`G N A W` = Guest, Normal, Admin, Webmaster. A cell reads `today -> target` where they differ.
Unchanged functions show one value.

| ID | Function | G | N | A | W | Decision |
|---|---|---|---|---|---|---|
| core.zoom | Fit, zoom steps, 100 %, pan (picture page) | edit -> none | edit | edit | edit | 1 |
| core.subalbum-count | Sub-album count without hidden empty albums | none | none -> see | see | see | 11 |
| core.merge-buttons | Translated merge buttons (admin tags) | none | none -> see | see | see | 4 |
| core.german-strings | 24 German string overrides | see -> none | see | see | see | 1 |
| core.handbook | German handbook | see -> none | see | see | see | 2 |
| typetags.badges | Tags as colour badges | see -> none | see | see | see | 1 |
| typetags.assign-remove | Assign / remove coloured tag (picture page) | none | edit | edit | edit | |
| typetags.type-new-tag | Type a new tag, 255 per account and day | none | edit | edit | edit | |
| typetags.write-warning | Warning: tag not written into file | none | see | see | see | 6 |
| typetags.groups | Colour groups: stripes, emoji | none | none -> see | edit | edit | 4 |
| typetags.admin-chips | Colour on admin tag chips | none | none -> see | see | see | 4 |
| provenance.public-row | Herkunft row | see -> none | see | see | see | 1, 6 |
| provenance.album | Album provenance (4 fields) | none | none -> see | edit | edit | 4 |
| provenance.apply | Apply album values to its photos | none | none -> see | edit | edit | 4 |
| provenance.writeback | Write provenance into files | none | none -> see | edit | edit | 4 |
| provenance.photo-note | Photo note, album values read-only | none | none -> see | edit | edit | 4 |
| provenance.move-prompt | Move prompt (keep / clear / replace) | none | none -> see | edit | edit | 4 |
| provenance.inherit | Inherit album provenance on link / sync | edit -> none | edit -> none | edit | edit | 3 |
| provenance.history | Change history | none | none -> see | see | see | 4 |
| persons.overlay | Region overlay, Personen row | none | see | see | see | 6 |
| persons.names-as-tags | Person names as ordinary tags | see -> none | see | see | see | 1, 6 |
| persons.add-region | Draw region, pick or create person | none | edit | edit | edit | |
| persons.delete-region | Delete region | none | edit -> own only | edit | edit | 7 |
| persons.name-list | Name picker list | none | see | see | see | 6 |
| persons.admin-screen | Persons screen: rename, delete, rescan | none | none | edit -> see | edit | 8 |
| persons.admin-tagging | Admin tagging screen | none | none | edit | edit | |
| photoinfo.rows | Date and info text rows | see -> none | see | see | see | 1, 6 |
| photoinfo.edit-date | Edit date | none | none -> edit | edit | edit | 9 |
| photoinfo.edit-info | Edit info text | none | none -> edit | edit | edit | 9 |
| photoinfo.core-screens | Core screens write date, description, tags | none | none -> see | edit | edit | 4 |
| photoinfo.tag-keywords | Tags into file as keywords | none | edit | edit | edit | 5 |
| photoinfo.rescan-prune | Rescan from file, prune tags (API) | none | none | edit | edit | |
| photoedit.turn-crop | Turn / crop into file | none | none -> edit | none -> edit | edit | 10 |
| photoedit.warnings | Lost-region warning, re-encode note | none | none -> see | none -> see | see | 10 |

`provenance.inherit` for Normal was selected as `see`; it is a side effect with nothing to look at,
and decision 3 removes the only path a normal user had to it, so it is recorded as `none`.

## Findings from the analysis that the decisions leave open

- `pwg.persons.getList` without `image_id` lists every person, including names that appear only on
  photos the caller cannot see (`plugins/persons/include/ws_functions.inc.php:188-195`).
- An admin without access to a private album sees regions on the admin tagging screen but gets
  404 when saving there (`plugins/persons/admin/photo.php` has no visibility check; the write
  methods do).
- `pwg.provenance.writeBack` rewrites originals and has no webmaster check.
