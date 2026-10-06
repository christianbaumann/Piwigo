---
id: 06
dependencies:
- 05
---

# Task 06: Handbook, backlog and test-strength audit

The German handbook explains turning and cropping, the deferred work is in the backlog, and the unit suite's strength is recorded.

## References

* `design.md#backlog`
* `.claude/rules/handbook.md`, `handbuch/tools/seed.php`, `handbuch/tools/shoot.js`, `handbuch/tools/check.php`
* `.claude/rules/mutation-testing.md`, `docs/agents/TESTING.md`
* `.claude/rules/piwigo-architecture.md` (web services section)

## Work

* [x] Handbook section "Foto drehen und zuschneiden" with element screenshots of the demo album only (extend the demo seed if needed); German strings added to `GermanOverrideKeyTest` if any core/plugin string is overridden
  **Note:** Written by a subagent in parallel, then reviewed. New `handbuch/06-drehen.html`; `index.html` gets the sixth step and a "Webmaster" entry under "Wer darf was"; `05-personen.html` links on to page 6. Screenshots `21-drehen-schaltflaeche.png` and `22-drehen-rahmen.png` are element shots of the demo album (`werkstatt`); no save happens in any shot. No seed change was needed: the demo photos are PNG, and `persons_webmaster` is a webmaster. No `GermanOverrideKeyTest` rows: every string comes from photoedit's own `de_DE` file, none from `local/language/de_DE.lang.php`. The 8 existing shots that changed only by new ids were reverted, as `handbook.md` says. Review corrected two claims: the arrows on the right of the toolbar stay in edit mode, and a marking is removed once more than half of it is cut away, not only "almost all".
  **Defect found (task 03):** in edit mode the Jcrop holder covered the photo with **opaque black**. On a non-`<img>` target, Jcrop paints its holder in `bgColor` (default black), and the photo lies under it. The geometry-only specs of task 03 could not see it. Reproduced first by a new spec that checks for covering layers (red: `div.jcrop-holder: rgb(0, 0, 0)`), then fixed in `editor.js` (`bgColor: 'transparent'`, `shade: true`, `shadeColor: 'black'`). `shoot.js` now refuses shot 22 while the holder is opaque.
* [x] `docs/backlog.md`: deskew, lossless JPEG via `jpegtran`, undo from the backup
* [x] Correct `.claude/rules/piwigo-architecture.md`: a WS handler may `include_once` `admin/include/functions.php` itself (`pwg.categories.php:755`)
* [x] Mutation table for photoedit's unit suite and persons' new region transform, recorded in `docs/agents/TESTING.md`
  **Note:** First skipped on request (2026-10-05), then run in `/verify` (2026-10-06). The first table holds the 14 mutants tasks 02 and 03 ran while building. The second run adds 24 mutants over the code tasks 04 and 05 added (M1–M14, P1–P10). A critical review of the table found likely survivors; their tests were written first (the plan's `turns`, a crop from the top in `CropTest`, a half turn plus a crop in `TransformRegionTest`), then each mutant was killed. **Survivor found and fixed:** M6, where no test asserted `rotation_before`. **Runner defects found and corrected:** a colour code hid survivors, and a first-match substitution mutated the wrong function, which also invalidated one task-03 row; that row was re-run and killed. The lesson is now in `.claude/rules/mutation-testing.md`.
* [x] Hand-check ledger entry: the edit mode looks right in the dark modus skins
  **Note:** Checked 2026-10-06 in `dark` and `dark_sky`. The `modus_theme` row was switched and put back byte for byte, using full-page screenshots of a generated test photo (`.agent-tests/2026-10-06-photoedit-dark-skins/`, git-ignored). Toolbar labels are legible, the frame and handles are visible, and the photo is clear inside the frame and darkened outside it. Recorded in the ledger of `docs/agents/TESTING.md`.

## Verification

* [x] `ddev exec php handbuch/tools/check.php` passes; seed `--restore` returns the install to its album/photo count
  **Note:** Re-run 2026-10-06 against the committed fix: seed → `shoot.js` (22 screenshots, the holder check passes) → `--restore`; 5 albums / 105 photos before and after. `check.php`: OK (7 pages, 55 references, 22 screenshots all referenced). Shots 21 and 22 are new. Five existing shots (03, 09, 11, 19, 20) changed only through demo ids, with identical dimensions, and were reverted (11 is shot as the normal account, which never sees the button).
* [x] Every mutant in the table is killed, or its survival is recorded with the reason
  **Note:** All 38 mutants are killed. M6 survived, then died once `rotation_before` was asserted. Two equivalent mutants are named and not run. Unit suites after the new cases: photoedit 95, persons 143 (1 recorded skip).
* [x] All photoedit, persons and provenance suites pass
  **Note:** 2026-10-06, run one after another (persons' E2E must not run beside photoedit's integration suite, which switches persons off for one case). photoedit: unit 90, integration 81, E2E 26. persons: unit 142 (1 skip: the recorded rotation bug), integration 110 (1 existing skip), E2E 120. provenance: unit 183, integration 184 (3 existing skips), E2E 54.

## Problem along the way

* Docker Desktop stopped mid-task. It was restarted with your OK (`open -a Docker`, `ddev start`).

**Status: approved 2026-10-06** (after `/verify`: mutation pass completed, all mutants killed).
