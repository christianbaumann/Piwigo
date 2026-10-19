---
id: 66
dependencies:
- 65
---

# Task 66: The tag screen shows a refused rename's own message

Added 2026-10-19 (finding F3 of the independent handbook review). `pwg.tags.rename` refuses a person's tag with `Personen-Schlagworte werden nur zusammen mit der Person umbenannt: ...`, but `renameTag()` in `admin/themes/default/js/tags.js` rejects every failure with `str_already_exist`, so the screen shows `Schlagwort »Anna Zwei« existiert bereits`, which is false. Delete and merge already show the server's message (task 18/26).

## References

* `.agent-tests/2026-10-19-handbook-gap-fix/report.md` (F3, screenshot b04)
* `admin/themes/default/js/tags.js` `renameTag()`; `.claude/rules/fork-local-core-edits.md` (tag screen bullets); persons `tests/e2e/admin-tag-guard.spec.js`
* Task 62 and 63 (the handbook statements that depend on this)

## Work

* [ ] Test first, red: a case in persons `admin-tag-guard.spec.js` renaming a person's tag on `admin.php?page=tags` and asserting the error line shows the refusal text, not *existiert bereits*; record the red reason
* [ ] `renameTag()` rejects with `data.message` when the server sends one, keeping `str_already_exist` for a real duplicate (check what the ws answers for a duplicate)
* [ ] A real duplicate rename still shows *existiert bereits* (assert in the same spec)
* [ ] Bullet added to `.claude/rules/fork-local-core-edits.md` in the same commit (extend the tag-screen bullet of task 26/18)

## Verification

* [ ] `admin-tag-guard.spec.js` green under the suite lock (name the command)
* [ ] `git diff` of `tags.js` limited to `renameTag()`
