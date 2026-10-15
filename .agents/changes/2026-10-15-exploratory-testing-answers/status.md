# Status — paused 2026-10-15

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
| 06 test-user scripts CLI only | `7129dfa67` (+ typetags submodule `b0c63cc`, **not pushed**) |
| 14 photoedit copies and sidecars | `8ee8af949` |
| 07 typetags plain names, addNewTag refusal | see `git log` (typetags `6a5b7b4`, **not pushed**) |

Task 38 (two failing baseline tests) was added in `8ede68ae5`; not started.

## Next

Lowest open tasks whose dependencies are done: 07, 08, 09, 10, 11 (typetags submodule — run
them one after another or with one agent, two agents committing in the same submodule race),
12, 13, 15, 16, 19, 20, 21, ... Task 17 needs 16; 18 needs 17; 38 needs 16.

Decision numbers used so far: 0069 (task 05), 0072 (task 14), 0073 (task 03). 0070 and 0071
were reserved but not used; the next free number is 0070.

## Open items needing the user

- **typetags push refused (403, `dark-sith-lord`):** `b0c63cc` reached the remote, but `6a5b7b4`
  (task 07) and later typetags commits stay unpushed until the user pushes. Recorded in `docs/backlog.md`.
- **Remote `display_errors` re-check:** after the next deploy, the remote phpinfo should read
  `display_errors` Local `Off` (today: Local `On` from core's default, Master `Off`). A deploy
  changes the live web space; ask before running it.

## Known failures (not caused by this change; task 38)

- typetags `CreationCapTest::testTheLastTagOfTheDayIsCreated` — fixture adds cap−1 rows on top of
  the account's real creations of the day.
- persons `IndexRebuildTest` — leftover person `D'Arcy "R" & Töchter` (id 157) gives a duplicate
  key in the rescan; likely the task 16 escaping defect.

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
- Local browser checks: `http://piwigo.ddev.site` (the https certificate is refused by
  playwright-cli's browser); log in with an in-page `fetch` to `pwg.session.login`.
