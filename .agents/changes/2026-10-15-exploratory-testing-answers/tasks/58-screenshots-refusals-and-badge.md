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

* [ ] `seed.php --scenario=demo` provides what the shots need: a demo album holding a photo found in no other album, and a way to make one demo file read-only; `--restore` undoes both
* [ ] `shoot.js` entries:
  * `29-album-loeschen-verweigert.png`: delete dialog with the refusal
  * `30-album-abtrennen-verweigert.png`: Stapelverarbeitung refusal of "Von Album abtrennen"
  * `31-einzelansicht-warnung.png`: unit-mode badge with the write warning
* [ ] Element screenshots only; nothing of the real gallery can be in the frame
* [ ] Commit only the three new PNGs; revert the churn in the existing shots (handbook.md)

## Verification

* [ ] Full cycle runs under the suite lock: seed, shoot, `--restore`; install back to its album and photo counts
* [ ] Each new PNG shows the screen it is named for (inspected)
* [ ] `check.php` reports the three shots as unreferenced until tasks 59-61 add them; that is expected and noted
