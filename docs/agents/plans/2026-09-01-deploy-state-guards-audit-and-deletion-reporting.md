---
date: 2026-09-01T10:02:43+00:00
git_commit: 51a8a89dab7bbf1a9f82a5145279f23e0d82dbd0
branch: master
topic: "Deploy: state guards, version detection, deletion reporting and a read-only audit"
tags: [plan, deployment, pwgdeploy, manifest, prune, audit, guards]
status: draft
---

# Deploy state guards, version detection, deletion reporting and a read-only audit

## Overview

The research note
[2026-09-01-ftp-deploy-blank-vs-existing-remote.md](../research/2026-09-01-ftp-deploy-blank-vs-existing-remote.md)
established that incremental upload and prune already work, and put seven questions to the
user. All seven were answered. This plan implements the six that need work and records the
seventh as a decision.

The through-line: **the tool currently trusts a local manifest as its only picture of the
remote, and never checks that picture against the server.** Four of the six changes close
that gap — two guards that abort when the two disagree, one report line that names what a
prune would delete out of `galleries/`, and one read-only audit that can finally see what
the manifest does not cover. The remaining two are reporting fixes: the remote's
`PHPWG_VERSION`, and the album/photo rows `site_update` deletes without saying so.

| Research answer | This plan |
|---|---|
| 1 + 6 — reconcile the two "is it installed" oracles, both directions | Phase 3 |
| 2 — `galleries/` prune-eligibility is intended, report it separately | Phase 2 |
| 3 — detect a core version difference, refuse, never migrate | Phase 4 |
| 4 — do not remove empty remote directories | Phase 5 (decision only) |
| 5 — read-only `--audit` that lists the remote and reports orphans | Phase 5 |
| 7 — report `site_update`'s deletions | Phase 1 |

## Current State Analysis

- `upload.run()` compares local hashes against `tools/deploy/.state/<host>_<root>.json` and
  never reads the server (`upload.py:3-6`, `upload.py:53-70`).
- `bootstrap.is_installed()` asks the remote `install.php` for the literal
  `Piwigo is already installed` (`bootstrap.py:92-93`) — but only inside the bootstrap half,
  which runs *after* the upload.
- Nothing compares the two. A run can print `0 new, 0 changed` and `install installed` in one
  report, which is the wiped-remote-with-stale-manifest state that actually happened
  2026-08-31.
- `SyncCounts` has three fields; `_SUMMARY_ITEM` already matches `update_summary_del` but
  `parse_sync_counts()` reads only `_new` and `_err` (`bootstrap.py:313-326`).
- `Transport` is a six-operation port with **no listing operation** (`transport.py:25-42`), so
  the remote tree is unreachable from the tool.
- 106 tracked files under `galleries/` are published, hashed, and therefore prune-eligible;
  the report folds them into one `N removed` count (`cli.py:157-166`).
- `include/constants.php:10` holds `define('PHPWG_VERSION', '17.0.0beta1');`. `pwg.getVersion`
  is registered in `ws.php:57-62` with **no** `admin_only` option, and returns `PHPWG_VERSION`
  verbatim (`include/ws_functions/pwg.php:125-128`).

### Key Discoveries

- **`pwg.getVersion` is reachable, but this plan calls it logged in anyway.** The fake
  gallery — and a real install with guest access disabled — refuses every ws method but
  `pwg.session.login` until a session exists (`tests/fakes.py:188-189`). One code path that
  always logs in first is simpler than an unauthenticated call with a login retry, and the
  bootstrap already owns `login()` (`bootstrap.py:236-249`).
- **The README is guarded structurally.** `tests/test_readme.py` asserts every flag the parser
  accepts is documented (`:105-109`), every reachable exit code is documented (`:145-149`),
  and that the dated test count in **both** `README.md` and `.claude/rules/deployment.md` equals
  what pytest collects, with a single shared date (`:337-358`). Every phase below that adds a
  flag, an error class or a test therefore updates those documents **in the same commit** or
  the suite goes red.
- **`-W error` is on** (`pyproject.toml`), and `pytest-randomly` shuffles order every run. No
  test may depend on another's state.
- **`FakeGallery` is stateful on purpose** (`tests/fakes.py:91-101`); idempotence claims are
  asked of it, not scripted. New behaviour goes into the fake as a real endpoint, not as a
  canned reply.
- **`FakeGallery._sync_page()` hardcodes `0` for both `update_summary_del` lines**
  (`tests/fakes.py:220-221`) — Phase 1 has to parameterise it or every deletion assertion is
  vacuous.
- `exists()` asks `SIZE` and is therefore blind to directories (`transport.py:134-139`). Nothing
  in the tool calls it; it is used only by `smoke.py` and its own tests.

## Desired End State

A run against a remote whose state disagrees with the local manifest stops before uploading
anything, and says which of the two to fix. A run against a remote at a different core version
stops and names `upgrade.php`. A prune that would delete a photo says so on its own line. The
sync summary reports deletions. `--audit` lists the remote tree and reports what the manifest
does not cover, deleting nothing.

### CLI mockups

Normal update run, nothing unusual — one new `preflight` line, one extended `sync` line:

```
Piwigo deploy -> bilder.example.de:/
  file set    3378 files, 128.4 MB (excluded: 155 dev/test files)
  manifest    /…/.state/bilder.example.de_root.json (existing)
  preflight   installed, 17.0.0beta1 — manifest and remote agree
  transport   FTPS to bilder.example.de:21
  upload      0 new, 2 changed, 3376 unchanged, 0 removed (sent)
  chmod       local _data upload plugins themes  ok
  install     already installed - skipped
  config      local/config/config.inc.php unchanged
  plugins     typetags active, provenance active, persons active
  sync        106 photos, 4 albums, 0 errors (deleted: 0 photos, 0 albums)
done in 41.2s
```

A prune that would remove a tracked scan — the new `galleries` line, on a dry run:

```
  manifest    /…/.state/bilder.example.de_root.json (existing)
  preflight   skipped (--dry-run opens no connection)
  upload      0 new, 0 changed, 3375 unchanged, 3 removed (would send)
  galleries   1 of the 3 would delete a tracked photo:
              galleries/2019-ostern/img_0421.png
```

The wiped-remote guard (research §1c, answer 1):

```
Piwigo deploy -> bilder.example.de:/
  file set    3378 files, 128.4 MB (excluded: 155 dev/test files)
  manifest    /…/.state/bilder.example.de_root.json (existing)
StateMismatchError: the manifest records 3309 uploaded files, but install.php says the
gallery is not installed. The remote was emptied by something other than this tool, so the
manifest is a lie and this run would upload nothing. Delete
/…/.state/bilder.example.de_root.json and re-run, or pass --adopt-remote-state to
upload against the remote as it is.
```

The other direction (answer 6) — a second machine, or a lost `.state/`:

```
StateMismatchError: install.php says the gallery is already installed, but there is no
manifest for this target. This run would re-upload all 3378 files, and anything already on
the server that this run does not send stays there permanently — no later run can reach it.
Pass --adopt-remote-state to proceed, or run --audit first to see what is there.
```

The version guard (answer 3):

```
VersionError: local PHPWG_VERSION is 17.1.0, the remote reports 17.0.0beta1. Uploading
would put newer core PHP on an un-migrated schema; this tool does not run upgrade.php.
Run upgrade.php on the remote yourself, or pass --allow-version-change.
```

`--audit`:

```
Piwigo deploy -> bilder.example.de:/
  manifest    /…/.state/bilder.example.de_root.json (existing, 3309 entries)
  transport   FTPS to bilder.example.de:21
  listed      3411 files in 402 directories (skipped: _data/ upload/)
  covered     3308 files the manifest records and the server holds
  orphans     103 files on the server the manifest does not cover:
              plugins/removed_plugin/main.inc.php
              …
  missing     1 file the manifest records and the server does not hold:
              themes/modus/theme.css
  This is a read-only report. Nothing was deleted.
```

## What We're NOT Doing

- **No `RMD`, no directory removal** — answer 4, recorded as decision 0029.
- **No migration.** `upgrade.php` is never posted to. Detect and refuse only — answer 3.
- **No deletion from `--audit`.** It lists and reports; an orphan is still removed by hand over
  FTP.
- **No change to `exists()`.** Its `SIZE`-based directory blindness stays, recorded in decision
  0030. Nothing in the tool calls it, and `--audit` gets its own listing operation.
- **No committed manifest, no manifest transfer between machines** — answer 6 was "fail loudly",
  not "share state". Hashes of a private target stay out of git.
- **No `--dry-run` HTTP.** The "connects to nothing" property is kept; the preflight is skipped
  and the report says so.
- **No NLST fallback in the listing adapter.** MLSD or a loud failure.
- **No new integration or E2E layer.** FTPS and the remote HTTP endpoint still have no test
  double; the adapters stay decision-free and the hand-checks go in the ledger.

## Implementation Approach

Every phase is a vertical slice: pure decision function first (unit-tested), then the port or
adapter it needs, then the CLI wiring, then the documents the structural guards read. Phases
are ordered smallest-blast-radius first, so the two that touch `cli.main`'s control flow
(3 and 4) land on a suite that is already green with the reporting changes.

Two rules apply to **every** phase and are not repeated in each one:

- The last step of each phase updates the dated test count in `tools/deploy/README.md` **and**
  `.claude/rules/deployment.md`, both to today's date, or
  `test_readme.py::test_the_documented_test_count_is_the_number_pytest_reports` and
  `::test_both_documents_date_the_same_measurement` go red.
- New tests follow the suite's conventions: full-sentence snake_case names, a docstring opening
  with its technique tag, exit codes read off `errors.py` rather than transcribed, and a
  module-level lower-bound constant for anything that scans or counts.

---

## Phase 1: Report what `site_update` deleted

### Overview

Answer 7. `_SUMMARY_ITEM` already matches `update_summary_del`; the two counts are parsed and
discarded. On an update run the remote database genuinely loses rows for photos that are gone,
and the summary never mentions it.

### Changes Required

#### [x] 1. `SyncCounts` gains two fields
**File**: `tools/deploy/pwgdeploy/bootstrap.py`

```python
MIN_SUMMARY_DELETED_LINES = 2   # site_update.tpl:21-22, always both

@dataclass(frozen=True)
class SyncCounts:
    albums_added: int
    photos_added: int
    albums_deleted: int
    photos_deleted: int
    errors: int
```

#### [x] 2. `parse_sync_counts()` reads the `_del` bucket
**File**: `tools/deploy/pwgdeploy/bootstrap.py:313-326`
**Changes**: collect a `deleted` list alongside `added`; require
`MIN_SUMMARY_DELETED_LINES` of it for the same reason `MIN_SUMMARY_ADDED_LINES` exists — a login
page must not read as zeroes. Order is `albums, photos` in both buckets
(`admin/themes/default/template/site_update.tpl:19-22`).

#### [x] 3. The report line names the deletions
**File**: `tools/deploy/pwgdeploy/cli.py:196-200`

```python
_line(out, "sync",
      f"{result.sync.photos_added} photos, {result.sync.albums_added} albums, "
      f"{result.sync.errors} errors (deleted: {result.sync.photos_deleted} photos, "
      f"{result.sync.albums_deleted} albums)")
```

#### [x] 4. `FakeGallery` can be told what was deleted
**File**: `tools/deploy/tests/fakes.py:108-131, 214-225`
**Changes**: `albums_deleted=0`, `photos_deleted=0` constructor keywords; `_sync_page()`
interpolates them instead of the two hardcoded `0`s. Without this every deletion assertion in
this phase is vacuous.

### Success Criteria

#### Automated Verification
- [x] `cd tools/deploy && uv run pytest` passes
- [x] `test_sync_reports_the_counts_the_summary_carries` updated for the five-field tuple
- [x] The new deletion tests fail when `_del` handling is reverted (watch each go red)

#### Manual Verification
- [x] Nothing — this phase is covered end to end by the fake.

**Implementation Note**: pause for confirmation before Phase 2.

---

## Phase 2: Name the tracked photos a prune would delete

### Overview

Answer 2. The 106 tracked `galleries/` files are prune-eligible by design; a bare `3 removed`
hides which three. Report them on their own line, on both a dry run and a real one, and record
the design as a decision rather than leaving it implicit in `.gitignore`.

### Changes Required

#### [x] 1. A pure classifier
**File**: `tools/deploy/pwgdeploy/fileset.py`

```python
# The four album directories .gitignore:14-21 re-includes. Those scans exist nowhere
# else, so a prune that reaches one is worth naming rather than counting.
GALLERY_PREFIX = "galleries/"

def gallery_paths(root: str, remote_paths: Iterable[str]) -> list[str]:
    """Which of these remote paths are tracked photos, in the order given."""
```

Uses `urls.remote_path(root, GALLERY_PREFIX)` as the prefix so a non-empty `remote_root` works.

#### [x] 2. The report line
**File**: `tools/deploy/pwgdeploy/cli.py:157-166`

```python
MAX_REPORTED_GALLERY_DELETIONS = 10
```

Emitted only when the list is non-empty, after the `upload` line, on both dry and real runs.
It reports `result.diff.removed` on a dry run (nothing was deleted) and `result.deleted` on a
real one, matching the existing prediction/truth split. Suppressed entirely under
`--no-prune`, which deletes nothing.

#### [x] 3. Decision 0026
**File**: `docs/agents/decisions/0026-tracked-gallery-photos-are-prune-eligible.md`
**Changes**: records that deleting a scan from the working copy *should* propagate to the
remote, that this is why `galleries/` is in the file set at all, and that the compensating
control is the named report line rather than an exclusion.

#### [x] 4. README
**File**: `tools/deploy/README.md`
**Changes**: the "what is protected from the prune" material gains the `galleries/` row and a
pointer to decision 0026. Check `test_readme.py::test_every_relative_link_in_the_readme_resolves`
stays green.

### Success Criteria

#### Automated Verification
- [x] `cd tools/deploy && uv run pytest` passes
- [x] `uv run pwg-deploy --dry-run deploy.local.json` prints no `galleries` line against the
      current working copy (nothing is removed)

#### Manual Verification
- [x] ~~Temporarily `git rm --cached` one `galleries/` file, run `--dry-run`, confirm the line
      names that exact path and that the count matches the `removed` total; restore.~~
      **Automated, so it leaves no ledger entry.** Run once by hand 2026-09-01 (it named
      `galleries/1992_Rund_um_Sefferweich/1992_Rund_um_Sefferweich_01_0.png` on a `1 removed`
      run), then replaced by two tests: `test_the_real_file_set_publishes_tracked_gallery_photos`
      covers the half the fake cannot — that this checkout's published set really does contain
      paths the classifier calls photos — and `test_a_dry_run_names_the_photo_it_would_delete`
      now prunes two strangers, one of them a photo, and reads the second figure off the
      `upload` line instead of transcribing it.

**Implementation Note**: pause for confirmation before Phase 3.

---

## Phase 3: The manifest and the remote must agree

### Overview

Answers 1 and 6, which are one guard in two directions. A new preflight step runs **before**
the upload, asks `install.php` the question the bootstrap already asks, and refuses when the
answer contradicts the manifest.

### Changes Required

#### [x] 1. A new error class
**File**: `tools/deploy/pwgdeploy/errors.py`

```python
class StateMismatchError(DeployError):
    """The local manifest and the remote disagree on whether the gallery is installed."""

    exit_code = 9
```

#### [x] 2. A pure decision function
**File**: `tools/deploy/pwgdeploy/preflight.py` (new)

```python
def check_state(
    *,
    entry_count: int,
    remote_installed: bool,
    manifest_path: Path,
    file_count: int,
    adopt: bool,
) -> str | None:
    """None when the two agree; a warning string when `adopt` overrides a disagreement.

    Raises StateMismatchError otherwise. Pure: every input is a value.
    """
```

Decision table:

| `entry_count` | `remote_installed` | verdict |
|---|---|---|
| `0` | `False` | agree — a first run |
| `> 0` | `True` | agree — an update run |
| `> 0` | `False` | raise: the manifest is a lie, name the file to delete |
| `0` | `True` | raise: no local state for an installed remote, orphans would be permanent |

#### [x] 3. The impure probe
**File**: `tools/deploy/pwgdeploy/preflight.py`

```python
@dataclass(frozen=True)
class RemoteState:
    installed: bool
    version: str | None   # None when not installed — Phase 4 fills this in

def probe(client, config) -> RemoteState:
    """One GET of install.php. Port-typed; opens no socket of its own."""
```

Phase 3 leaves `version` as `None` unconditionally; Phase 4 gives it a value. Splitting it that
way keeps each phase's tests honest about what it proved.

#### [x] 4. The flag and the wiring
**File**: `tools/deploy/pwgdeploy/cli.py`
**Changes**: `--adopt-remote-state` on the parser; a `_preflight()` helper called from `main()`
after `_report_target` and **before** `_upload`, skipped when `args.dry_run` or
`args.list_files`. It prints one `preflight` line in all cases — the verdict, the skip reason,
or the adopted-anyway warning. `state_path` moves up into `main()` so both `_preflight` and
`_upload` read the same one.

#### [x] 5. README
**File**: `tools/deploy/README.md`
**Changes**: the new flag in the flag table, exit code `9` in the exit-code section, and the
operational rule under "the manifest is the only record of remote state" rewritten from "the
next run reports 0 new, 0 changed and leaves the site broken" to "the next run refuses". Both
`test_every_flag_the_tool_accepts_is_documented` and
`test_every_exit_code_the_tool_can_return_is_documented` enforce the first two.

#### [x] 6. Decision 0027
**File**: `docs/agents/decisions/0027-manifest-and-remote-must-agree-on-installation.md`
**Changes**: why the guard aborts rather than self-heals in either direction — self-healing the
wiped-remote case means silently re-uploading 128 MB, and self-healing the missing-manifest case
means adopting a server whose contents nobody has seen. Records `--adopt-remote-state` as the
deliberate, named escape hatch.

#### [x] 7. Rules file
**File**: `.claude/rules/deployment.md`
**Changes**: the "wiping the remote means deleting that target's manifest" paragraph now
describes a guard, not a trap.

### Success Criteria

#### Automated Verification
- [x] `cd tools/deploy && uv run pytest` passes
- [x] All four cells of the decision table are covered by their own test
- [x] `test_dry_run_connects_to_nothing` still passes unchanged — the preflight must not break it

#### Manual Verification
- [x] Real run against `bilder.foerderverein-sefferweich.de` with the existing manifest: prints
      `preflight installed, … — manifest and remote agree` and proceeds. Run 2026-09-01:
      `preflight   installed — manifest and remote agree`, then 27 new / 1 changed sent,
      install skipped, all three plugins active. No `galleries` line — nothing irreplaceable
      was pruned.
- [x] Move `.state/<host>_root.json` aside, run again: aborts with exit 9 and the
      no-manifest message; restore the file. Run 2026-09-01: `EXIT=9`, the message named the
      3335 files it would have re-uploaded and both `--adopt-remote-state` and `--audit`, and
      the report stopped after the `manifest … (new)` line — no `transport` line, so no FTPS
      connection was opened. Manifest restored.

**Implementation Note**: pause for confirmation before Phase 4.

---

## Phase 4: Detect a core version difference and refuse

### Overview

Answer 3. Detect only. The tool never posts to `upgrade.php`; it stops and names it.

### Changes Required

#### [x] 1. A new error class
**File**: `tools/deploy/pwgdeploy/errors.py`

```python
class VersionError(DeployError):
    """The checkout's PHPWG_VERSION cannot be read, or differs from the remote's."""

    exit_code = 10
```

Both failure modes share one code deliberately: they are one question — "which core is this?" —
and a caller branching on the answer takes the same action either way.

#### [x] 2. Read the local version
**File**: `tools/deploy/pwgdeploy/version.py` (new)

```python
VERSION_FILE = "include/constants.php"
_DEFINE = re.compile(r"define\(\s*'PHPWG_VERSION'\s*,\s*'([^']*)'\s*\)")

def parse_version(text: str) -> str:
    """The PHPWG_VERSION literal. Pure. Raises VersionError when absent or empty."""

def local_version(repo_root: Path) -> str:
    """parse_version of include/constants.php."""
```

#### [x] 3. Read the remote version
**File**: `tools/deploy/pwgdeploy/preflight.py`
**Changes**: `probe()` gains, when `installed` is true, `bootstrap.login(client, config)`
followed by `bootstrap.ws_call(client, base_url, "pwg.getVersion")`. `pwg.getVersion` is public
(`ws.php:57-62` passes no `admin_only`), but the session is taken anyway so one code path covers
an install with guest access disabled. A non-string result raises `RemoteHttpError`.

#### [x] 4. A pure comparison
**File**: `tools/deploy/pwgdeploy/preflight.py`

```python
def check_version(local: str, remote: str | None, *, allow_change: bool) -> str | None:
    """None when they match or there is nothing to compare (remote not installed)."""
```

Exact string equality, not a semver parse: `17.0.0beta1` is not a semver, and "which of these
two is newer" is a question this tool must not answer — any difference is a refusal.

#### [x] 5. The flag and the wiring
**File**: `tools/deploy/pwgdeploy/cli.py`
**Changes**: `--allow-version-change`; `_preflight()` runs `check_state` first, then
`check_version`. The `preflight` line reports both versions when the remote is installed.

#### [x] 6. `FakeGallery` answers `pwg.getVersion`
**File**: `tools/deploy/tests/fakes.py`
**Changes**: a `version="17.0.0beta1"` constructor keyword and a `pwg.getVersion` branch in
`_ws()`, placed **after** the `logged_in` gate so the fake keeps mirroring a real install with
guest access off.

#### [x] 7. README, decision 0028, rules file
**Files**: `tools/deploy/README.md`,
`docs/agents/decisions/0028-core-version-is-detected-never-migrated.md`,
`.claude/rules/deployment.md`
**Changes**: flag and exit code `10` documented; the decision records why an unauthenticated POST
to a migration endpoint is not acceptable even against a sandbox, and that the operator runs
`upgrade.php` themselves. The rules file's "no database is transferred" section gains the
version-guard note.

### Success Criteria

#### Automated Verification
- [x] `cd tools/deploy && uv run pytest` passes
- [x] A test asserts `version.local_version(REPO_ROOT)` equals the literal in
      `include/constants.php` — read from the file, never transcribed

#### Manual Verification
- [x] Real run against the remote: the `preflight` line reports `17.0.0beta1` on both sides.
      Run 2026-09-01: `preflight   installed, 17.0.0beta1 — manifest and remote agree`, then
      `0 new, 0 changed, 3335 unchanged`, install skipped, all three plugins active. The
      refusal path was forced in the same session by setting the working copy's
      `PHPWG_VERSION` to `17.1.0`: `EXIT=10`, the message named both versions, `upgrade.php`
      and `--allow-version-change`, and the report stopped after the `manifest` line — no
      `transport` line, so no connection was opened. `constants.php` restored, diff clean.
- [x] The remote host runs exiftool 12.76 against local 13.25 and no suite has run against
      12.76 — unchanged by this phase, but confirm the version line does not imply otherwise.
      Confirmed: the line reads `installed, 17.0.0beta1` and names `PHPWG_VERSION` only. Both
      entries are in the `docs/agents/TESTING.md` ledger, dated.

**Implementation Note**: pause for confirmation before Phase 5.

---

## Phase 5: A read-only `--audit`

### Overview

Answer 5, plus the two decisions that bound it (answer 4, and leaving `exists()` alone). The
`Transport` port gains its seventh operation — a listing — and a new pure module walks it and
compares against the manifest. It reports; it never deletes.

### Changes Required

#### [x] 1. The port grows a listing
**File**: `tools/deploy/pwgdeploy/transport.py`

```python
@dataclass(frozen=True)
class RemoteEntry:
    name: str      # the bare name, not a path
    is_dir: bool

class Transport(Protocol):
    ...
    def list_dir(self, remote_dir: str) -> list[RemoteEntry]:
        """One directory's entries. Used only by --audit; a deploy never lists."""
```

#### [x] 2. The adapter, MLSD only
**File**: `tools/deploy/pwgdeploy/transport.py`

```python
MLSD_FACTS = ("type",)
MLSD_SKIP_TYPES = ("cdir", "pdir")
```

`ftplib.FTP.mlsd()` with `facts=MLSD_FACTS`; `type=dir` → `is_dir`. An `error_perm` becomes a
`TransportError` naming MLSD and stating that the deploy itself is unaffected — only `--audit`
needs a listing. No NLST fallback: NLST cannot distinguish a file from a directory, and probing
each of 3400 names with a `CWD` is both slow and a second thing to keep correct.

#### [x] 3. The pure walk and compare
**File**: `tools/deploy/pwgdeploy/audit.py` (new)

```python
# Server-authoritative by construction (fileset.REMOTE_DIRS_TO_CREATE) plus the file
# install.php writes. Listing them would report thousands of orphans that are not orphans.
AUDIT_SKIP = fileset.REMOTE_DIRS_TO_CREATE
# A symlink loop on the server would otherwise walk forever.
MAX_DEPTH = 20

@dataclass(frozen=True)
class AuditReport:
    covered: list[str]
    orphans: list[str]     # on the server, absent from the manifest
    missing: list[str]     # in the manifest, absent from the server
    directories: int
    skipped: list[str]

def walk(list_dir, root: str, *, skip=AUDIT_SKIP, max_depth=MAX_DEPTH) -> tuple[list[str], int]:
    """Every file path under root, and how many directories were listed. `list_dir` is
    the injected port method, so this is pure given a callable."""

def compare(remote_files, entries, generated) -> AuditReport:
    """Three buckets. `generated` (local/config/config.inc.php) counts as covered."""
```

`local/config/database.inc.php` is written by `install.php` on the server and appears as an
orphan; that is correct and the report says so in its own line rather than special-casing it.

#### [x] 4. `FakeTransport` grows `list_dir`
**File**: `tools/deploy/tests/fakes.py`
**Changes**: derives entries from `self.files` keys, synthesising directories from path
segments, and records `("list_dir", remote_dir)` in `calls` like every other operation. That is
what lets an audit test assert **no** `delete` call was ever made.

#### [x] 5. The flag and the report
**File**: `tools/deploy/pwgdeploy/cli.py`
**Changes**: `--audit` is a standalone mode like `--list-files`: it connects, lists, reports,
and returns `0`. It runs no preflight, no upload and no bootstrap. `MAX_REPORTED_ORPHANS = 20`,
with an `… and N more` tail. The report ends with the literal sentence
`This is a read-only report. Nothing was deleted.`

#### [x] 6. Decisions 0029 and 0030
**Files**:
`docs/agents/decisions/0029-empty-remote-directories-are-never-removed.md`,
`docs/agents/decisions/0030-the-audit-is-read-only-and-exists-stays-size-based.md`
**Changes**: 0029 records answer 4 — directory removal is where an over-broad delete stops being
recoverable, and the accepted cost is that removing `plugins/foo/` locally leaves its empty tree
on the server forever. 0030 records that `--audit` reports and never deletes (an orphan is
removed by hand over FTP), and that `exists()` keeps its `SIZE`-based directory blindness even
though `list_dir` could now answer correctly — nothing in the tool calls `exists()`, and a
second way to ask one question is the copy that rots.

#### [x] 7. README and rules file
**Files**: `tools/deploy/README.md`, `.claude/rules/deployment.md`
**Changes**: `--audit` in the flag table with a worked example; the rules file's "prune only ever
considers what the previous manifest recorded … a path dropped from the manifest is an orphan no
run can reach" paragraph now points at `--audit` as the way to find them.

### Success Criteria

#### Automated Verification
- [x] `cd tools/deploy && uv run pytest` passes
- [x] An audit test asserts `"delete" not in transport.names()` — the read-only claim, made
      mechanical
- [x] A `MIN_AUDIT_FILES` lower bound guards every audit count assertion

#### Manual Verification
- [x] `uv run pwg-deploy --audit deploy.local.json` against the real host. Record the counts and
      whether MLSD is supported in `docs/agents/TESTING.md`, dated. This is the only way to learn
      whether the host answers MLSD at all. Run 2026-09-01: **MLSD supported**, `3337 files in
      394 directories (skipped: _data/ upload/)` in 75.8 s, `covered 3336` (the whole manifest),
      one orphan, no missing files. Ledger row added.
- [x] If orphans are reported, spot-check two of them over FTP by hand before believing the
      report. **Only one orphan exists** — `local/config/database.inc.php`, written by
      `install.php` and unreachable by any run, exactly as the README predicts. Checked over
      HTTPS rather than FTP, so the oracle is not the tool's own listing: it answers 200, the
      *covered* `config.inc.php` answers 200 (so the generated-config exception holds on the
      real host), and a path the audit did not list answers 404 — the control that makes a 200
      mean something. Found and fixed one cosmetic defect: the report said `1 files`.

**Implementation Note**: pause for confirmation before Phase 6.

---

## Phase 6: Close the documentation loop

### Overview

The structural guards force README and rules updates into each phase, but three things can only
be settled once, at the end.

### Changes Required

#### [ ] 1. The dated test count, final
**Files**: `tools/deploy/README.md`, `.claude/rules/deployment.md`
**Changes**: both to the final `uv run pytest` collection count, both dated the same day.

#### [ ] 2. The hand-check ledger
**File**: `docs/agents/TESTING.md`
**Changes**: dated entries for what has no automated oracle in this plan — the MLSD support of
the real host, the two real-run guard checks of Phase 3, and the version line of Phase 4. State
for each why it cannot be automated (no FTP server, no second web space).

#### [ ] 3. Research note status
**File**: `docs/agents/research/2026-09-01-ftp-deploy-blank-vs-existing-remote.md`
**Changes**: its "Open Questions — answered by the user" table gains the decision file each
answer became, so the next reader follows a link instead of re-litigating. Its two "Still open"
bullets are resolved: the `Transport` port's `list()` shape is decided (Phase 5), and `exists()`
is deliberately left alone (decision 0030). `admin.php?page=plugins` in a browser stays open and
moves to `docs/backlog.md`.

### Success Criteria

#### Automated Verification
- [ ] `cd tools/deploy && uv run pytest` passes, including both `test_readme.py` count guards
- [ ] `test_the_rules_file_is_reachable_from_claude_md` passes

#### Manual Verification
- [ ] Read `tools/deploy/README.md` end to end as a first-time operator: the five new flags and
      two new exit codes read as instructions someone could follow. This half has no oracle
      (`test_readme.py` module docstring says so) and belongs in the ledger.

---

## Testing Strategy

Unit is the only layer available: FTPS and the remote HTTP endpoint have no test double, and
this plan adds none. Everything below runs in `tools/deploy/tests/`, against `FakeTransport`
and `FakeGallery`. The pyramid's upper two levels are covered by the hand-check ledger, which
is a recorded gap, not a silent one.

### Test Design Techniques Applied

- `[ECP]` — the state guard's four cells; the three audit buckets; installed vs. not.
- `[BVA]` — `entry_count` at `0` and at `1`; a summary with exactly `MIN_SUMMARY_DELETED_LINES`
  and with one fewer; `MAX_DEPTH` at the limit and one past it; an empty remote listing.
- `[DT]` — `check_state`'s two-condition table, all four rows, each with and without `adopt`.
- `[ST]` — a first run then a second against one `FakeGallery`: the state guard's verdict must
  flip from "first run" to "update run" without either being an abort.
- `[NEG]` — every abort, asserted by exit code read off the class.
- `[ERR]` — `parse_version` against a `constants.php` with the define commented out, doubled, or
  written with double quotes.
- Decision-table testing is **not applicable** to `check_version`: one condition (equal or not),
  two outcomes, already covered by `[ECP]`.

### Unit Tests

#### Phase 1 — sync deletions (`tests/test_bootstrap.py`, `tests/test_cli.py`)

- [x] `test_sync_reports_the_albums_and_photos_the_summary_says_were_deleted` — a fake seeded
      with non-zero deletions returns them `[HAPPY]`
- [x] `test_a_sync_that_deleted_nothing_reports_zero_deletions` — the boundary that must not read
      as "field missing" `[BVA]`
- [x] `test_parse_sync_counts_needs_both_deleted_lines` — a body with one `_del` line raises,
      mirroring the existing added-lines guard `[NEG]`
- [x] `test_the_added_and_deleted_counts_are_not_transposed` — a fake with four distinct values
      (albums added, photos added, albums deleted, photos deleted) proves the field order
      `[ERR]`; without it a swapped pair passes every other test
- [x] `test_the_report_names_what_the_sync_deleted` — the CLI line `[HAPPY]`
- [x] **Regression**: `test_sync_reports_the_counts_the_summary_carries`,
      `test_a_second_sync_reporting_zero_new_is_a_success`, `test_sync_errors_are_carried_through`,
      `test_a_sync_answered_by_the_login_page_fails_loudly`, `test_the_report_names_every_step`,
      `test_a_first_run_installs_activates_and_syncs` — all read `SyncCounts`; update the first
      for the widened tuple, confirm the rest pass untouched

#### Phase 2 — gallery deletions (`tests/test_fileset.py`, `tests/test_cli.py`)

- [x] `test_gallery_paths_finds_a_tracked_photo` `[HAPPY]`
- [x] `test_gallery_paths_ignores_a_core_file` `[NEG]`
- [x] `test_gallery_paths_respects_a_nested_remote_root` — `/piwigo/galleries/x.png` under root
      `/piwigo`, and `/other/galleries/x.png` under the same root must not match `[BVA]`
- [x] `test_gallery_paths_of_an_empty_list_is_empty` `[BVA]`
- [x] `test_a_path_merely_containing_galleries_is_not_a_photo` — `plugins/galleriesx/a.php`
      `[ERR]`
- [x] `test_a_dry_run_names_the_photo_it_would_delete` `[HAPPY]`
- [x] `test_a_real_prune_names_the_photo_it_deleted` `[HAPPY]`
- [x] `test_no_gallery_line_when_the_prune_touches_no_photo` — absence, so the line is signal
      `[NEG]`
- [x] `test_no_prune_prints_no_gallery_line` `[NEG]`
- [x] `test_more_gallery_deletions_than_the_cap_are_summarised` — `MAX_REPORTED_GALLERY_DELETIONS`
      + 1 `[BVA]`
- [x] **Regression**: `test_dry_run_reports_what_it_would_delete`,
      `test_dry_run_with_no_prune_reports_no_deletion`, `test_a_pruned_file_is_deleted_by_default`,
      `test_no_prune_keeps_a_file_the_manifest_no_longer_covers` — the `upload` line's wording
      must not change

#### Phase 3 — the state guard (`tests/test_preflight.py` new, `tests/test_cli.py`)

- [x] `test_an_empty_manifest_and_a_blank_remote_is_a_first_run` `[DT]` `[HAPPY]`
- [x] `test_a_recorded_manifest_and_an_installed_remote_is_an_update_run` `[DT]` `[HAPPY]`
- [x] `test_a_recorded_manifest_and_a_blank_remote_is_refused` `[DT]` `[NEG]`
- [x] `test_an_empty_manifest_and_an_installed_remote_is_refused` `[DT]` `[NEG]`
- [x] `test_one_recorded_entry_is_already_a_recorded_manifest` — `entry_count == 1` `[BVA]`
- [x] `test_the_wiped_remote_message_names_the_manifest_file_to_delete` — the message is the whole
      point of the guard; assert the path, not a substring of prose `[NEG]`
- [x] `test_the_missing_manifest_message_names_the_audit_flag` `[NEG]`
- [x] `test_adopt_turns_each_refusal_into_a_warning` — parametrised over both refusing rows `[DT]`
- [x] `test_adopt_says_nothing_when_the_two_already_agree` — a flag that is always noisy stops
      being read `[NEG]`
- [x] `test_probe_reports_a_blank_remote_as_not_installed` `[ECP]`
- [x] `test_probe_reports_an_installed_remote_from_the_marker` `[ECP]`
- [x] `test_a_wiped_remote_exits_with_the_state_mismatch_code` — `StateMismatchError.exit_code`
      read off the class `[NEG]`
- [x] `test_a_refused_preflight_uploads_nothing` — `transport.calls == []`, the same shape as
      `test_a_failed_upload_stops_before_the_bootstrap` `[NEG]`
- [x] `test_a_first_run_then_a_second_both_pass_the_guard` — one `FakeGallery`, two `cli.main`
      calls `[ST]`
- [x] `test_the_preflight_line_says_the_two_agree` `[HAPPY]`
- [x] **Regression, and the one that matters most**: `test_dry_run_connects_to_nothing` — the
      preflight must not open a socket on a dry run. Also `test_list_files_prints_the_published_set_and_stops`,
      `test_no_bootstrap_uploads_but_leaves_the_gallery_alone` (the preflight still runs),
      `test_a_full_run_uploads_then_bootstraps_and_exits_zero`, `test_the_report_names_every_step`
- [x] `test_a_dry_run_says_the_preflight_was_skipped` — a silently skipped guard is one an
      operator believes ran `[NEG]`

#### Phase 4 — the version guard (`tests/test_version.py` new, `tests/test_preflight.py`)

- [x] `test_parse_version_reads_the_literal` `[HAPPY]`
- [x] `test_local_version_reads_this_checkout` (renamed) — reads
      `include/constants.php`, never transcribes `17.0.0beta1` `[HAPPY]`
- [x] `test_parse_version_without_the_define_raises` `[NEG]`
- [x] `test_parse_version_of_an_empty_literal_raises` — `define('PHPWG_VERSION', '')` `[BVA]`
- [x] `test_parse_version_ignores_a_double_quoted_define` — core uses single quotes; a
      double-quoted one is not the define this tool means `[ERR]`
- [x] `test_parse_version_takes_the_first_define_when_there_are_two` `[ERR]`
- [x] `test_parse_version_tolerates_the_whitespace_core_may_use` `[HAPPY]` and
      `test_local_version_of_a_tree_without_core_raises` `[NEG]` — added: an unreadable local
      version shares exit `10` and had no case
- [x] `test_matching_versions_pass` `[HAPPY]`
- [x] `test_differing_versions_are_refused` `[NEG]`
- [x] `test_a_blank_remote_has_no_version_to_compare` — `remote is None` `[BVA]`, paired with
      `test_a_blank_remote_has_no_version_to_read` on the probe side
- [x] `test_the_refusal_names_both_versions_and_upgrade_php` (renamed; it asserts both figures too) `[NEG]`
- [x] `test_allow_version_change_turns_the_refusal_into_a_warning` `[DT]`
- [x] `test_a_version_difference_exits_with_the_version_code` `[NEG]`
- [x] `test_a_version_difference_uploads_nothing` `[NEG]`
- [x] `test_the_probe_logs_in_before_asking_for_the_version` — assert `pwg.session.login`
      precedes `pwg.getVersion` in `gallery.methods_called()`; the fake refuses ws calls to an
      anonymous session, which is what makes this a real check `[ST]`
- [x] `test_a_non_string_version_result_fails_loudly` `[NEG]`
- [x] `test_the_preflight_line_reports_both_versions` `[HAPPY]`
- [x] `test_allow_version_change_uploads_over_the_difference` `[DT]` and
      `test_allow_version_change_says_nothing_when_the_versions_match` `[NEG]`
- [x] **Superseded**: `test_probe_leaves_the_version_unread` (Phase 3's `[ERR]` placeholder)
      deleted, successor `test_probe_reports_the_remote_version`
- [x] **Regression**: every Phase 3 preflight test — `check_state` runs first and its verdicts
      must not change; `test_login_returns_the_pwg_token` and
      `test_a_wrong_password_fails_with_the_server_s_message`, since `probe` now calls `login`

#### Phase 5 — the audit (`tests/test_audit.py` new, `tests/test_transport.py`, `tests/test_cli.py`)

- [x] `test_walk_finds_a_file_at_the_root` `[HAPPY]`
- [x] `test_walk_descends_into_a_subdirectory` `[HAPPY]`
- [x] `test_walk_of_an_empty_remote_finds_nothing` `[BVA]`
- [x] `test_walk_skips_the_server_authoritative_directories` — `_data/` and `upload/`, read from
      `fileset.REMOTE_DIRS_TO_CREATE` `[ECP]`
- [x] `test_walk_stops_at_the_depth_limit` — a `list_dir` that returns itself forever; without
      this the test hangs rather than fails `[BVA]`
- [x] `test_walk_counts_every_directory_it_listed` `[HAPPY]`
- [x] `test_compare_puts_a_recorded_and_present_file_in_covered` `[ECP]`
- [x] `test_compare_puts_an_unrecorded_present_file_in_orphans` `[ECP]`
- [x] `test_compare_puts_a_recorded_absent_file_in_missing` `[ECP]`
- [x] `test_the_generated_config_is_covered_not_an_orphan` — it is in the manifest but not the
      file set; the same trap `upload.py:62-69` already fixed once `[ERR]`
- [x] `test_compare_of_an_empty_manifest_makes_everything_an_orphan` `[BVA]`
- [x] `test_compare_of_an_empty_listing_makes_everything_missing` `[BVA]`
- [x] `test_mlsd_entries_become_files_and_directories` — against `ScriptedFtp` `[HAPPY]`
- [x] `test_mlsd_skips_the_self_and_parent_entries` — `cdir` / `pdir` `[ERR]`
- [x] `test_a_server_that_refuses_mlsd_fails_with_a_transport_error_naming_it` `[NEG]`
- [x] `test_audit_deletes_nothing` — `"delete" not in transport.names()`, the read-only claim
      `[NEG]`
- [x] `test_audit_uploads_nothing_and_runs_no_bootstrap` — `"put" not in transport.names()` and
      `gallery.calls == []` `[NEG]`
- [x] `test_audit_reports_an_orphan_by_name` `[HAPPY]`
- [x] `test_audit_exits_zero_even_with_orphans` — a report is not a failure `[BVA]`
- [x] `test_more_orphans_than_the_cap_are_summarised` — `MAX_REPORTED_ORPHANS` + 1 `[BVA]`
- [x] `test_audit_says_it_deleted_nothing` — the literal closing sentence `[HAPPY]`
- [x] **Regression**: `test_dry_run_never_touches_the_transport` and every `test_transport.py`
      test — the port grew an operation; nothing that existed may change

#### Phase 6 — documents

- [ ] **Regression only**: `test_every_flag_the_tool_accepts_is_documented`,
      `test_every_exit_code_the_tool_can_return_is_documented`,
      `test_the_documented_test_count_is_the_number_pytest_reports`,
      `test_both_documents_date_the_same_measurement`,
      `test_every_relative_link_in_the_readme_resolves`. Raise `MIN_FLAGS` and `MIN_EXIT_CODES`
      in `test_readme.py` to sit just under the new totals, the way the existing constants sit
      just under the old ones — otherwise the anti-vacuity floor stops floor-ing.

### Integration Tests

None. There is no FTP server and no second web space; standing one up is out of scope and was
already ruled out (`.claude/rules/deployment.md`, "what has no local test double"). The
substitute is the hand-check ledger in Phase 6.

### End-to-End Tests

None, for the same reason. `pwg-deploy` has no browser surface.

### Manual Testing Steps

All against `bilder.foerderverein-sefferweich.de`, the sandbox
([decision 0021](../decisions/0021-remote-instance-is-a-sandbox.md)). Each result is recorded,
dated, in `docs/agents/TESTING.md`.

1. Full deploy with everything in place — read the `preflight`, `sync` and (absent) `galleries`
   lines.
2. Move the manifest aside, re-run, confirm exit 9 and the missing-manifest message; restore.
3. `--audit`, and note whether the host answers MLSD at all.
4. Spot-check two reported orphans over FTP by hand.

### Test Commands

```bash
# The whole suite — there is only one
cd tools/deploy && uv run pytest

# One file while iterating
cd tools/deploy && uv run pytest tests/test_preflight.py

# Predicted, offline, safe to run against the real credential file
cd tools/deploy && uv run pwg-deploy --dry-run deploy.local.json

# The read-only remote report (Phase 5 onward)
cd tools/deploy && uv run pwg-deploy --audit deploy.local.json
```

## Performance Considerations

- The preflight adds two or three HTTP round trips (`install.php`, and on an installed remote
  `pwg.session.login` + `pwg.getVersion`) to a run that already takes tens of seconds. Negligible,
  and skipped entirely on `--dry-run`.
- `--audit` issues one `MLSD` per remote directory — roughly 400 against the current tree. It is
  a separate mode, never part of a deploy.
- `MAX_DEPTH` is the only thing standing between a symlink loop on the server and an unbounded
  walk. It is a correctness bound first and a performance bound second.

## Migration Notes

- **`MANIFEST_VERSION` is not bumped.** Nothing about the manifest's shape changes, so existing
  `.state/*.json` files stay valid and no re-upload is triggered.
- **The first run after Phase 3 may abort where the previous version would have proceeded.** That
  is the point. An operator who hits it either deletes the manifest or passes
  `--adopt-remote-state`; both are named in the message.
- Two new exit codes (`9`, `10`) are added. Any wrapper script branching on exit status keeps
  working — the existing codes are unchanged.

## References

- Research: [2026-09-01-ftp-deploy-blank-vs-existing-remote.md](../research/2026-09-01-ftp-deploy-blank-vs-existing-remote.md)
- Prior plan: [2026-08-31-ftp-deployment-and-remote-install.md](2026-08-31-ftp-deployment-and-remote-install.md)
- [decision 0021 — the remote instance is a sandbox](../decisions/0021-remote-instance-is-a-sandbox.md)
- [decision 0023 — no database transfer to the remote](../decisions/0023-no-database-transfer-to-the-remote.md)
- `tools/deploy/README.md:111-133` — the manifest rules and the dated test count
- `.claude/rules/deployment.md` — the agent-facing rules this plan edits in four phases
- `tools/deploy/tests/test_readme.py` — the structural guards every phase has to satisfy
- `admin/themes/default/template/site_update.tpl:19-24` — the six summary lines Phase 1 parses
- `ws.php:57-62`, `include/ws_functions/pwg.php:125-128` — `pwg.getVersion`, no `admin_only`
- `include/constants.php:10` — the `PHPWG_VERSION` define Phase 4 reads
