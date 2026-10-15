---
id: 01
dependencies: []
---

# Task 01: Fork-local core edits live in one rules file

All fork-local core edits are listed in one rules file that every core-editing task extends, and CLAUDE.md stays under its 100-line cap.

## References

* `design.md#fork-local-core-edits-are-listed-in-one-rules-file`
* `CLAUDE.md` (*Project Overview*)
* `.claude/rules/backpressure.md` (*Documentation files have a length budget*)

## Work

* [x] Create `.claude/rules/fork-local-core-edits.md`; move verbatim from `CLAUDE.md` the two provenance triggers, `discount_hidden_subalbums()` (decision 0037), `handbuch.php` (decision 0063), the `tags.tpl` merge buttons and the modus zoom (decision 0032)
  **Note:** Moved verbatim except relative links, rewritten for the file's new directory (`docs/…` → `../../docs/…`, `.claude/rules/git-and-commits.md` → `git-and-commits.md`); a short header states the read trigger and that each new core edit is added in its own commit.
* [x] Replace them in `CLAUDE.md` with one link under *Additional rules*, read trigger "read before editing core or pulling from upstream"
* [x] Point `.claude/rules/git-and-commits.md` (upstream pull section) at the new file

## Verification

* [x] `CLAUDE.md` has fewer than 100 lines and links the rules file with its read trigger
  **Note:** Verified via `wc -l CLAUDE.md` (81 lines) and `bash tools/test-hooks.sh` (length gate self-test passes)
* [x] Every fork-local core edit CLAUDE.md named before is in the rules file, none in two places
  **Note:** Verified via `grep -c` of a key per edit (`associate_images_to_categories`, `discount_hidden_subalbums`, `handbuch.php` serves, `Confirm merge`, `rvas_choose`): 0 in CLAUDE.md, 1 in the rules file. Historical decisions (0013, 0014, 0031) and ledger entries still say "CLAUDE.md"; left as written, since decisions are not edited
* [x] Rules file under 500 lines
  **Note:** Verified via `wc -l` (13 lines)
* [x] Relevant suites pass, each named with the command that ran (`.claude/rules/plugin-test-suites.md`)
  **Note:** Documentation only; no plugin suite reads these files. Ran `uv run pytest tests/test_readme.py` in `tools/deploy` (reads CLAUDE.md, 20 passed) and `bash tools/test-hooks.sh` (all cases passed)
