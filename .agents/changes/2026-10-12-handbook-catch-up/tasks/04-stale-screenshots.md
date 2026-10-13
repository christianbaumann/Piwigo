---
id: 04
dependencies: []
---

# Task 04: Stale screenshots

Shot 26 shows the picture page's toolbar as a normal account sees it today, and the shots that
differed in the task 01 runs are each either re-committed with a reason or confirmed unchanged.

## References

* `design.md#follow-ups` (finding 5)
* `.claude/rules/handbook.md` (seed, shoot, restore; commit a re-shoot only when its screen changed; the demo tag group's badges in shots 11, 12 and 15)
* `handbuch/tools/shoot.js` (`SHOTS`: `26-fotoseite-leiste.png`, `17-personen-markieren.png`, `19-personen-auswahl.png`, `20-personen-verwaltung.png`, `22-drehen-rahmen.png`)
* `handbuch/07-fotoseite.html` (shot 26, its `alt` and `figcaption`, the bar description)
* [decision 0059](../../../../docs/agents/decisions/0059-photo-edits-are-open-to-every-logged-in-account-that-sees-the-photo.md)

## Work

* [x] Run the cycle: `seed.php --scenario=demo`, `shoot.js`, `seed.php --restore` (integration suites share the install: `ddev exec flock /tmp/pwg-suite.lock ...`)
  * **Note:** One lock hold for the whole cycle, restore from an `EXIT` trap (`.agent-tests/2026-10-13-stale-screenshots/cycle.sh`; `bash -c` through `ddev exec` mangled `$?`). 28 shots written, restore removed album 13 with 6 photos.
* [x] Compare each re-shot image with its committed version by eye. Keep 26. For 17, 19, 20 and 22 keep a shot only if its screen changed (a control, label or layout), and record the reason per shot in this file; revert every other image, including 11, 12 and 15 if they differ only by the demo group's badges
  * **Note:** Kept:
    * 26: *Drehen/Zuschneiden* between *+* and the heart
    * 17: the sidebar gained photoinfo's *Datum* and *Info* rows and the Herkunft row's *Details und Verlauf* link (also carries the two demo `+` badges, acceptable per `handbook.md`)
    * 22: the Herkunft row ends with *Details und Verlauf* (plus demo badges); the crop frame itself is unchanged
  * Reverted: 19 (one sub-pixel, `compare -metric AE` 0.01), 20 (only the *Index zuletzt aufgebaut* timestamp), 27 (only the history timestamps), 11, 12, 15 (demo badges and publication date only), 01, 03, 07, 09 (churn)
* [x] `07-fotoseite.html`: if the bar in 26 now shows *Drehen/Zuschneiden*, update the shot's `alt` text so it names what the image shows, and link the button to `06-drehen.html` where the bar is described, if it is not already
  * **Note:** `alt` and `figcaption` name *Drehen/Zuschneiden*. The page describes the bar only through the figure, so a new sentence right after it links the button to `06-drehen.html`.
* [x] Add a dated entry to the hand-check ledger in `docs/agents/TESTING.md`: which shots were re-committed and why
  * **Note:** Row *2026-10-13, Task 04* after task 02's.

## Verification

* [x] `ddev exec php handbuch/tools/check.php` passes and reports 28 screenshots, all referenced
  * **Note:** `OK  8 pages, 70 references, 28 screenshots all referenced, 8 admin routes resolve`.
* [x] Shot 26 shows the toolbar with *Drehen/Zuschneiden*, as shot 11 does
  * **Note:** Checked by viewing old and new shot 26 side by side, and new shot 11.
* [x] Every re-committed shot has a recorded reason; `git status` shows no other screenshot
  * **Note:** `git status`: only 17, 22 and 26 among the screenshots.
* [x] After `--restore` the install is back to its 5 albums and 105 photos, with no *Beispielgruppe* left
  * **Note:** Verified via SQL after the cycle and task 05's suite runs: 5 albums, 105 photos, 0 groups named *Beispielgruppe*.
