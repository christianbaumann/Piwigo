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

* [ ] `handbuch/tools/seed.php --scenario=demo`: create a demo tag group (name like "Beispielgruppe", a colour) with one or two demo tags, assign them to a demo photo, record their ids in the snapshot as soon as they exist, and assert they took effect
* [ ] `--restore`: remove the demo group, its tags and their photo links; fail if any survives. A seed killed halfway must still be restorable
* [ ] Add `28-schlagwort-gruppen.png` to `SHOTS` in `handbuch/tools/shoot.js`: element shot of `.typetags-group[data-group-id=<demo group id>]` on `index.php?/taggroups`, as the `NORMAL` role. The seed must print the group id for `shoot.js`
* [ ] Run the cycle: `seed.php --scenario=demo`, `shoot.js`, `seed.php --restore`. Keep 28; revert every other image whose screen did not change (check that the new demo tag did not change shots 13-15; if it did, decide per shot and say why)
* [ ] `04-schlagworte.html:19`: groups are assigned by administrators; viewing them is open to every logged-in user
* [ ] `04-schlagworte.html`: new subsection after "Die vier besonderen Gruppen": how to open the page (link *Schlagwort-Gruppen* on the gallery's tag page), what it shows (each group with colour, stripes, emoji and the tags the user can see; *Kein sichtbares Schlagwort* for an empty one), that it changes nothing. Reference shot 28
* [ ] `index.html` "Wer darf was": extend the sentence from task 01 with the tag groups
* [ ] Add a dated entry to the hand-check ledger in `docs/agents/TESTING.md`: the new subsection and shot 28 checked against the real page

## Verification

* [ ] `ddev exec php handbuch/tools/check.php` passes and reports 28 screenshots, all referenced
* [ ] Shot 28 shows only the demo group and demo tags; no real group or tag name is visible
* [ ] After `seed.php --restore` the demo group and tags are gone (database query), and a second `--scenario=demo` followed by `--restore` works again
* [ ] A seed run interrupted after the group was created is cleaned up by one `--restore`
* [ ] Every German label the text quotes appears on the page exactly as written
* [ ] `git status` shows only the intended files
