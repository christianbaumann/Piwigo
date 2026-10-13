---
datetime: 2026-10-12T23:01:02+02:00
author: Christian Baumann
tags: [handbuch, documentation, screenshots, provenance, typetags, persons]
---

# Handbook catch-up with the 2026-10-12 role changes

The German handbook (`handbuch/`) was last changed in `d14c1754f`. Before that, and during the
role-permission change (`.agents/changes/2026-10-11-role-permissions/`), some user-visible changes
landed that the handbook does not describe. This change closes those gaps. `check.php` passed on
2026-10-12 (8 pages, 66 references, 26 screenshots), so the gaps are in the content, not broken
references.

Rules that apply: [`.claude/rules/handbook.md`](../../../.claude/rules/handbook.md) (seed, shoot,
restore; never photograph the real gallery).

## Findings

| # | Change | Source | Handbook today |
|---|---|---|---|
| 1 | Read-only provenance page `index.php?/provenance/album\|photo/<id>`: values, photo note, history (*Verlauf*). Linked as *Details und Verlauf* in the picture page's Herkunft row and as button *Herkunft* on the album page | `2c22602c9`, [decision 0064](../../../docs/agents/decisions/0064-normal-users-read-provenance-and-tag-groups-on-read-only-pages.md) | not mentioned. `03-fototexte.html:270` describes the Herkunft row without the link. `01-alben.html` covers only the admin dialog |
| 2 | Read-only tag groups page `index.php?/taggroups` (*Schlagwort-Gruppen*), linked from the gallery's tag page | typetags `6c917c9`, decision 0064 | not mentioned. `04-schlagworte.html:19` presents groups as admin-only |
| 3 | Person picker leaves out people whose regions are all on photos the user cannot see; *Anlegen "..."* then attaches the existing person | `dd078f6dd`, [decision 0061](../../../docs/agents/decisions/0061-persons-list-and-tagging-screen-respect-album-permissions.md) | `05-personen.html:75-76` says *Anlegen* always creates a new person |
| 4 | At most `TYPETAGS_NEW_TAGS_PER_DAY` (255) tags typed in per account and day; error *Heute wurden schon zu viele neue Schlagworte eingegeben* | typetags `c69ab4f` | covered only by the generic "Schlägt das fehl, steht der Grund neben dem Feld" |

Two existing screenshots are stale as a result of finding 1: both were shot 2026-10-09, before the
link and the button existed.

```
04-album-oeffentlich.png  shoot.js: #content of the album page    -> button "Herkunft" missing
11-foto-oeffentlich.png   shoot.js: #content of the picture page  -> link "Details und Verlauf" missing
```

## Target state

```
handbuch/
  index.html          "Wer darf was": + logged-in users read provenance + history, and the tag groups
  01-alben.html       Herkunft des Albums: + link to "Herkunft nachlesen" (03)
  03-fototexte.html   + subsection "Herkunft nachlesen" after "Herkunft und Notiz"   [shot 27]
                      Herkunft row (:270): + link "Details und Verlauf"               [shot 11 re-shot]
  04-schlagworte.html :19 + "ansehen kann jeder angemeldete Benutzer"
                      + subsection on "Schlagwort-Gruppen" after "Die vier besonderen Gruppen" [shot 28]
                      "Eintippen": + daily limit, with the error text
  05-personen.html    :75-76 + "gibt es sie schon, wird die vorhandene zugeordnet"
  tools/seed.php      + demo tag group with demo tags, removed by --restore
  tools/shoot.js      + 27-herkunft-seite.png, 28-schlagwort-gruppen.png
  assets/screenshots/ re-shot 04, 11; new 27, 28
docs/agents/TESTING.md  + dated hand-check ledger entry
```

## Key Decisions

### Scope of the read-only pages

- **Decision:** Write the text, re-shoot `04-album-oeffentlich.png` and `11-foto-oeffentlich.png`,
  and add two new screenshots: 27 (provenance page) and 28 (one tag group).
- **Reason:** Every other function in the handbook has a picture of its screen. 04 and 11 are
  already stale, so a re-shoot is necessary anyway.
- **Trade-offs:** Two more `shoot.js` entries to maintain. Rejected: text and re-shoot only (two
  screens without a picture); text only (leaves two stale screenshots).

### Shooting the tag groups page without real tags

- **Decision:** `seed.php --scenario=demo` creates its own demo group (for example
  "Beispielgruppe") with one or two demo tags on a demo photo; `--restore` deletes them.
  `shoot.js` takes an element shot of that group only:
  `.typetags-group[data-group-id=<demo id>]` (`plugins/typetags/template/taggroups.tpl`).
- **Reason:** The page lists every group with every tag the account can see. Real groups such as
  *Personen* hold the names of real people. The seed until now used the install's real tags on
  purpose (`DEMO_PHOTO_TAGS`, `tag_id_by_name()`), so it has no demo group to show.
- **Trade-offs:** The seed grows a create/remove pair for a group and its tags, which `--restore`
  must undo reliably. Rejected: shooting only the link (shows nothing of the page); shooting the
  full page and cropping or blurring (forbidden by `handbook.md`).

### Placement of the provenance page text

- **Decision:** A new subsection "Herkunft nachlesen" in `03-fototexte.html` after
  "Herkunft und Notiz". `01-alben.html` and the Herkunft row description (`:270`) link to it.
- **Reason:** 03 already explains the Herkunft fields of a photo; one place for the page avoids
  two descriptions of the same screen.
- **Trade-offs:** Readers of 01 need one click. Rejected: two short notes in 01 and 03 (two
  copies); a new page `08-herkunft.html` (too small for a page, shifts the Weiter/Zurück chain).

### Placement of the tag groups page text

- **Decision:** A new subsection in `04-schlagworte.html` after "Die vier besonderen Gruppen";
  line 19 gets "ansehen kann jeder angemeldete Benutzer".
- **Reason:** The groups are explained there; the new page is the read view of the same groups.
- **Trade-offs:** Rejected: one sentence in "Farbige Schlagworte" (no room for the shot); a new page.

### "Wer darf was" in `index.html`

- **Decision:** Add one sentence: every logged-in user can read the provenance with its history,
  and the tag groups.
- **Reason:** The section is the role overview; without the sentence it understates what a normal
  user can do.
- **Trade-offs:** Rejected: no change (overview incomplete); a roles x functions table (a second
  copy of the role matrix in `.agents/changes/2026-10-12-role-test-coverage/design.md`, which goes stale).

### Picker sentence in `05-personen.html`

- **Decision:** "...legt die Person neu an; gibt es sie schon, wird die vorhandene zugeordnet."
  Album permissions are not mentioned.
- **Reason:** The sentence becomes correct without explaining an edge case the reader cannot act on.
- **Trade-offs:** Rejected: explaining private albums (detail for an edge case); no change (the
  sentence stays wrong).

### Daily limit for new tags

- **Decision:** One sentence in "Eintippen" with the limit 255 and the error text as the page shows
  it: *Heute wurden schon zu viele neue Schlagworte eingegeben*
  (`local/language/de_DE.lang.php:43`).
- **Reason:** A user who reaches the limit can look up the message.
- **Trade-offs:** The number is a second copy of `TYPETAGS_NEW_TAGS_PER_DAY`
  (`plugins/typetags/include/functions.inc.php`) and goes stale if the constant changes; accepted,
  because the handbook is prose with no machine check of content. Rejected: no mention; also
  explaining the reason (database limits are not user information).

### Commits

- **Decision:** One commit per task (`tasks/`), each carrying its tooling, its screenshots and the
  text that references them. `ddev exec php handbuch/tools/check.php` passes before each.
- **Reason:** `check.php` fails on a screenshot no page references (`handbuch/tools/check.php`,
  "is never referenced by any page"), so screenshots cannot be committed apart from their text.
- **Trade-offs:** Tooling and prose are reviewed in one commit. Rejected: tooling and screenshots
  first, text second (fails `check.php`); one commit for everything (mixes three slices).

### Hand check

- **Decision:** One dated entry in the hand-check ledger of `docs/agents/TESTING.md`: the new
  sections were checked against the real pages (wording, each screenshot shows its screen).
- **Reason:** `check.php` cannot judge prose or screenshot content (`handbook.md`, "What has no
  automated oracle").
- **Trade-offs:** Rejected: `check.php` alone; a ledger entry only when a screenshot changes.

## Process

```
seed.php --scenario=demo  ->  shoot.js  ->  seed.php --restore  ->  check.php
```

Commit a re-shot image only when its screen changed (04, 11, 27, 28); revert the churn of the
others (`handbook.md`: a re-shoot is reproducible in content, not byte-for-byte).

## Follow-ups

Found while implementing tasks 01 to 03 (2026-10-13), outside their scope:

| # | Finding | Source |
|---|---|---|
| 5 | `26-fotoseite-leiste.png` lacks the *Drehen/Zuschneiden* button a normal account has had since [decision 0059](../../../docs/agents/decisions/0059-photo-edits-are-open-to-every-logged-in-account-that-sees-the-photo.md); shot 11 (re-shot in task 01) shows it. Shots 17, 19, 20 and 22 also differed after the task 01 runs and were reverted unexamined | task 01 notes |
| 6 | No suite case asserts that *Anlegen "..."* with the full name of a person who is only on photos the account cannot see attaches that existing person ([decision 0061](../../../docs/agents/decisions/0061-persons-list-and-tagging-screen-respect-album-permissions.md)); task 03 witnessed it once with an ad hoc copy of `VisibilityTest`, then deleted it | task 03 notes, `docs/agents/TESTING.md` ledger row of task 03 |

Decision on 6: keep it as a regression test in `plugins/persons/tests/Integration/VisibilityTest.php`,
next to the cases that leave such a person out of the list, rather than in the hand-check ledger.
