---
id: 02
dependencies:
- 01
---

# Task 02: Schlagwort-Gruppen

The handbook shows and explains the read-only *Schlagwort-Gruppen* page, photographed from a demo
group the seed creates, so no real tag appears in the shot.

Depends on 01 because both run the seed/shoot/restore cycle (a second `--scenario=demo` refuses
while one is seeded) and both edit `shoot.js` and the same `index.html` sentence.

## References

* `design.md#findings` (finding 2)
* `design.md#shooting-the-tag-groups-page-without-real-tags`
* `design.md#placement-of-the-tag-groups-page-text`
* `design.md#wer-darf-was-in-indexhtml`
* `design.md#hand-check`
* [decision 0064](../../../../docs/agents/decisions/0064-normal-users-read-provenance-and-tag-groups-on-read-only-pages.md)
* `.claude/rules/handbook.md`
* `plugins/typetags/template/taggroups.tpl` (`.typetags-group[data-group-id]`), `template/tags_link.tpl` (*Schlagwort-Gruppen* link on the tag page)
* `plugins/typetags/include/taggroups.inc.php` (route `index.php?/taggroups`, which tags are listed)
* `handbuch/tools/seed.php` (`DEMO_PHOTO_TAGS`, `tag_id_by_name()`, snapshot, `--restore`)
* `plugins/typetags/tests/Support/FixtureBuilder.php` (`createStripedGroup()` as a model for creating a group)

## Work

* [x] `handbuch/tools/seed.php --scenario=demo`: create a demo tag group (name like "Beispielgruppe", a colour) with one or two demo tags, assign them to a demo photo, record their ids in the snapshot as soon as they exist, and assert they took effect
  * **Note:** `create_demo_tag_group()`: group *Beispielgruppe* (`#6FA8DC`), tags *Beispielschlagwort Laterne* and *Beispielschlagwort Schnee* on the `winterabend` photo (no picture page shot shows it). Refuses names the install already has; asserts both links. Deviation: the ids are not recorded in the snapshot, because `--restore` deletes by name and so also undoes a seed killed before any snapshot write.
* [x] `--restore`: remove the demo group, its tags and their photo links; fail if any survives. A seed killed halfway must still be restorable
  * **Note:** `destroy_demo_tag_group()` runs unconditionally, before the snapshot is read, and deletes by name (group, its tags, their links); fails if any survives. Another tag moved into the demo group meanwhile is only ungrouped, never deleted.
* [x] Add `28-schlagwort-gruppen.png` to `SHOTS` in `handbuch/tools/shoot.js`: element shot of `.typetags-group[data-group-id=<demo group id>]` on `index.php?/taggroups`, as the `NORMAL` role. The seed must print the group id for `shoot.js`
  * **Note:** `tag_group_id` in `demo.json`; the shot waits for a tag badge in the group first.
* [x] Run the cycle: `seed.php --scenario=demo`, `shoot.js`, `seed.php --restore`. Keep 28; revert every other image whose screen did not change (check that the new demo tag did not change shots 13-15; if it did, decide per shot and say why)
  * **Note:** The picture page offers every coloured tag as a `+` badge, so the demo tags appeared in 11, 12 (admin tag list) and 15; 13 and 14 did not change. Reverted 11, 12 and 15: their screens are unchanged except for the demo's own badges. Recorded in `.claude/rules/handbook.md` so a later re-shoot knows why. Also reverted the churn of 01, 03, 04, 09.
* [x] `04-schlagworte.html:19`: groups are assigned by administrators; viewing them is open to every logged-in user
* [x] `04-schlagworte.html`: new subsection after "Die vier besonderen Gruppen": how to open the page (link *Schlagwort-Gruppen* on the gallery's tag page), what it shows (each group with colour, stripes, emoji and the tags the user can see; *Kein sichtbares Schlagwort* for an empty one), that it changes nothing. Reference shot 28
* [x] `index.html` "Wer darf was": extend the sentence from task 01 with the tag groups
* [x] Add a dated entry to the hand-check ledger in `docs/agents/TESTING.md`: the new subsection and shot 28 checked against the real page

## Verification

* [x] `ddev exec php handbuch/tools/check.php` passes and reports 28 screenshots, all referenced
  * **Note:** `OK  8 pages, 69 references, 28 screenshots all referenced, 8 admin routes resolve`.
* [x] Shot 28 shows only the demo group and demo tags; no real group or tag name is visible
  * **Note:** Checked by viewing the image: *Beispielgruppe #6FA8DC* with its two demo tags, nothing else.
* [x] After `seed.php --restore` the demo group and tags are gone (database query), and a second `--scenario=demo` followed by `--restore` works again
  * **Note:** Verified with SQL counts after restore (0), a second seed (3 rows) and restore (0); install back to 5 albums, 105 photos.
* [x] A seed run interrupted after the group was created is cleaned up by one `--restore`
  * **Note:** Simulated: group and tag inserted by SQL with no snapshot; one `--restore` ("no snapshot") left 0 rows.
* [x] Every German label the text quotes appears on the page exactly as written
  * **Note:** *Schlagworte* (menu), *Schlagwort-Gruppen* via DOM probe as `persons_normal`; *Gestreift*, *Kein sichtbares Schlagwort* only against `plugin.lang.php` (the demo group shows neither).
* [x] `git status` shows only the intended files
  * **Note:** seed.php, shoot.js, 04-schlagworte.html, index.html, shot 28, TESTING.md, handbook.md, this file.
