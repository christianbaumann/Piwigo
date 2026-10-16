# Status — paused 2026-10-16

Handoff for the next agent. Task files hold the per-item detail; this file holds only what is
not in them.

## Done (committed on `master`, each task file fully ticked with notes)

| Task | Commits |
|---|---|
| 01 fork-local core edits rules file | `3a7932e1c` |
| 02 `plugins/galleryrules` skeleton | `98989fc8e` |
| 03 plain-text fields | `05ad8fcdc` (characterization), `121879833`, `d1220cd31` (admin browser check) |
| 04 album lock clears cache | `26fdb1491` (characterization), `2c04c285d` |
| 05 remote hides PHP errors | `4e7917981`, ledger entry in `8ede68ae5` |
| 06 test-user scripts CLI only | `7129dfa67` (+ typetags `b0c63cc`, pushed). **Not fully ticked:** "the five integration suites pass" waits for task 38 |
| 14 photoedit copies and sidecars | `8ee8af949` |
| 07 typetags plain names, addNewTag refusal | `64827edba` (typetags `6a5b7b4`, pushed) |
| 08 typed tag names NFC, name match | `8ebf0e7b5`, `50b483e33` (typetags `486722d`, pushed; remote intl checked) |
| 12 replace refuses ambiguous name | `aca49d9a7` |
| 13 addSimple identical / completes | `31d0a3aa8` |
| 15 photoinfo writes tags only on change | `97aff1970` |
| 16 persons names unslashed | `a0b72636f` (repair of photos 100, 103-105 run by the user 2026-10-16) |

Nothing is in progress: no agent running, working tree clean except the four
`galleries/Verschiedenes/*.png` that were modified before this change started (not ours; leave them).

## Next

Open tasks whose dependencies are done: 09, 10, 11 (typetags submodule — one after another or one
agent; two agents committing in the same submodule race), 17, 19, 20, 21, 38, and onwards
(check each task's `dependencies`). 18 needs 17. 06's last item closes with 38.

Task 38 has a new finding (2026-10-16) in its file: `IndexRebuildTest` now fails on region-less
person 146 `Name`, linked to the marker tag `Name ?` by `url_name` (the defect task 17 removes).
Doing 17 before 38 is sensible.

Decision numbers used so far: 0069 (task 05), 0070 (task 07), 0072 (task 14), 0073 (task 03).
Next free: 0071, then 0074.

Task 15 left a typetags script quirk for task 10: the picture page script treats
`tags_written: false` (now also "nothing changed") as a failure and unhides the warning span,
which shows a stale message after an earlier failed write (see task 15's Deviations).

Task 16 found, out of its scope: the persons picker search (`pwg.persons.getList` `q`) and
`admin/persons.php`'s `$_GET['q']` still use core's slashed value (`docs/backlog.md`).

## Open items needing the user

- **Remote `display_errors` re-check:** after the next deploy, the remote phpinfo should read
  `display_errors` Local `Off` (today: Local `On` from core's default, Master `Off`). A deploy
  changes the live web space; ask before running it.


## Known failures (not caused by this change; task 38)

- typetags `CreationCapTest::testTheLastTagOfTheDayIsCreated` — fixture adds cap−1 rows on top of
  the account's real creations of the day. Passed in the 2026-10-16 runs, but only because the day's
  count happened to be low; the fixture still does not force its precondition.
- persons `IndexRebuildTest` — since task 16 and the repair: region-less leftover person 146 `Name`
  (linked to marker tag `Name ?`) does not come back; see task 38's findings.

## Working rules learned this session

- Agents share one DDEV install: wrap integration/E2E runs in
  `ddev exec flock /tmp/pwg-suite.lock ...`; hold the lock for a mutation flip's whole
  apply/test/restore cycle (task 04 once applied a mutant before taking the lock).
- No worktrees: DDEV serves only the main checkout. Parallel agents edit shared files with small
  exact replacements and do not commit; the main agent stages per task (`git hash-object` +
  `git update-index --cacheinfo` to stage one task's hunks of a shared file).
- `.claude/rules/plugin-test-suites.md` is at 498 of 500 lines; any addition there must remove
  as much.
- `tools/deploy` README and `deployment.md` cite the pytest count (598 on 2026-10-15);
  `test_readme.py` fails when a new deploy test changes it.
- The pre-commit hook runs every plugin's unit suite on the **working tree**: a parallel agent's
  red unit test blocks every commit. Commit while agents are green, or wait. With suites running
  in parallel a commit takes several minutes; run `git commit` in the background.
- The auto-mode classifier refuses scripts that rewrite real gallery photos even with the user's
  go-ahead; hand the user the exact `! ...` command instead.
- Remote read-only checks: log in through `pwg.session.login` with `tools/deploy/deploy.local.json`'s
  admin, then `admin.php?page=maintenance&action=phpinfo&pwg_token=...` (ledger in `docs/agents/TESTING.md`).
- Local browser checks: `http://piwigo.ddev.site` (the https certificate is refused by
  playwright-cli's browser); log in with an in-page `fetch` to `pwg.session.login`.
