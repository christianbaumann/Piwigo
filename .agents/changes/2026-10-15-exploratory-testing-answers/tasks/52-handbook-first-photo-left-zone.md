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

* [ ] Browser check on the demo album: left-zone click on the first photo, and right-zone click on the last photo (seed, check, `--restore` under the suite lock)
* [ ] The sentence names what happens there, e.g. "(beim ersten Foto zurück zum Album)"; same for the last photo if it differs

## Verification

* [ ] `ddev exec php handbuch/tools/check.php` passes; dated ledger row in `docs/agents/TESTING.md`
