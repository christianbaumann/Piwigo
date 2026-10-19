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

* [x] Test first, red: a case in persons `admin-tag-guard.spec.js` renaming a person's tag on `admin.php?page=tags` and asserting the error line shows the refusal text, not *existiert bereits*; record the red reason
* [x] `renameTag()` rejects with `data.message` when the server sends one, keeping `str_already_exist` for a real duplicate (check what the ws answers for a duplicate)
* [x] A real duplicate rename still shows *existiert bereits* (assert in the same spec)
* [x] Bullet added to `.claude/rules/fork-local-core-edits.md` in the same commit (extend the tag-screen bullet of task 26/18)

## Verification

* [x] `admin-tag-guard.spec.js` green under the suite lock (name the command)
* [x] `git diff` of `tags.js` limited to `renameTag()`

**Note:** Red reason: screen showed `Schlagwort »E2E Guard Renamed« existiert bereits`. Duplicate answers `This name is already token` (same WS_ERR_INVALID_PARAM), so `renameTag()` compares against that literal (const `RENAME_DUPLICATE_MESSAGE`, declared just above the function). Verified by admin-tag-guard.spec.js (8 passed) and the whole chromium-admin project (20 passed).

**Note (verifier):** the const copies core literal (`include/ws_functions/pwg.tags.php`, untranslated English; photoinfo wrapper returns core PwgError unchanged). Added provenance unit `TagRenameDuplicateMessageTest` (guard JS const == PHP literal); flipped const -> red, restored -> green via `ddev exec plugins/provenance/vendor/bin/phpunit --testsuite unit --filter TagRenameDuplicateMessageTest`. Other failures (403 token, missing tag) now show the server text instead of a false "existiert bereits"; empty name is a silent no-op in core, unchanged.
