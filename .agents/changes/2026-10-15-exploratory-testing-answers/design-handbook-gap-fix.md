---
datetime: 2026-10-19T10:55:00+02:00
author: Christian Baumann
tags: [handbook, photoinfo, photoedit, provenance, batch-manager, core-defects]
---

# Handbook gap fix (tasks 55-65)

A review on 2026-10-19 compared every code change since `a0cfef2c0` with `handbuch/`. Result: 4
statements contradict the code, 10 user-visible behaviours are missing, 4 groups of minor gaps. Two
of the gaps are code defects, and one is a rule the owner wants changed. A brainstorm the same day
decided the fix. This file is the design for tasks 55-65. The design of tasks 01-54 stays in
[design.md](design.md), which is complete and is not edited.

Rules that apply: [handbook.md](../../../.claude/rules/handbook.md),
[fork-local-core-edits.md](../../../.claude/rules/fork-local-core-edits.md),
[testing.md](../../../.claude/rules/testing.md), [e2e-tests.md](../../../.claude/rules/e2e-tests.md).

## The findings and where they go

```
 finding                                                       kind     chapter  task
 ─────────────────────────────────────────────────────────────────────────────────────
 Replace keeps "Titel, Beschreibung und Schlagworte" (02:68)   WRONG    02       60
 Notiz "also" on Eigenschaften (03:147, task 53)               WRONG    03       61
 Person tag put back "in der Stapelverarbeitung" (05:183)      WRONG    05       63
 "Fotoinfo:" message in Stapelverarbeitung (04:340)            WRONG*   03 04    55 61 62
 Album delete refused when a photo is left in no album         MISSING  01       59
 Last album of a photo cannot be removed                       MISSING  02 03    60 61
 Replace refused: several photos share the file name           MISSING  02       60
 Restoring a kept copy brings back its Info/date/tags/marks    MISSING  06       64
 Person tag cannot be renamed in the tag screen                MISSING  04 05    62 63
 "Auf N Fotos anwenden" overwrites; sub-album blanks values    MISSING* 01 03    57 59 61
 Empty album is invisible to visitors (+ admin hint)           MISSING  01       59
 Album/photo texts are plain text                              MISSING  01 03    59 61
 "Gestreift" and emoji only on the plugin page                 MISSING  04       62
 Sync links stray photos to parent / "Ohne Album"              MISSING  02       60
 "Fotoinfo:" on admin screens for date/Info                    MISSING  03       61
 Abbrechen: finished files stay; failed files are listed       MINOR    02       60
 Marking mode: names, drag in a box, admin icon, ...           MINOR    05       63
 Photoedit errors in English; arrow keys blocked in edit mode  MINOR*   06       56 64
 "auf welchem Weg auch immer" overclaims (decision 0050)       MINOR    04       62

 * = needs a code change first
```

Line numbers are those at `4b0400c3b`.

## Task graph

```
 wave 1 (parallel)            wave 2 (parallel)               wave 3
 ─────────────────            ─────────────────               ──────
 55 unit-mode warning ──┬──▶ 58 screenshots 29-31 ──┬──▶ 59 ch01 (also 57)
                        │                            ├──▶ 60 ch02
                        │                            └──▶ 61 ch03 (also 55, 57)
                        └──▶ 62 ch04
 56 photoedit German ──────▶ 64 ch06
 57 apply rule ─────────────────────────────────────▶ (59, 61)
 63 ch05  (no dependency)
                                                          all ──▶ 65 review
```

The owner runs the tasks with `/implement-tasks` in these waves (63 joins wave 1).
Each handbook chapter belongs to exactly one task, and each task owns its files (see *Parallel work*
below).

## The unit-mode write warning (task 55)

```
 Stapelverarbeitung, unit mode, file not writable
   pwg.images.setInfo ─▶ ws_photoinfo_images_setInfo()       answer: stat ok
                         (plugins/photoinfo/include/            written false, message (English)
                          events_core_edit.inc.php:174)         tags_written false, tags_message_l10n
   batchManagerUnit.js:69/124 reads stat only ─▶ green badge, no text      ◀── defect
```

Target: when `written === false` or `tags_written === false`, the photo's badge shows the German
message through the existing `showErrorLocalBadge(pictureId, message)`
(`admin/themes/default/js/batchManagerUnit.js:258`, added by task 40). The answer gets a
`message_l10n` for the date/Info write, built the same way as `tags_message_l10n`
(`photoinfo_tag_write_answer()`, `photoinfo_l10n_reason()`).

## The apply rule (task 57)

`ws_provenance_applyToPhotos()` (`plugins/provenance/include/ws_functions.inc.php:180`) copies the
album's own four fields onto every photo in the album, and clears a photo field when the album field
is empty. The clearing is on purpose (code comment there).

```
 album tree        today                          target
 Eltern (A,B)      apply on Eltern: A,B           unchanged
  └ Kind (empty)   apply on Kind: blanks A,B      writes A,B (nearest ancestor,
                                                  provenance_inherited_album_values())
 photo with Herkunft from album X
                   overwritten without notice     overwritten after a confirm naming the count
```

## Key Decisions

### Scope: every finding

- **Decision:** All WRONG, MISSING and MINOR findings are fixed.
- **Reason:** The owner chose full coverage (brainstorm Q1 A).
- **Trade-offs:** More text to keep true later. Rejected: WRONG + MISSING only (the recommendation),
  WRONG only.

### The work lives in this change

- **Decision:** Tasks 55-65 in `2026-10-15-exploratory-testing-answers`, with this file as their
  design.
- **Reason:** The owner's choice (Q1b). The findings come from this change's own work.
- **Trade-offs:** A long change gets longer. `design.md` is complete, so this design is a sibling
  file and not an edit of it.

### Unit mode shows a failed file write

- **Decision:** Fix the code (task 55). photoinfo's `setInfo` answer carries `message_l10n` for
  date/Info. `batchManagerUnit.js` shows the German message in the photo's error badge when
  `written` or `tags_written` is false. This is a fork-local core edit and gets its bullet.
- **Reason:** A green badge over a failed write hides data loss. The handbook already promises the
  message (Q2 A, 2a).
- **Trade-offs:** Rejected: document the gap (teaches a workaround for a bug); a new warning badge
  style (new UI element, the error badge with text is enough); the raw English `message`.

### Tests for the unit-mode warning sit at two layers

- **Decision:** Integration (photoinfo `CoreEditTest`): the answer carries `message_l10n` and
  `tags_message_l10n` in German. E2E (photoinfo, new spec): unit mode with a read-only file shows the
  message in the badge for a date, an Info and a tag change. Unit layer: not applicable. Core JS has
  no unit runner, and the branch is two lines in a jQuery success handler. Each test is red first.
- **Reason:** The server answer is a two-part boundary (ws and file). What the badge shows is
  client-side JS, so only a browser can witness it (testing.md *Placement rule*).
- **Trade-offs:** Accepted: an E2E spec for a two-line branch.

### Photoedit failures are German

- **Decision:** Fix the code (task 56). Every message `pwg.photoedit.apply` can return to a
  logged-in user goes through `l10n()`, with de_DE and en_UK strings. Integration tests assert the
  German text. The translation happens at the ws boundary (`ws_functions.inc.php`), so internal
  English messages and the tests on them stay. The handbook gets one generic sentence: a failed
  save shows a message with the reason.
- **Reason:** Quoting English messages in a German handbook documents a defect (Q7 / 5a).
- **Trade-offs:** Rejected: say errors may be English; quote them.

### "Auf N Fotos anwenden" applies the nearest ancestor's values and warns before overwriting

- **Decision:** Task 57, new decision file **0086**:
  - An album with all four fields empty applies the values of its nearest ancestor that has any
    (`provenance_inherited_album_values()`, the same rule as inheritance for new links).
  - Overwriting stays. Before the apply, `pwg.provenance.applyToPhotos` with `dry_run=1` counts
    the photos whose Herkunft is not empty and differs in at least one field from what apply
    would write. When the count is above 0, a confirm names it and lets the admin cancel.
  - Clearing a field stays when the album has values of its own.
- **Reason:** The owner chose to change the code (4b, 10a). Blanking a sub-album's inherited values
  contradicts the inheritance rule of task 20. Overwriting is the button's purpose, but it should
  not happen silently.
- **Trade-offs:** A sub-album whose fields were all cleared on purpose can no longer clear its
  photos by apply while an ancestor has values. Rejected: fill only photos without a Herkunft plus
  an "overwrite all" switch (more UI); no confirm; a new count method (needs role-matrix rows); a
  count rendered into the page (stale after edits elsewhere); counting every photo with a
  Herkunft (also counts photos apply would not change).

### The wrong Notiz sentence is deleted

- **Decision:** Task 61 deletes the last sentence of 03 *Herkunft und Notiz*. The Notiz block exists
  only on the photo's Eigenschaften page, which that section already describes
  (`plugins/provenance/include/events_admin.inc.php:122`).
- **Reason:** Task 53 assumed chapter 03 described the picture page. It never did.
- **Trade-offs:** The owner chose to record the wrong premise in task 53 (3a). Completed task files
  are not edited (changes-folder rule), so this design and task 61 record it instead.

### Three new screenshots

- **Decision:** Task 58 adds element shots of the demo album to `handbuch/tools/shoot.js`:
  - `29-album-loeschen-verweigert.png`: the delete dialog with its refusal (`.DeleteAlbumErrors`), for 01
  - `30-album-abtrennen-verweigert.png`: the Stapelverarbeitung refusal of "Von Album abtrennen", for 02
  - `31-einzelansicht-warnung.png`: the unit-mode badge with the write warning, for 03

  `seed.php` gets what the shots need (a demo photo in one album only, a read-only demo file), and
  `--restore` undoes all of it.
- **Reason:** The owner asked for shots of the refusals and the badge (11b).
- **Trade-offs:** Three more shots to keep current. Never a real photo (handbook.md).

### Verification and a final review

- **Decision:**
  - Each task: `check.php` passes, its suites are green with the command named, and each new
    statement is checked once in the browser on the demo album.
  - Task 65: a fresh agent with no context reads every changed section against the code. With
    `playwright-cli` it walks each statement on the demo album, as a normal account and as
    webmaster, and writes a report with screenshots to `.agent-tests/2026-10-19-handbook-gap-fix/`.
    The ledger rows go into `docs/agents/TESTING.md`. The owner reads the German last.
- **Reason:** The owner's choice (8a plus review).
- **Trade-offs:** The review agent reports and does not fix. Fixes become a follow-up task, so the
  review stays independent.

## Parallel work

Several agents share one checkout and one install, so the following holds:

- **One owner per file.** Chapter `NN` is edited only by its task. Code tasks do not touch
  `handbuch/`. `shoot.js` and `seed.php` belong to task 58.
- **`docs/agents/TESTING.md` is written once, by task 65.** Each task records its browser-check
  result as a note in its own task file. This avoids several agents committing each other's
  uncommitted hunks in one shared file.
- **One demo album at a time.** `seed.php --scenario=demo` refuses while one is seeded. Seed, check
  and `--restore` run under `flock /tmp/pwg-suite.lock`, like every integration and E2E run.
- **Commit only your own paths:** `git add <paths>`, then `git commit`. Never `git add -A`.
- **One mutant script restores its file** (mutation-testing.md): backups go to
  `/tmp/<task>-flip-<label>.bak`.
