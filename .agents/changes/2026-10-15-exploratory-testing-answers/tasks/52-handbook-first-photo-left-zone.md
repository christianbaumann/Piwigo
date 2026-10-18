---
id: 52
dependencies:
- 50
---

# Task 52: Handbook 07 says the left zone of an album's first photo goes to the album

Added 2026-10-18 (user's answer 2a), from task 50's open question.

## References

* Task 50, `handbuch/07-fotoseite.html`, `themes/modus/js/photo.autosize.js` (`#linkPrev`), `.claude/rules/handbook.md`

## Work

* [x] Browser check on the demo album: left-zone click on the first photo, and right-zone click on the last photo (seed, check, `--restore` under the suite lock)
  * **Note:** first photo left click and last photo right click both opened the album page (demo album, 2026-10-18, `.agent-tests/2026-10-18-task52-53/check.js`)
* [x] The sentence names what happens there, e.g. "(beim ersten Foto zurück zum Album)"; same for the last photo if it differs
  * **Note:** both behave the same; sentence added to 07

## Verification

* [x] `ddev exec php handbuch/tools/check.php` passes; dated ledger row in `docs/agents/TESTING.md`
  * **Note:** `OK  8 pages, 74 references, 28 screenshots all referenced, 8 admin routes resolve` (2026-10-18); ledger row dated 2026-10-18
