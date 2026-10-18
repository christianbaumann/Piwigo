# Status — paused 2026-10-18 (all tasks done)

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

Every task file is fully ticked; the working tree is clean. No agent running, no flip applied, no
seed left.

## Next

All 50 tasks are done (paused 2026-10-18). What follows:

1. Answer the open question below (task 47).
2. Deploy (user agreed: every task committed, task 49 run), then the remote `display_errors`
   check and the compiled-template purge (Werkzeuge > Wartung > *Kompilierte Vorlagen entfernen*).
3. A final change-integration check by a subagent (skill step "after all tasks are complete").

Open question (2026-10-18, task 47): the sync never deletes a photo stored outside a removed
folder; it only drops that photo's links, which can leave it in no album
(`delete_categories()` default `no_delete`). The count was already right; task 36's note was
wrong. Should such photos be deleted (`delete_orphans`) and counted? Behaviour change, not done.

Hand-check left open (task 50): left click zone on an album's first photo goes to the album
(handbook says only "zum vorigen Foto"); the Eigenschaften page's own *Notiz speichern* is not
named in handbook 03. Both listed in task 50's file.

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
