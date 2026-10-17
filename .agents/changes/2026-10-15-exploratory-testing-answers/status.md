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
| 16 persons names unslashed | `a0b72636f` (repair of photos 100, 103-105 run by the user 2026-10-16). **Not fully ticked:** "suites pass" waits for 38 |
| 20 Herkunft inheritance | `b00f0b6b7` (characterization), `1c9214ebc` |
| 19 last album kept | `06e568968` |
| 17 person tag identity | `660ea947f`. **Not fully ticked:** "suites pass" waits for 38 (person 146) |
| 09 typetags CSRF | `bc0e86a6d` (typetags `16f50cc`, pushed) |
| 10 typetags failures reported | `1c17ee1a6` (typetags `ac55c5b`, pushed) |
| 11 tag writes clear user cache | `06ba59ce2` (typetags `18549d1`, pushed; decision 0071) |

Nothing is in progress: no agent running, working tree clean except the four
`galleries/Verschiedenes/*.png` that were modified before this change started (not ours; leave them).

## Next

Open tasks whose dependencies are done: 18, 21, 22, 23, 24, 25, 26, 27, 38 and onwards (check each
task's `dependencies`). 06's, 16's and 17's last items close with 38.

typetags `16f50cc`, `ac55c5b`, `18549d1` were pushed by the user (checked 2026-10-17).

Task 38: `IndexRebuildTest` fails only on region-less leftover person 146 `Name`; task 17 cannot
bring it back (no region, no rescan finds it). Prepared repair, not run (real data, question 1
below): `ddev mysql -e "DELETE FROM piwigo_persons WHERE id = 146 AND name = 'Name' AND NOT EXISTS
(SELECT 1 FROM piwigo_person_region WHERE person_id = 146);"`

Decision numbers used so far: 0069 (05), 0070 (07), 0071 (11), 0072 (14), 0073 (03).
Next free: 0074.

## Questions to the user (asked 2026-10-16; unanswered, so 2026-10-17 the defaults `a` apply)

1. Person 146: a) run the prepared DELETE under the suite lock, re-run `IndexRebuildTest`;
   b) leave to task 38; c) make the test skip region-less persons.
2. Task 20 — sub-albums inherit only on a new link (photos already in a sub-album, and
   *Auf N Fotos anwenden* on a sub-album without own values, do not use the parent's):
   a) keep, handbook says so; b) new task: apply uses the nearest parent, dialog shows it;
   c) one-time backfill.
3. Task 19 — deleting an album with "keep photos" can leave photos in no album:
   a) new task (refuse, or link to parent); b) backlog entry only; c) accept, decision file.
4. Task 20 — hand check of the two new paragraphs in `handbuch/03-fototexte.html`:
   a) user reads them; b) open row in the `docs/agents/TESTING.md` ledger; c) accept unchecked.
5. Next batch: a) wait for the user; b) 21, 38, 22, 23; c) only 38.

Applied 2026-10-17: 1a (in task 38), 2a, 3a (task 39 added), 4a (hand check still open), batch 18, 21, 22, 38. 2026-10-17: task 39 refuses the delete (default a); the handbook paragraphs of task 20 are read by a subagent (user's answer).

## Incidents in the 2026-10-16 batch (all repaired, recorded so a later failure can be traced)

- Task 09's flip script briefly copied typetags' `admin.php` over core's root `admin.php` and
  created a stray root `main.inc.php` (relative paths in an EXIT trap after `cd`); restored, checked
  clean. Use absolute paths in flip traps.
- typetags' `TypeTags show_all` was off for 15-30 minutes after `CsrfTest` ran against old code;
  reset to `b:1`, the test now restores the config.

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
