---
id: 58
dependencies:
- 55
---

# Task 58: Screenshots of the two refusals and the unit-mode warning

Added 2026-10-19 (brainstorm 11b). Three new element shots of the demo album exist and can be re-taken with the committed shoot cycle.

## References

* `design-handbook-gap-fix.md`, key decision *Three new screenshots*
* `handbuch/tools/shoot.js` (`SHOTS`), `handbuch/tools/seed.php`, `.claude/rules/handbook.md` (*The three commands*, *Never photograph the real gallery*)
* `admin/themes/default/js/albums.js` (`.DeleteAlbumErrors`), `plugins/galleryrules/include/events_last_album.inc.php`, task 55

## Work

* [x] `seed.php --scenario=demo` provides what the shots need: a demo album holding a photo found in no other album, and a way to make one demo file read-only; `--restore` undoes both
* [x] `shoot.js` entries:
  * `29-album-loeschen-verweigert.png`: delete dialog with the refusal
  * `30-album-abtrennen-verweigert.png`: Stapelverarbeitung refusal of "Von Album abtrennen"
  * `31-einzelansicht-warnung.png`: unit-mode badge with the write warning
* [x] Element screenshots only; nothing of the real gallery can be in the frame
* [x] Commit only the three new PNGs; revert the churn in the existing shots (handbook.md)

## Verification

* [x] Full cycle runs under the suite lock: seed, shoot, `--restore`; install back to its album and photo counts
* [x] Each new PNG shows the screen it is named for (inspected)
* [x] `check.php` reports the three shots as unreferenced until tasks 59-61 add them; that is expected and noted

**Note:** Verified via the full cycle (seed, shoot, --restore, one locked run), each PNG read by eye. `seed.php` needed no new photo: every demo photo is in the demo album only. It gained `--read-only=<slug>` and `--writable`, which shoot.js calls around shot 31. `check.php` reports 29-31 as unreferenced, as expected.

**Verifier note:** diff reviewed. The slug must be a key of the seeded demo's `photos` before the anchored glob under `upload/handbuch-demo/` runs, so no traversal; the marker check precedes it; the file mode is changed, never the directory's. `--restore` unlinks leftovers in a writable dir, so a read-only file left by a dead shoot.js is removed. Shot 31 restores in `finally`. Clips are the dialog container and the `.errors` banner only. Added the two options to the seed header. `php -l` and `node --check` clean. The shoot cycle was not re-run.
