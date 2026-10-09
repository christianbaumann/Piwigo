# 0047 — a new tag typed in while tagging goes to the Freitext group

Date: 2026-10-09
Status: accepted

## Context

Core creates a tag whenever a name is typed into a tag field that matches no tag
(`get_tag_ids()` → `tag_id_from_tag_name()`), and the new tag has no typetags group. Such tags
were indistinguishable from the category tags, and nothing announces the new id. The owner wanted
them collected in one group (plan 2026-10-09, **Q3**), and a field on the picture page to type one
in (**Q15**).

## Decision

- photoinfo takes `photoinfo_max_tag_id()` before a save and, after it, puts every tag that
  has an id above it, no group, is not a person's tag (`piwigo_persons.tag_id`) and is linked
  to a photo the save changed into the group named `PHOTOINFO_FREITEXT_GROUP` (`Freitext`)
  (`photoinfo_assign_freitext()` in `include/writer.inc.php`, the pure part
  `photoinfo_new_freitext_tags()`). The link condition keeps out a tag another request created
  meanwhile; the `UPDATE` touches only tags still without a group. This runs before the file is
  written, so the file gets `Freitext|Name`.
- Paths that assign Freitext: the photo properties save, the Batch Manager's `add_tags`
  (snapshot on `loc_begin_element_set_global`), the `pwg.images.setInfo` wrapper (`tag_list`,
  used by the Batch Manager's unit mode), and `typetags.image.addNewTag`.
- Paths that do not: `pwg.tags.add` (not wrapped) and `pwg.tags.duplicate` on the admin tags
  page, where a tag is created deliberately and grouped there; typetags' `addTag`, which links
  an existing tag; persons' tags; `pwg.photoinfo.rescan`, which takes the group from the file.
- `typetags.image.addNewTag` (`plugins/typetags/main.inc.php`) is a field after the `+` badges
  on the picture page, for any logged-in account: POST, `pwg_token`, `image_id`, `tag_name`. A
  name that exists is linked and keeps its group; only a new name is created. It refuses a photo
  the account cannot see (decision 0044) and a new name past the daily cap (decision 0045).
  photoinfo wraps it (`ws_photoinfo_typetags_addNewTag()`) to assign Freitext, write the file and
  answer the badge's `style` and `emoji_html` as the tag's group draws them after that.
- Without photoinfo, a typed tag stays ungrouped and plain, and no file is written. Without a
  `Freitext` group, `photoinfo_assign_freitext()` does nothing and the file is still written.

## Consequences

- Typed-in tags are found in one group on the admin tags page and can be moved from there to a
  real category; a move fires `typetags_tags_regrouped` and rewrites the affected files.
- A tag created on the admin tags page and linked later by the Batch Manager is not new to that
  save and stays ungrouped.
- The group is found by its name. Renaming `Freitext` in the typetags screen turns the
  assignment off silently; `tools/deploy/tests/test_seed.py` asserts that `tag-groups.json`
  holds a group named as the constant.
- `FreitextAssignTest` in `plugins/photoinfo/tests/Integration/` covers each path, the
  `pwg.tags.add` case, the missing group and the plugin missing.
