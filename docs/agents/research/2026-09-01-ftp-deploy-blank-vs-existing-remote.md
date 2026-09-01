---
date: 2026-09-01T09:50:06+00:00
git_commit: 2fc6e14936ab4e012cf9a05ba86423b3c936622d
branch: master
topic: "FTP deploy: blank remote vs. already-running instance, prune, and incremental upload"
tags: [research, deployment, ftp, pwgdeploy, manifest, prune, idempotence]
status: complete
---

# Research: how the FTP deploy distinguishes a blank remote from a running one, and what an update run does

## Research Question

> The ftp upload shall
> - differentiate between a blank/empty remote, and an already running instance that gets updates
> - if already running instance: remove on remote what does not exist locally any more (piwigo files, not images!), upload only changed/new files, etc.
> — what also comes with such changes and I did not list?

This document records **what the tool does today**. It proposes nothing.

## Summary

Both listed behaviours already exist in `tools/deploy` and have since Phases 3, 5 and 6 of
[the deployment plan](../plans/2026-08-31-ftp-deployment-and-remote-install.md):

- **Blank vs. running is decided twice, by two independent oracles**, one per half of the run:
  - the **upload half** asks a *local* file — `tools/deploy/.state/<host>_<root>.json`. Absent or
    unreadable manifest → every path is `new`. The server is never read back
    (`upload.py:3-6`, `manifest.py:5-6`).
  - the **bootstrap half** asks the *remote* — `GET install.php` and looks for the literal
    `Piwigo is already installed` (`bootstrap.py:32`, `bootstrap.py:92-93`), which is the same
    question `install.php:156-165` asks itself.
  These two can disagree, and the failure mode when they do is documented rather than guarded
  (README.md:117-125).
- **Incremental upload** exists: sha256 per file, compared against the manifest, four buckets
  (`new` / `changed` / `unchanged` / `removed`), only `new + changed` are sent
  (`manifest.py:94-115`, `upload.py:70-98`).
- **Prune** exists: `diff.removed` — paths the *previous manifest* recorded and the current file
  set no longer holds — are `DELE`d (`upload.py:100-105`), suppressible with `--no-prune`,
  predicted by `--dry-run` (`cli.py:157-166`).
- **Images are protected by construction, but only some of them.** `upload/` and `_data/` never
  enter a manifest, so no prune can reach them. **`galleries/` is different**: 106 tracked photo
  files *are* in the published file set and *are* therefore prune-eligible if they are removed
  from the working copy (`.gitignore:14-21`, verified: `git ls-files galleries | wc -l` → 106).

What the question did not list, and what the codebase shows comes with this shape of design, is
in §7 below — the largest items being that **remote directories are never removed**, that
**orphans are permanently unreachable**, that a **core version upgrade (`upgrade.php`) is not
handled at all**, and that the manifest is **per-machine, git-ignored state**.

## Detailed Findings

### 1. "Is the remote blank?" — asked twice, from two different places

#### 1a. The upload half: a local manifest, never the server

`upload.run()` builds `current` (path → sha256 of local bytes) and compares it against
`manifest.load(state_path)` (`upload.py:53-70`). `manifest.load()` returns `{}` for a missing
file, unreadable JSON, or a `version` other than `MANIFEST_VERSION` (`manifest.py:42-55`) — and
`{}` as `previous` makes `diff()` classify every path as `new` (`manifest.py:101-109`).

So "blank remote" is, to the uploader, exactly "no usable manifest for this target". The
module docstring states the design rule outright:

```python
# upload.py:3-6
Every decision here is made against the local file set and the local manifest; nothing is
ever read back from the server to decide what to send.
```

The manifest path is per-target — host plus slugified remote root — so two web spaces never
share state (`manifest.py:31-39`). Its shape is two top-level keys, `version` (integer, compared
against `MANIFEST_VERSION = 1`) and `entries` (flat map of remote path → 64-hex sha256). No
timestamps, no sizes. The one manifest in this checkout,
`.state/bilder.foerderverein-sefferweich.de_root.json`, holds **3309 entries** in 375,860 bytes;
its keys are repo-relative (`CLAUDE.md`, `COPYING.txt`) because that target's `remote_root` is
empty — with a root like `/piwigo` the keys carry the full remote path.

The CLI reports which of the two cases it is in, but only as a one-word annotation next to the
path:

```python
# cli.py:136-138
state_path = manifest.manifest_path(state_dir, config.ftp.host, config.ftp.remote_root)
known = "existing" if state_path.exists() else "new"
_line(out, "manifest", f"{state_path} ({known})")
```

Note that this is `Path.exists()`, not the outcome of `manifest.load()` — a present-but-rejected
manifest (future version, corrupt JSON) prints `existing` while behaving as `new`.

#### 1b. The bootstrap half: the remote itself

```python
# bootstrap.py:32, 92-93
INSTALLED_MARKER = "Piwigo is already installed"

def is_installed(client, base_url: str) -> bool:
    return INSTALLED_MARKER in client.get(site_url(base_url, INSTALL_PATH)).body
```

`bootstrap.run()` installs only when this returns False (`bootstrap.py:335-337`), and records
which happened in `BootstrapResult.installed`, documented as "True when *this* run installed"
(`bootstrap.py:81-83`). The CLI prints `installed` or `already installed - skipped`
(`cli.py:183`).

`install()` does not trust the POST's HTTP status: `install.php` answers 200 whether it installed
or re-rendered its form with errors, so the proof is a **follow-up** `is_installed()` call, and
the failure path scrapes `div.errors` out of the body (`bootstrap.py:124-148`).

#### 1c. Where the two oracles diverge

The README states the consequence as an operational rule rather than a code guard:

> **Wiping the remote means deleting that target's manifest.** If the web space is emptied by
> any means other than this tool's own prune, the manifest becomes a lie: the next run reports
> `0 new, 0 changed`, uploads nothing, and leaves the site broken. (README.md:117-119)

The plan records this being hit for real, 2026-08-31 (plan:1124-1145). Nothing in the code
cross-checks the two answers — a run can print `0 new, 0 changed` and `install installed` in the
same report, which is precisely the wiped-remote-with-stale-manifest state.

### 2. Change detection

- sha256 over file bytes, streamed in 1 MiB chunks (`manifest.py:22, 72-78`). Chosen over git's
  blob SHA-1 so one code path covers tracked files, submodule files and the generated
  `config.inc.php` (`manifest.py:7-8`).
- The comparison is a four-way decision table (`manifest.py:94-115`):

  | in current | in previous | hashes | bucket |
  |---|---|---|---|
  | yes | no | — | `new` |
  | yes | yes | differ | `changed` |
  | yes | yes | equal | `unchanged` |
  | no | yes | — | `removed` |

- `Diff.pending` is `sorted(new + changed)` — deterministic order (`manifest.py:88-91`).
- No timestamp, size or `MDTM` comparison exists anywhere; content hash is the only signal.

### 3. The prune, exactly as implemented

```python
# upload.py:100-105
if prune:
    for remote in difference.removed:
        transport.delete(remote)
        entries.pop(remote, None)
        manifest.save(state_path, entries)
        deleted.append(remote)
```

Three properties follow from `removed = previous - current` (`manifest.py:109`):

1. **Only previously-uploaded paths are deletion candidates.** Anything the server holds that
   this tool never uploaded is unreachable. That is what makes `upload/` and `_data/` safe
   (README.md:121-125).
2. **The manifest is written after every single deletion**, not once at the end, so an
   interrupted prune leaves a manifest that matches reality (plan:825-826).
3. **`--no-prune` skips the loop entirely** and deliberately leaves the entries in place
   (`cli.py:150`, tests `test_no_prune_flag_deletes_nothing`,
   `test_no_prune_keeps_the_path_in_the_manifest` — dropping them would make the path unprunable
   on every later run, i.e. an orphan by §7.2).

`--dry-run` returns before connecting (`upload.py:72-79`) and therefore deletes nothing, so the
CLI *predicts* the deletion count from `diff.removed` rather than printing a truthful zero:

```python
# cli.py:157-159
would_prune = 0 if args.no_prune else len(result.diff.removed)
removed = would_prune if args.dry_run else len(result.deleted)
```

### 4. What is and is not protected from the prune

| Remote area | In the manifest? | Prune can reach it? | Why |
|---|---|---|---|
| `upload/` | never | no | created empty (`fileset.py:85`), server-authoritative |
| `_data/` | never | no | same |
| anything uploaded by hand / another tool | never | no | `removed` is `previous - current` |
| `local/config/database.inc.php` | never | no | written by `install.php` on the server |
| `local/config/config.inc.php` | **yes**, but hidden from the diff | no | `fileset.GENERATED_REMOTE_PATHS` |
| **`galleries/<album>/*.png`** | **yes** | **yes** | 106 files are tracked and published |
| core PHP, themes, plugins, language | yes | yes | the intended case |

The generated config is a special case worth naming, because it was a real defect
(plan:1080-1092). It is uploaded by the bootstrap and recorded in the manifest, but is never in
the git file set — so it looked `removed` on every run after the first. The fix hides it from
**all four buckets**, not merely from the deletion loop:

```python
# upload.py:62-69
generated = {remote_path(root, name) for name in fileset.GENERATED_REMOTE_PATHS}
comparable = {path: digest for path, digest in entries.items() if path not in generated}
difference = manifest.diff(current, comparable)
```

The `galleries/` row is the one that bears on the question's parenthesis, "*piwigo files, not
images*". The `.gitignore` blanket-ignores `/galleries/*` and then re-includes four album
directories by name (`.gitignore:14-21`), because those recovered scans exist nowhere else. Those
106 files are enumerated by `git ls-files`, pass `select()`, are hashed, uploaded and recorded —
and would be deleted from the remote by a prune if they were removed from the working copy.
Photos added *through the gallery* land in `upload/` and are safe; photos that came from the repo
are not.

### 5. The bootstrap's five steps, and how each is idempotent

`bootstrap.run()` (`bootstrap.py:332-350`) runs install → config → login → plugins → sync. The
docstring states the invariant: "each of which asks the server what state it is in before
changing it, so running the whole thing twice is safe by construction" (`bootstrap.py:3-5`).

| Step | The question it asks first | Repeat-run behaviour |
|---|---|---|
| install | `is_installed()` (remote `install.php`) | skipped |
| config | manifest hash of `local/config/config.inc.php` (`bootstrap.py:191-192`) | re-uploaded only when the credential JSON changed |
| login | none — always runs | new session each run |
| plugins | `pwg.plugins.getList` state per plugin (`bootstrap.py:270-289`) | `active` plugins left alone |
| sync | none — always runs | rescans the whole `galleries/` tree |

Two failure paths are explicit rather than silent:
- a plugin the remote does not list at all raises, because `getList` reports the **filesystem**,
  so an absent name means a partial deploy (`bootstrap.py:273-278`).
- `sync()` refuses a response with fewer than two `update_summary_new` lines rather than reading
  a login page as zeroes (`bootstrap.py:313-326`, `bootstrap.py:295-310`).

### 6. What the transport port can and cannot do

`Transport` is exactly six operations: `connect`, `close`, `makedirs`, `put`, `delete`, `chmod`,
`exists` (`transport.py:25-42`). Consequences that bear directly on the question:

- **There is no listing operation.** No `NLST`, no `MLSD`. Deriving remote state from the server
  is not something the current port can express.
- **There is no directory removal.** `delete()` is `FTP.delete` (`transport.py:124-125`) — `DELE`,
  files only. No `RMD` anywhere in the tool.
- **`exists()` asks `SIZE`** (`transport.py:134-139`), and a server refuses `SIZE` for a
  directory. It reported a directory that was plainly present as "already gone" during the
  2026-08-31 session (plan:1147-1150, rules/deployment.md).
- `makedirs()` caches every segment it has already issued an `MKD` for, per session
  (`transport.py:64-65, 102-116`), and treats 550 as "already exists".
- `chmod()` returns `False` rather than raising, because `SITE CHMOD` is optional
  (`transport.py:127-132`); `upload.run()` applies it to all five `WRITABLE_REMOTE_PATHS` on
  **every** run and downgrades a refusal to a warning (`upload.py:107-112`).

### 7. What comes with this shape of change that the question did not list

Each item below is a property of the code as it stands, with its evidence.

1. **Empty directories accumulate on the remote forever.** Prune deletes files; nothing ever
   issues `RMD`. Removing `plugins/foo/` locally deletes its files and leaves its directory tree.
2. **An orphan is permanent.** A path present on the server but absent from the manifest can
   never be reached again by any run — `removed` is computed from the manifest alone. This
   happened during the Ctrl-C experiment when manifest entries were dropped by hand; the files
   had to be deleted over FTP manually (plan:1139-1145).
3. **A `MANIFEST_VERSION` bump silently means a full re-upload.** `load()` returns `{}` for a
   version mismatch (`manifest.py:52-53`) — deliberate, "correct-but-slow instead of
   fast-but-wrong" — but it also means `removed` is empty that run, so nothing is pruned and the
   remote keeps whatever the previous format had put there.
4. **The manifest is per-machine and git-ignored** (`.gitignore`, `/tools/deploy/.state/`).
   Deploying the same target from a second checkout or a second machine is indistinguishable from
   a first run: 128 MB re-uploaded, and no prune possible because there is no previous state.
5. **Core version upgrades are not handled.** `upgrade.php` and `upgrade_feed.php` exist in this
   checkout, `install/db/` holds 122 migration files, and `upgrade.php:348-385` decides what to
   apply from the `piwigo_upgrade` table. The tool never touches any of it — the plan lists it
   under *What We're NOT Doing* (plan:127-129) on the grounds that a fresh install marks every
   migration applied (`install.php:413-431`). An already-running instance whose core files are
   updated to a newer `PHPWG_VERSION` therefore gets new PHP against an un-migrated schema.
6. **Plugin schema updates ride on the `Version:` header, and can miss silently.** `activate`
   falls through to `install` only when there is no DB row; an existing row goes through
   `autoupdate_plugin()`. A schema change shipped without a version bump never reaches the remote
   tables and the deploy reports the plugin `active` either way. `persons` has no
   `ALTER … MODIFY` path, so a changed column definition does not propagate even with a bump.
   Recorded as accepted and un-gated in `docs/backlog.md:3-9`.
7. **`site_update` deletes database rows for vanished files, and the tool does not report it.**
   `admin/themes/default/template/site_update.tpl:21-22` renders `update_summary_del` counts for
   albums and photos deleted from the database. `parse_sync_counts()` matches that CSS class in
   its regex (`bootstrap.py:61-63`) but only reads `update_summary_new` and `update_summary_err`
   (`bootstrap.py:317-321`). So on an update run the remote database *does* lose rows for photos
   that are gone, and the run's summary line never mentions it.
8. **The generated config is overwritten on the remote whenever the credential JSON changes**,
   and the file itself says so: "do not edit on the server, it is overwritten on deploy"
   (`bootstrap.py:162-163`). A hand-edit on the server survives only until the JSON changes.
9. **`chmod 0777` is re-applied to five paths every run** (`upload.py:107-112`,
   `fileset.py:87`), including on an update run against a long-lived instance.
10. **There is no remote lock and no in-progress marker.** Two concurrent runs against one target
    would interleave `STOR`s and both write the same manifest; nothing detects it. The plan
    records "there is no concurrency, so no interleaving cases" as a deliberate test-design
    omission (plan:1365-1367).
11. **A partial file set is a hard failure, by design.** `verified_tracked_paths()` enforces
    `MIN_EXPECTED_PATHS = 3000` and a per-submodule contribution check (`fileset.py:37, 131-160`),
    because an uninitialised submodule is dropped from `--recurse-submodules` *silently* and would
    otherwise publish a gallery whose Colored Tags plugin has no code.
12. **The admin password is baked in at install time from the JSON, and validation accepts the
    placeholder.** `_required_string()` accepts `REPLACE_ME` unchanged; confirmed 2026-09-01 that
    a deploy ran clean with it and that literal became the working login
    (rules/deployment.md, `docs/backlog.md:15-16`).
13. **No database is transferred in either direction**
    ([decision 0023](../decisions/0023-no-database-transfer-to-the-remote.md)). Albums and photos
    are re-created by `site_update`, person regions by `pwg.persons.rescan` out of the image
    files. Provenance columns live only in the database and have **no path to the remote at all**.
14. **The whole tool is declared unsafe against production**
    ([decision 0021](../decisions/0021-remote-instance-is-a-sandbox.md)) — it prunes without a
    prompt, overwrites the remote config, and posts to `install.php` unauthenticated.

### 8. Which of this is covered by tests

`tools/deploy/tests/test_upload.py` (29 tests) carries the behaviours this question is about:

- first run / second run: `test_first_run_uploads_every_file`, `test_second_run_uploads_nothing`
- incremental: `test_changed_file_is_re_uploaded_alone`, `test_a_new_file_is_uploaded_alone`
- per-target isolation: `test_a_manifest_of_another_target_is_not_reused`
- prune scope: `test_prune_deletes_only_previously_recorded_paths`, `test_a_pruned_path_leaves_the_manifest`
- prune suppression: `test_no_prune_flag_deletes_nothing`, `test_no_prune_keeps_the_path_in_the_manifest`
- the generated config: `test_the_generated_config_is_never_pruned`,
  `test_the_generated_config_is_absent_from_every_diff_bucket`
- resumability: `test_manifest_persists_after_each_file`,
  `test_resume_after_failure_uploads_only_the_remainder`,
  `test_ctrl_c_mid_run_resumes_rather_than_restarts`, `test_ctrl_c_still_closes_the_session`
- dry run: `test_dry_run_never_touches_the_transport`, `test_dry_run_writes_no_manifest`

`test_manifest.py` covers the diff decision table, the empty-previous boundary, and
`test_a_deleted_manifest_makes_every_file_new`. Suite total: 311 tests, measured 2026-09-01
(README.md:133).

What has **no** test double, stated in `.claude/rules/deployment.md`: FTPS and the remote HTTP
endpoint. Every decision is pushed into a pure function; the adapters are decision-free and
hand-checked in the dated ledger of [`docs/agents/TESTING.md`](../TESTING.md).

## Code References

- `tools/deploy/pwgdeploy/upload.py:38-122` — the transfer: hash, diff, connect, mkdir, put, prune, chmod
- `tools/deploy/pwgdeploy/upload.py:62-69` — generated paths hidden from all four diff buckets
- `tools/deploy/pwgdeploy/upload.py:100-105` — the prune loop
- `tools/deploy/pwgdeploy/manifest.py:42-55` — `load()`; missing / corrupt / version-mismatched → `{}`
- `tools/deploy/pwgdeploy/manifest.py:94-115` — `diff()`, and the `removed = previous - current` rule
- `tools/deploy/pwgdeploy/manifest.py:31-39` — one manifest per host + remote root, slugified
- `tools/deploy/pwgdeploy/bootstrap.py:92-93` — `is_installed()`, the remote-side oracle
- `tools/deploy/pwgdeploy/bootstrap.py:124-148` — install, confirmed by a follow-up marker check
- `tools/deploy/pwgdeploy/bootstrap.py:176-203` — the generated config, routed through the manifest
- `tools/deploy/pwgdeploy/bootstrap.py:260-289` — plugin activation, skipping what is already active
- `tools/deploy/pwgdeploy/bootstrap.py:313-326` — sync summary parsing (`_new` and `_err` only)
- `tools/deploy/pwgdeploy/bootstrap.py:332-350` — the five-step ordering
- `tools/deploy/pwgdeploy/fileset.py:41-87` — the exclusion list, generated paths, dirs, writable set
- `tools/deploy/pwgdeploy/fileset.py:131-160` — the completeness guard
- `tools/deploy/pwgdeploy/transport.py:25-42` — the six-operation port (no listing, no `RMD`)
- `tools/deploy/pwgdeploy/transport.py:134-139` — `exists()` via `SIZE`; wrong for directories
- `tools/deploy/pwgdeploy/cli.py:136-138` — the `new` / `existing` manifest annotation
- `tools/deploy/pwgdeploy/cli.py:157-166` — the dry-run deletion prediction
- `tools/deploy/README.md:111-125` — "the manifest is the only record of remote state"
- `.claude/rules/deployment.md` — the agent-facing rules, exclusions and traps
- `.gitignore:14-21` — the four tracked `galleries/` album directories
- `docs/backlog.md:1-16` — the accepted plugin-schema gap and the `REPLACE_ME` password
- `upgrade.php:348-385` — the core upgrade path the tool does not touch
- `admin/themes/default/template/site_update.tpl:19-24` — the summary the sync parses

## Architecture Documentation

Hexagonal, and deliberately base-heavy: the two things that can fail in the world — FTPS and a
remote HTTP endpoint — have no local test double, so every decision lives in a pure function and
the two adapters hold none.

```
                cli.py  (flags, one report, exit codes)
                  |
     +------------+------------+
     |                         |
 upload.py                bootstrap.py     <- orchestration, port-typed
     |                         |
config.py fileset.py manifest.py urls.py   <- pure, unit-tested
     |                         |
 Transport (port)         HttpClient (port)
 FtplibTransport          UrllibClient
```

The load-bearing architectural choice for this question is stated in two docstrings and one
README section: **the server is never read back to decide what to send.** Everything the tool
knows about the remote is the local manifest. That single choice produces both the safety
property the question asks for (`upload/` and `_data/` are unreachable by the prune) and every
one of the sharp edges in §7 (orphans, wiped-remote blindness, per-machine state).

## Open Questions — answered by the user, 2026-09-01

Seven were put to the user and all seven were answered. All seven are now implemented by
[2026-09-01-deploy-state-guards-audit-and-deletion-reporting.md](../plans/2026-09-01-deploy-state-guards-audit-and-deletion-reporting.md);
the **Recorded as** column names the decision file each answer became, so a later reader follows
the link instead of re-litigating the answer here.

| # | Question | Answer | Recorded as |
|---|---|---|---|
| 1 | Reconcile the two "is it installed" oracles (§1c)? | **Yes, as a hard guard.** In `cli.py`: a non-empty manifest together with `is_installed() == False` aborts the run, naming the manifest file to delete. This is the wiped-remote case that actually happened 2026-08-31. | [decision 0027](../decisions/0027-manifest-and-remote-must-agree-on-installation.md) |
| 2 | Is `galleries/` being prune-eligible (§4) intended? | **Yes — intended, and to be made explicit.** Record it as a decision, and have `--dry-run` report `galleries/` deletions as their own line rather than folding them into one count. Deleting a scan locally *should* propagate; a bare "3 removed" hides which 3. | [decision 0026](../decisions/0026-tracked-gallery-photos-are-prune-eligible.md) |
| 3 | A core-version upgrade path (§7.5)? | **Detect only.** Compare local `PHPWG_VERSION` against the remote's and refuse the run when they differ, naming `upgrade.php`. No unauthenticated POST to a migration endpoint. | [decision 0028](../decisions/0028-core-version-is-detected-never-migrated.md) |
| 4 | Remove empty remote directories (§7.1)? | **No.** Record the decision instead. `RMD` needs a listing operation `Transport` does not have, and directory removal is where an over-broad delete stops being recoverable. | [decision 0029](../decisions/0029-empty-remote-directories-are-never-removed.md) |
| 5 | Make orphans (§7.2) recoverable? | **Read-only audit.** A `--audit` flag that lists the remote tree and reports what the manifest does not cover. Needs one `list()` on the port; it reports, never deletes. | [decision 0030](../decisions/0030-the-audit-is-read-only-and-exists-stays-size-based.md) |
| 6 | Stop the manifest being per-machine (§7.4)? | **No — fail loudly instead.** When the manifest is absent and the remote answers `already installed`, say so before re-uploading everything. The same guard as #1, in the other direction. Committing hashes of a private target is worse. | [decision 0027](../decisions/0027-manifest-and-remote-must-agree-on-installation.md) |
| 7 | Report `site_update`'s deletions (§7.7)? | **Yes.** Add `albums_deleted` / `photos_deleted` to `SyncCounts` and to the summary line. `_SUMMARY_ITEM` already matches `update_summary_del`; the rows are genuinely being deleted unreported. | No decision — a reporting fix with no tradeoff to record |

Answers 1 and 6 are two halves of one guard: the manifest and the remote must agree on whether
the gallery is installed, in both directions.

### Resolved since

- The `Transport` port's listing operation is decided: `list_dir(remote_dir) -> list[RemoteEntry]`,
  one directory at a time, `MLSD` only, used by `--audit` and by nothing else. `exists()` keeps its
  `SIZE`-based directory blindness — nothing in the tool calls it, and a second way to ask one
  question is the copy that rots ([decision 0030](../decisions/0030-the-audit-is-read-only-and-exists-stays-size-based.md)).

### Still open

- `admin.php?page=plugins` has still never been opened in a browser; plugin state has only ever
  been witnessed through `pwg.plugins.getList` (plan:1174-1176). Carried in
  [`docs/backlog.md`](../../backlog.md).
