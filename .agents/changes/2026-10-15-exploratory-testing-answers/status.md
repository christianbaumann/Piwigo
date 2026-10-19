# Status — 2026-10-19: tasks 1-65 done (1-54 deployed); follow-ups 66, 67 open

Tasks 55-65 (handbook gap fix), design in
[design-handbook-gap-fix.md](design-handbook-gap-fix.md). All done; review follow-ups 66 and 67 open.

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
| 06 test-user scripts CLI only | `7129dfa67` (+ typetags `b0c63cc`, pushed). Closed by 38 |
| 14 photoedit copies and sidecars | `8ee8af949` |
| 07 typetags plain names, addNewTag refusal | `64827edba` (typetags `6a5b7b4`, pushed) |
| 08 typed tag names NFC, name match | `8ebf0e7b5`, `50b483e33` (typetags `486722d`, pushed; remote intl checked) |
| 12 replace refuses ambiguous name | `aca49d9a7` |
| 13 addSimple identical / completes | `31d0a3aa8` |
| 15 photoinfo writes tags only on change | `97aff1970` |
| 16 persons names unslashed | `a0b72636f` (repair of photos 100, 103-105 run by the user 2026-10-16). Closed by 38 |
| 20 Herkunft inheritance | `b00f0b6b7` (characterization), `1c9214ebc` |
| 19 last album kept | `06e568968` |
| 17 person tag identity | `660ea947f`. Closed by 38 |
| 09 typetags CSRF | `bc0e86a6d` (typetags `16f50cc`, pushed) |
| 10 typetags failures reported | `1c17ee1a6` (typetags `ac55c5b`, pushed) |
| 11 tag writes clear user cache | `06ba59ce2` (typetags `18549d1`, pushed; decision 0071) |
| 20 handbook wording reviewed | `a3620cfb5` (also adds task 39) |
| 38 baseline suite failures | `edc67f9fe` (typetags `843065a`, **not pushed**; person 146 deleted) |
| 21 sync reads date | `61783f83f` (decision 0075) |
| 22 empty albums hidden | `2ae5e505a` (core already hides them; admin note added) |
| 24 German overrides | `6f31fba35` |
| 18 tag tools guard person tags | `7acc5a1cb` (characterization), `be1bf8005` (typetags `5d5e65d`, **not pushed**) |
| 23 menu photo count | `22ab265a7` (characterization), `71711d391` (core fix, decision 0074) |
| 25 blank album name hint | `3e88343ba` |
| 28 addRegion frame | `7367e2c59` (decision 0077: the MWG clip stays) |
| 34 IPTC charset | `6c249cf2f` |
| 30 Verlauf "Von wem" | `43c7dad22` |
| 31 handbook ch6/ch7 | `690259041` (decision 0078: marking needs a mouse) |
| 26 core tag admin | `b5927f3f0` (characterization), `8992faed0` (decision 0076) |
| 33 Notiz outside form | `66feef108` |
| 29 marking editor | `6fd846937` |
| 35 album properties | `c84c9d9db` (characterization), `7b96192a7` |
| 27 core upload | `9d78156ed` (characterization), `791e2de19` (decision 0079) |
| 39 album delete keeps photos | `a3a4602fb` (decision 0083) |
| 37 modus wrap and zoom | `3bbb97334` |
| 42 tree seed restores | `861fdfd75` |
| 43 delete refusal shown | `a0ff54e54` |
| 44 Notiz button and heading | `f3f576ad9` |
| 36 API checks, sync counts | `4e1a79ae9` (characterization), `b8256fe25` |
| 45 orphan-tag snapshot on disk | `b15bfa2b4` |
| 32 upload merges file and gallery | `3abe24231` (characterization), `45af91938` (decision 0084, supersedes 0050 for replace) |
| 46 ch6 restore end to end | `255378088` |
| 41 add-album dialog hint | closed as not needed (user's answer); finding in `docs/backlog.md` |
| 50 handbook German | `ca39d31dc` |
| 49 polluted scans | `06c68e0f7` (script run by the user 2026-10-18 14:15, `OK (real)`; `IndexRebuildTest` OK) |
| 48 tag cloud | `9fc67bde7` (also ticks task 44's work boxes, done in `f3f576ad9`) |
| 47 upload level | `e0ddda21b` (characterization), `bff4a401b` (also closes task 27's suite item) |
| 40 person tag guard | `161bc26d1` (characterization), `94dbc44b9` |

Tasks 1-54 are fully ticked (state at 2026-10-18); tasks 66 and 67 are open, see below. No agent running, no flip applied, no
seed left.

## Handbook gap fix, tasks 55-65 (2026-10-19)

| Task | Commit |
|---|---|
| 55 unit-mode write warning | `bbf8556ff` |
| 56 photoedit German errors | `a007c39fe` |
| 57 apply nearest ancestor and confirm | `564b1d51b` (decision 0086) |
| 58 screenshots of refusals and badge | `1e80e58e7` |
| 59 handbook 01 | `d27685057` |
| 60 handbook 02 | `ab3fa1224` |
| 61 handbook 03 | `742f0252f` |
| 62 handbook 04 | `2d393e842` |
| 63 handbook 05 | `670a5b209` |
| 64 handbook 06 | `5088f261c` |
| 65 independent review, ledger rows, German read | this commit |

- Review (fresh agent, playwright on the demo album, normal and webmaster): 35 rows, 31 TRUE, 4 WRONG
  (F1-F4). Report `.agent-tests/2026-10-19-handbook-gap-fix/report.md` (git-ignored). Follow-ups:
  task 66 (F3, code: `renameTag()` shows the server's message), task 67 (F1, F2, F4, a nit, two owner
  wording decisions: "Code Points", "über die Schnittstelle"). Next free decision number: 0087.
- German read (delegated to a critical subagent): wording edits in chapters 01-06; its doubt
  "Modus für alle Fotos" was wrong (core's tab is *Übersicht*), fixed in 03 and 04; "von der Galerie
  geschriebene Schlagworte" is right (TagsWritten marker), no change.
- `handbook.md` says the demo cycle returns the install to 5 albums / 105 photos (measured
  2026-08-31); it now has 106 (photo 56012, uploaded 2026-10-15). Dated measurement, left as is.
- Backlog lines added: English upload reason (core's text), no E2E for the stale-box border colour.

Incidents of this run (nothing left behind):
- One agent ran `seed.php --restore` on another agent's seeded demo album.
- One agent ran `pkill -f flock` in the container.
- A demo seed once failed with "only 13 of 6 photos": 7 stale `image_category` rows pointed at a
  reused album id. Cause not found, nothing left over.
- The review agent's Write into `.agent-tests/` was refused; the main agent wrote the report from
  its answer.

Rule learned: hold the suite lock for the whole seed, check, restore cycle in one command; never
`--restore` an album you did not seed; never `pkill` in the shared container.

## Next

Tasks 1-54 were done at that point (51 `313c967ab`, 52+53 `5294de127`, 54 `9cf2652da`). Final integration
check 2026-10-18 by an Opus 5.5 subagent: ready to deploy. Every suite once under the lock: typetags
91/179/66, provenance 313/435 (3 skipped)/79, persons 218/224 (1 skipped)/187+1 (the one,
`editor.spec.js:653`, passed alone 5x; a DB change outside the suite lock made the photo invisible
mid-test: environment), photoedit 107/110/34, photoinfo 288/230/59, galleryrules 51/84/28,
`check.php` OK, `test-hooks.sh` OK, deploy pytest 599. No design gap. Its two test-side leak fixes
and the handbook.md key count (35): `bbe01a9d5`.

Done 2026-10-19: the leftover cleanup ran (`OK`, 0 left), the deploy ran clean (94 new, 80 changed,
galleryrules activated, persons and photoinfo rescans 840 of 840), and the remote phpinfo reads
`display_errors` Local `Off` (ledger row in `docs/agents/TESTING.md`). The remote's
compiled templates were purged 2026-10-19 through the Wartung link as the deploy admin (answer:
*Aktion erfolgreich ausgeführt*). The button shows *Nicht zutreffend* until the cache sizes are
calculated, which looks disabled but is not. Nothing of this change is left.

Previously next:

1. The user runs the leftover cleanup (7 empty `provenance-sync-other-%` albums, 64 dead
   `image_category` rows, 16 `upload/buffer/*.block`, 12 `upload/galleryrules-test/*_original`);
   auto mode refuses these deletes.
2. Deploy, then the remote `display_errors` check and the compiled-template purge
   (Werkzeuge > Wartung > *Kompilierte Vorlagen entfernen*).

Questions raised 2026-10-17/18 (all answered since: 1 and 2 by task 47, 3 by task 48 and the backlog):

1. `pwg.images.upload` / `addSimple` / `add` accept a `level` between the offered ones (3, 5, 6, 7);
   admin-only. Same check as task 36's `setInfo`? (task 36 Deviations)
2. Task 36: the sync's deleted counter misses a photo stored elsewhere whose only links were in a
   removed album (rare; task 36 Deviations).
3. Task 37 / 41 / 42 backlog lines (tag cloud nowrap, letter list on phones, first-album modal,
   concurrent provenance seeds): turn any into tasks?

typetags `843065a` (task 38) and `5d5e65d` (task 18): pushed by the user 2026-10-18.

Decision numbers used so far: 0069 (05), 0070 (07), 0071 (11), 0072 (14), 0073 (03), 0074 (23),
0075 (21), 0076 (26), 0077 (28), 0078 (31), 0079 (27), 0083 (39), 0084 (32). 0080-0082 were
reserved for tasks that needed none and are unused. Next free: 0085.

Answered 2026-10-17 ("all a"): task 18's unguarded paths and the Batch Manager message become
task 40; task 25's other dialog becomes task 41; the five dead `image_category` rows are deleted
(guarded, under the suite lock) and the counts re-checked; a subagent reviews task 18's German text
and a Playwright run opens task 24's group-merge modal; `plugin-test-suites.md` names the
characterization file pattern instead of a count (done). Done 2026-10-17 after DDEV came back: the five
dead rows deleted (0 left; `CoreMenuPhotoCount*` and `CoreHiddenSubalbumCount` 14 OK), the
group-merge modal opened (task 24 note), `check.php` OK after the task 18 rewording.

Decisions the answers above cover (in the task files' Deviations):

- Task 18: not guarded, outside the design's list: `pwg.tags.rename` of a person's tag,
  `pwg.images.add` / `uploadAsync` with `tag_ids` on an existing photo, `pwg.photoinfo.pruneTags`.
  The Batch Manager's JS unit mode shows core's generic error badge, not the refusal message.
- Task 25: the add-album dialog of Fotos hinzufügen / Batch Manager (`include/add_album.inc.tpl`)
  has no hint; not examined.

New seed options of tasks 18
(`--coloured-tags`), 22 (`--scenario=empty`) and 25 are documented only in the seeds' headers.

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

## Answered 2026-10-17 (second round, "all a")

1. Task 28: keep the MWG clip, decision 0077.
2. typetags `843065a` / `5d5e65d`: the user pushes `plugins/typetags` with the right account before
   any push of the main repo.
3. Remote `display_errors`: deploy once the batch 26/28/30/34 is committed, then the read-only
   phpinfo check.
4. Next batch: 27, 29, 31, 33.
5. No worktrees; parallel agents share the main checkout on disjoint files.

Later 2026-10-17: leftover "provenance E2E tree" albums 19-25 deleted (user approved); 6-18 approved
too, the delete is handed to the user (auto mode refused it). Task 42 added for the seed leak.

## Answered 2026-10-17 (third round, "all a")

1. Task 41 closed as not needed; first-album modal in `docs/backlog.md`.
2. Task 43: both album delete screens show a refused delete's message.
3. Task 44: the photo page's Notiz button reads "Notiz speichern" and the block gets a heading.
4. Task 45: `OrphanTagsTest` keeps its unused-tag snapshot in a git-ignored file and restores leftovers at the next setUp.
5. Deploy once every task is committed; the user first commits or reverts the four `galleries/Verschiedenes/*.png`.
6. Task 46: handbook ch6's restore path run end to end on a throwaway photo.
7. Task 42's concurrent-seed race: backlog line only.

## Answered 2026-10-18

1. Task 47: uploads refuse a level outside the offered ones, and the sync counts every photo it deletes.
2. (folded into 47)
3. Task 48: the tag cloud; letter list on phones, first-album modal, concurrent seeds stay in the backlog.
4. Tasks 40, 47, 48, 49, 50 run in parallel (max 6).
5. The four PNGs turned out to be test pollution: task 49 (default a: revert the files, rescan, delete
   the four test persons and tags; the user runs the script).
6. typetags pushed by the user.
7. Task 50: a Sonnet agent reads the handbook German and adds ledger rows.

## Answered 2026-10-18 (second round, "all a"; apply when resuming)

1. Task 47 includes `pwg.images.uploadAsync` (same level check, own characterization case).
2. Task 49: the agent writes the script and proves it on throwaway copies; the user runs the final
   `! ...` command.
3. Task 40: no core edit; the persons guard merges the marked persons' tags into `tag_ids` before
   `pwg.images.add` / `uploadAsync` run.
4. Resume all five (40, 47-50) in parallel.
5. Deploy after every task is committed and task 49's cleanup has run; then the remote
   `display_errors` check and the compiled-template purge.

## Incidents (all repaired, recorded so a later failure can be traced)

- 2026-10-17/18: the login expired mid-run and cut off three verifiers (tasks 32, 45, 46). Checked
  afterwards: no mutant left (container md5 = host = backups), real unused tags 13835/28562 intact,
  no snapshot file left; one scratch test (`ScratchRestoreTest.php`) removed. All three re-verified.
- 2026-10-17: task 35's `album-tree` seed leaked 20 albums (ids 6-25, no photos); deleted (19-25 by
  the main agent, 6-18 by the user, auto mode refused it). Cause and fix: task 42.

- 2026-10-17, task 33 verify: the provenance photo anchor stayed flipped back to the old
  `savebar-footer` value for a few minutes outside the suite lock (one attempt flipped on the host
  while still queued for the lock; one restore trap used a relative path after `cd`). Restored by
  hand, templates_c cleared, md5 host = container. A provenance photo-page run in that window saw
  the old placement.

- 2026-10-17, task 34 verify: one mutant run (photoinfo tags builder without the
  `CodedCharacterSet` line, about 10 s) ran without the suite lock (the `flock` wrapper was a
  no-op). Restored at once; a parallel photoinfo run in that window could have read the mutant.

- 2026-10-17, task 23: two mutant runs did not hold the suite lock; for about 30 s each, core's
  menu count query ran with one join removed. Restored at once.

- Task 09's flip script briefly copied typetags' `admin.php` over core's root `admin.php` and
  created a stray root `main.inc.php` (relative paths in an EXIT trap after `cd`); restored, checked
  clean. Use absolute paths in flip traps.
- typetags' `TypeTags show_all` was off for 15-30 minutes after `CsrfTest` ran against old code;
  reset to `b:1`, the test now restores the config.

Task 16 found, out of its scope: the persons picker search (`pwg.persons.getList` `q`) and
`admin/persons.php`'s `$_GET['q']` still use core's slashed value (`docs/backlog.md`).

## Answered 2026-10-18 (third round; 5a explicit, the rest default a)

1. Sync orphan photo: task 51 links it to the removed album's parent (`Ohne Album`, private, at the root).
2. Handbook 07 first-photo left zone: task 52.
3. Handbook 03 names both *Notiz speichern* places: task 53.
4. Tag-cloud multi-word fixture: task 54.
5. Final integration check of the whole change by an **Opus 5.5** subagent, every plugin suite once
   under the lock, after tasks 51-54.
6. Deploy after 1-5, then the remote `display_errors` check and the template purge.

## Open items needing the user

- **Remote `display_errors` re-check:** after the next deploy, the remote phpinfo should read
  `display_errors` Local `Off` (today: Local `On` from core's default, Master `Off`). The user
  agreed to the deploy once every task is committed and the four PNGs are settled (both true
  since 2026-10-18).
- typetags `843065a`, `5d5e65d`: pushed by the user 2026-10-18 (both on `origin/master`).


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
- Staging one task's line of `.claude/rules/fork-local-core-edits.md` (HEAD + that line) leaves the
  working copy in a different line order after the commit; check `sort | diff` is empty and
  `git restore` it.
- Characterization that only passes against the old code: keep the pre-change file in the
  scratchpad, stage it with `git hash-object -w` + `git update-index --add --cacheinfo`, commit,
  then commit the change (with the working-tree version or a `git rm --cached`).
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
