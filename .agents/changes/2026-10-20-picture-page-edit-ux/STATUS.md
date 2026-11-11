# Status: picture-page edit UX

Done 2026-11-12 (session s-e4c1). All 14 tasks are merged on `master`; each task file holds its checkboxes and *Implementation notes*.

## Open

- **Push `plugins/typetags`.** `master` pins 424ea62; five submodule commits are not on `github.com/christianbaumann/Piwigo-Colored-Tags` yet (c48112b, 6419cf9, 315e328, af47f60, 424ea62). A fresh clone fails until they are pushed.
- **Stale CLAUDE.md line.** It says persons fires one event of its own (`persons_tags_changed`); it now also fires `persons_regions_changed`, the filter `persons_regions_answer`, and answers `persons_undo_region` (tasks 04, 08).
- **Design questions, not decided:** `pwg.tags.merge` / `pwg.tags.delete` and person rename / delete change photos without a change-log entry (tasks 03, 04).
- **Hand checks:** the handbook pages 03-06 against the live page (ledger row in `docs/agents/TESTING.md`); the Info row's real leave warning in a browser; with photoinfo deactivated, typetags' and persons' answers carry no history id; the Batch Manager unit mode's Referer.
- **Deploy:** typetags', photoinfo's and persons' prefilter code changed, so the remote needs the manual compiled-template purge after the deploy that carries this change.
- **Test leftovers in the install, not from this change:** the tags „ETS27 Alt“ (visible in handbook shots 11 and 15) and „Persons guard extra 40e11fc9“.

## Testing a worktree

DDEV serves only the main checkout. `.agents/worktrees/.tools-s-e4c1/` (git-ignored) holds `wt-setup` and `wt-test`, which serve a worktree with PHP's built-in server in the web container under the suite lock; `BRIEF.md` there explains them.
