---
date: 2026-08-31T06:02:35+00:00
git_commit: ec41c7293c40326b36fb1f580e8faaa6c4eef1fe
branch: feat/provenance-metadata
topic: "FTP deployment script and first-run remote install"
tags: [plan, deployment, ftp, install, python, tooling]
status: approved
---

# FTP Deployment and First-Run Remote Install — Implementation Plan

## Overview

Build `tools/deploy/`, a stdlib-only Python 3 tool that uploads this Piwigo fork to an FTPS
web space — only the files that are needed and only those that changed — and then completes a
first-run install of the remote instance over HTTP: `install.php`, the generated
`local/config/config.inc.php`, activation of the three fork-local plugins, and a `site_update`
scan that turns the uploaded `galleries/` tree into albums and photos.

Credentials come from a JSON file passed as a parameter; a committed `deploy.example.json`
documents its structure. Nothing secret is committed.

All findings this plan builds on come from
[docs/agents/research/2026-08-30-ftp-deployment-and-remote-install.md](../research/2026-08-30-ftp-deployment-and-remote-install.md)
and are not re-derived here. Its twelve numbered decisions are treated as settled input.

## Current State Analysis

- **No deployment tooling exists at all.** No Python anywhere in the repo, no FTP/rsync/sftp/
  mysqldump usage, no CI, no root `composer.json`/`package.json`. `tools/` holds Perl, PHP and
  shell only. `docs/backlog.md` has no deployment entry. (Research §1.)
- **The install is a single unguarded POST branch.** `install.php:258-433` runs on
  `isset($_POST['install'])` — no CSRF token, no captcha. Eleven form fields complete a full
  install; `install.php:307-321` writes `local/config/database.inc.php` itself. (Research §3.)
- **"Already installed" is one file-level fact**: `PHPWG_INSTALLED` defined in
  `local/config/database.inc.php` (`install.php:156-165`). The database is never consulted, so
  the install step is trivially idempotent — a second run gets `Piwigo is already installed`.
- **The install is fully portable.** Measured: 0 rows in the database contain a host path or a
  URL. Every stored path is relative, every URL is computed per request from `$_SERVER`
  (`include/functions_url.inc.php:33-92`). A move needs no DB rewrite. (Research §4, §5.)
- **"Needed files" is much smaller than the working copy.** ~1.5 GB on disk excluding `.git`,
  but ~138 MB of tracked payload (`galleries/` 87 M + `themes/` 20 M + `admin/` 13 M +
  `language/` 12 M + core). The 1.17 GB difference is dev artifacts already excluded by each
  plugin's own `.gitignore`. 3321 tracked entries; `plugins/typetags` is a submodule and needs
  `--recurse-submodules` to enumerate its 157 files. (Research §6, decision 7.)
- **The host has no SSH** (ALL-INKL PrivatPlus), which is why FTP is the transport, and both
  `exec()` and Imagick are recorded as unresolved there. Both fork plugins already gate on
  `function_exists('exec')` and degrade rather than fatal, so they install and run on a host
  with no exiftool — only the write-back is lost. (Research §9.)
- **Sessions live in the database** (`$conf['session_save_handler'] = 'db'`) and no rewrite
  rules are needed (`question_mark_in_urls` / `php_extension_in_urls` both default true), so the
  deploy has no `.htaccess` and no session-directory concern. (Research §7.)
- **Writable directories** are enforced ad hoc; the hard gate is `_data/`
  (`include/template.class.php:80-99`, fatal). `upload/`, `local/config/`, `themes/`,
  `language/` follow. `plugins/` has no check. The permission set to apply is the one
  `tools/pwg_rel_create.sh:133-140` already uses.
- **Local Python**: `python3` 3.11.9 (pyenv) and `uv`/`uvx` on the host. The DDEV web container
  has Python 3.13.5 but no `pip3`, so this tool runs on the **host**, never inside DDEV.

## Desired End State

One command deploys the fork to a fresh FTPS web space and leaves a working, installed gallery:

```
$ uv run --directory tools/deploy pwg-deploy ../../deploy.local.json
Piwigo deploy → ftp.example.net:/piwigo
  transport   FTPS (AUTH TLS, PROT P)                              ok
  file set    3478 files, 138.2 MB  (excluded: 212 dev/test files)
  manifest    tools/deploy/.state/ftp.example.net-piwigo.json      (new)
  upload      3478 new, 0 changed, 0 unchanged, 0 removed
              [####################################]  138.2/138.2 MB
  dirs        _data/ upload/ created
  chmod       local _data upload plugins themes                    ok
  install     POST install.php                                     installed
  config      local/config/config.inc.php uploaded
  session     pwg.session.login as webmaster                       ok
  plugins     typetags activated, provenance activated, persons activated
  sync        admin.php?page=site_update&site=1                    106 photos, 4 albums
done in 4m12s
```

A second run of the same command uploads nothing, skips the install, skips already-active
plugins, and re-runs the sync — proving idempotence:

```
  upload      0 new, 0 changed, 3478 unchanged, 0 removed
  install     already installed — skipped
  plugins     typetags active, provenance active, persons active — nothing to do
  sync        106 photos, 4 albums (0 new)
```

Verification that the end state is reached: the remote URL serves the gallery, the four
recovered albums are visible, the three plugins appear active on `admin.php?page=plugins`, and
`--dry-run` on a third run reports zero pending transfers.

### Key Discoveries

- `install.php:156-165` — the sole install marker; drives the skip-if-installed branch.
- `install.php:147-151` — `$is_newsletter_subscribe` comes from `isset($_POST['newsletter_subscribe'])`,
  so simply omitting the field suppresses the outbound `fetchRemote()` call.
- `include/ws_functions/pwg.php:398-407` — `pwg.session.getStatus` returns `pwg_token`.
- `include/ws_functions/pwg.extensions.php:53-88` — `pwg.plugins.performAction` requires a
  matching `pwg_token` **and** `is_webmaster()`.
- `admin/include/plugins.class.php:187-219` — `activate` falls through to `install` when there is
  no DB row, so activation is what creates each plugin's schema (research decision 6).
- `tools/remote_sync.pl:41-56` — the exact `site_update` form field set to replay.
- `tools/pwg_rel_create.sh:123-140` — empty `upload/`+`_data/` creation and the chmod set.
- `include/config_default.inc.php:952` — `sync_chars_regex`, ASCII-only filenames; the four
  tracked album dirs and their 105 PNGs already satisfy it.

## What We're NOT Doing

- **No database transfer of any kind.** No dump, no import, no local→remote sync. The remote DB
  is built by `install.php` and filled by `site_update` + `pwg.persons.rescan` (research
  decision 9). Provenance values have no path to the remote and are deliberately not carried.
- **No production posture.** The remote is a toy/sandbox instance (research decision 9). The
  production instance is a separate, later decision.
- **No `Version:` bump verification** for plugin schema changes (research decision 10). The
  propagation is automatic via `autoupdate_plugin()`; the silent-failure gap is recorded, not
  gated.
- **No SSH, rsync or sftp path.** The host does not offer SSH.
- **No plain-FTP fallback.** FTPS is required and its absence is a loud failure (decision 2).
- **No pre-install probe script automation.** Decision 1's standalone probe and decision 12's
  `admin.php?page=maintenance&action=phpinfo` stay manual, five-minute checks; automating a file
  at a guessable URL is worse than doing it by hand.
- **No deletion of remote-authored content.** Prune only touches paths the previous manifest
  recorded. `upload/` and `_data/` are server-authoritative and never pruned.
- **No `upgrade.php` / `upgrade_feed.php` handling.** This fork adds no `install/db/` files, and
  a fresh install marks every migration applied (`install.php:413-431`).
- **No secrets in git.** Only `deploy.example.json` is committed.

## Implementation Approach

Hexagonal, because the two things that cannot run in a unit test — FTPS and the remote HTTP
endpoint — are exactly the two things worth isolating behind a port:

```
                    cli.py  (argparse, progress output, exit codes)
                      |
        +-------------+--------------+
        |                            |
   upload.py                    bootstrap.py        <- orchestration, port-typed
        |                            |
  +-----+------+            +--------+------+
  |            |            |               |
config.py  fileset.py   manifest.py    urls.py      <- pure: no I/O, unit-tested
        |                            |
   Transport (port)             HttpClient (port)
   |            |                |            |
FtplibTransport  FakeTransport  UrllibClient  FakeHttpClient
```

Everything that decides *what* to do is pure and exhaustively unit-tested. The two adapters
contain no decisions — they translate a call into `ftplib`/`urllib` and back — and are covered by
a documented manual smoke run recorded in the hand-check ledger.

Phases land in dependency order, each with its own tests, each committable on its own. Phases 1–3
produce no network traffic at all, so the risky parts (4–6) are built on a base that is already
proven.

Layout:

```
tools/deploy/
  pyproject.toml            dev-only: pytest; no runtime dependencies
  README.md                 the one command, and the production warning
  deploy.example.json       committed credential-file structure
  pwgdeploy/
    __init__.py  __main__.py  cli.py
    config.py  fileset.py  manifest.py  urls.py  errors.py
    upload.py  bootstrap.py
    transport.py            Transport port + FtplibTransport
    http.py                 HttpClient port + UrllibClient
  tests/
    fakes.py                FakeTransport, FakeHttpClient
    test_config.py  test_fileset.py  test_manifest.py  test_urls.py
    test_upload.py  test_bootstrap.py  test_cli.py
  .state/                   git-ignored: per-target manifests
```

---

## Phase 1: Config, credential file, and the package skeleton — IMPLEMENTED, VERIFIED 2026-08-31

### Overview

The package, its dev-only test runner, the committed example credential file, and a pure config
loader that fails with a typed, actionable error before a single byte is transferred.

### Changes Required

#### [x] 1. Package skeleton and dev tooling

**File**: `tools/deploy/pyproject.toml`, `tools/deploy/pwgdeploy/__init__.py`,
`tools/deploy/pwgdeploy/__main__.py`

**Changes**: A `pwgdeploy` package with **no runtime dependencies** (stdlib `ftplib`, `json`,
`hashlib`, `urllib`, `subprocess`, `pathlib`, `argparse` only) and pytest as the sole dev
dependency, run through `uv`.

```toml
[project]
name = "pwgdeploy"
version = "0.1.0"
requires-python = ">=3.11"
dependencies = []                      # stdlib only, on purpose

[project.scripts]
pwg-deploy = "pwgdeploy.cli:main"

[dependency-groups]
dev = ["pytest>=8", "pytest-randomly>=3"]   # order shuffling; see suite hygiene

[tool.pytest.ini_options]
addopts = "-q --strict-markers --strict-config -W error"  # warnings are failures
testpaths = ["tests"]
```

#### [x] 2. Typed errors

**File**: `tools/deploy/pwgdeploy/errors.py`

**Changes**: One exception type per distinct failure mode, per the error-handling rules — the CLI
maps each to a distinct exit code and a message that names the fix.

```python
class DeployError(Exception): ...            # base; carries .exit_code
class ConfigError(DeployError): ...          # malformed / incomplete credential JSON
class TransportError(DeployError): ...       # connect, auth, transfer
class InsecureTransportError(TransportError):...  # server offers no AUTH TLS
class RemoteHttpError(DeployError): ...      # non-2xx, or an unexpected body
class InstallError(RemoteHttpError): ...     # install.php reported field errors
class GitError(DeployError): ...             # git ls-files failed / not a repo
```

#### [x] 3. Config schema and loader

**File**: `tools/deploy/pwgdeploy/config.py`

**Changes**: Frozen dataclasses plus a pure `load(mapping) -> DeployConfig` that validates and
normalises. **Validation happens at this boundary only**; everything downstream trusts the result.

```python
@dataclass(frozen=True)
class FtpConfig:      host: str; user: str; password: str; port: int = 21
                      # remote_root normalised to a leading "/", no trailing "/", default "/"
@dataclass(frozen=True)
class MysqlConfig:    host: str; user: str; password: str; database: str; prefix: str = "piwigo_"
@dataclass(frozen=True)
class AdminConfig:    username: str; password: str; email: str
@dataclass(frozen=True)
class SiteConfig:     base_url: str; language: str = "de_DE"; assume_https: bool = True
                      exiftool_path: str = ""
@dataclass(frozen=True)
class DeployConfig:   ftp: FtpConfig; mysql: MysqlConfig; admin: AdminConfig; site: SiteConfig

def load(raw: Mapping) -> DeployConfig: ...
def load_file(path: Path) -> DeployConfig: ...   # thin: read + json.loads + load()
```

Validation rules, each mirroring what `install.php` itself enforces so a bad value fails locally
in milliseconds instead of after a 138 MB upload:

- every required key present and a non-empty string; unknown top-level keys rejected by name
- `mysql.prefix` — `install.php:277-285`: ≤ 20 chars, no leading digit, `^[a-zA-Z0-9_$]*$`
- `admin.username` — `install.php:287-291`: non-empty, contains neither `'` nor `"`
- `admin.email` — non-empty and shaped like an address (the local mirror of
  `validate_mail_address()`; the server remains the authority)
- `site.base_url` — absolute `http(s)://`, trailing slash stripped
- `ftp.remote_root` — normalised; `..` segments rejected
- `ftp.port` — integer in 1..65535

#### [x] 4. Committed example credential file

**File**: `tools/deploy/deploy.example.json`

```json
{
  "ftp":   { "host": "ftp.example.net", "user": "w01234", "password": "REPLACE_ME",
             "port": 21, "remote_root": "/piwigo" },
  "mysql": { "host": "localhost", "user": "d01234", "password": "REPLACE_ME",
             "database": "d01234", "prefix": "piwigo_" },
  "admin": { "username": "webmaster", "password": "REPLACE_ME",
             "email": "you@example.net" },
  "site":  { "base_url": "https://gallery.example.net", "language": "de_DE",
             "assume_https": true, "exiftool_path": "" }
}
```

#### [x] 5. Keep real credential files and manifests out of git

**File**: `.gitignore`

**Changes**: The root `.gitignore` already ignores nothing under `tools/`. Add exactly two lines
plus the Python noise the tool will produce:

```
# Deploy tool: real credential files and per-target manifests
/deploy.*.json
!/tools/deploy/deploy.example.json
/tools/deploy/.state/
__pycache__/
.pytest_cache/
```

### Success Criteria

#### Automated Verification
- [x] `cd tools/deploy && uv run pytest tests/test_config.py` passes
- [x] `cd tools/deploy && uv sync` resolves with an empty runtime dependency set
- [x] `python3 -c "import pwgdeploy"` works with no site-packages beyond stdlib
- [x] `git check-ignore -v deploy.local.json` reports the new rule
- [x] `git status --porcelain` shows `deploy.example.json` as tracked and no real credential file

#### Manual Verification
- [x] `deploy.example.json` is copyable to `deploy.local.json` — **automated** as
      `test_the_example_copies_to_a_working_local_credential_file`
- [x] A deliberately broken value (prefix `9foo`) produces a message naming the field and the rule
      — **automated** as `test_prefix_leading_digit_is_rejected` (field *and* rule asserted) and
      generalised over all twelve rejection paths by
      `test_every_rejection_names_the_offending_field`
- [ ] *Not automatable*: whether every field of `deploy.example.json` is **self-explanatory** is a
      human judgement with no oracle. Hand-check ledger entry, `docs/agents/TESTING.md` (Phase 7).

Automated in addition, not asked for by the plan but by the same manual criterion: the
`.gitignore` rules that keep a real credential file uncommittable are now a structural guard,
`tests/test_gitignore.py`. It runs `git check-ignore --no-index` — without `--no-index` git
reports every *tracked* path as not-ignored, which made the anti-vacuity control vacuous.

**Implementation Note**: After this phase and its automated verification, pause for manual
confirmation before Phase 2.

---

## Phase 2: The file set — what "needed" means — IMPLEMENTED, VERIFIED 2026-08-31

### Overview

Turn the working copy into the exact list of paths to publish. Enumeration is a thin `git`
adapter; the decisions are a pure filter, so every exclusion rule is unit-testable without a
repository.

### Changes Required

#### [x] 1. Git enumeration adapter

**File**: `tools/deploy/pwgdeploy/fileset.py`

```python
def git_tracked_paths(repo_root: Path, run=subprocess.run) -> list[str]:
    """git ls-files -z --recurse-submodules  (decision 7: typetags is a submodule,
    so a plain ls-files reports one gitlink instead of its 157 files)."""
```

Raises `GitError` naming the command when git exits non-zero or the output is empty — an empty
file set must fail loudly rather than deploy nothing.

#### [x] 2. Pure exclusion filter

**File**: `tools/deploy/pwgdeploy/fileset.py`

```python
EXCLUDED_DIR_NAMES  = ("tests",)                    # any segment named tests/
EXCLUDED_BASENAMES  = ("phpunit.xml", "composer.json", "composer.lock",
                       "package.json", "package-lock.json", "playwright.config.js",
                       "create-test-users.php")
EXCLUDED_PREFIXES   = ("docs/", ".claude/", ".githooks/", "local/config/",
                       "tools/deploy/", ".gitignore", ".gitmodules")

def select(tracked: Iterable[str]) -> list[str]:
    """Decision 11's deploy exclusion list, applied to a tracked-path list."""
```

Notes on scope, each a deliberate call:
- `tests/` is matched **as a path segment**, so `plugins/persons/tests/...` goes and a
  hypothetical `themes/modus/js/tests.js` stays.
- `local/config/` is excluded because `database.inc.php` is written by `install.php` on the
  remote and `config.inc.php` is generated by this tool (decision 8). The tracked
  `local/**/index.php` guards **are** published — they are the directory-listing guards.
- `tools/deploy/` excludes itself. The tool has no business on the web space.
- `docs/` and `.claude/` are agent/working artifacts, not application files.

#### [x] 4. Completeness guard — added during Phase 2, not in the original plan

**File**: `tools/deploy/pwgdeploy/fileset.py`

```python
MIN_EXPECTED_PATHS = 3000
SUBMODULE_INIT_HINT = "git submodule update --init --recursive"

def declared_submodule_paths(repo_root, run=subprocess.run) -> list[str]:
    """git config --file .gitmodules --get-regexp ^submodule\..*\.path$"""

def check_complete(paths, submodule_paths, min_expected=MIN_EXPECTED_PATHS) -> None:
    """Pure. Raise GitError when the enumeration is obviously partial."""

def verified_tracked_paths(repo_root, run=subprocess.run) -> list[str]:
    """Enumerate, then prove the enumeration is whole. The entry point a deploy uses."""
```

Why it was needed: an **uninitialised submodule is dropped from `--recurse-submodules`
silently** — neither its 157 files nor its gitlink appear, and the total stays plausible
(3376 here), so the plan's empty-set `GitError` never fires. A deploy would publish a gallery
whose Colored Tags plugin has no code and report success. The submodule paths are read from
`.gitmodules` rather than hardcoded, so a future submodule is covered without an edit.

**Phase 5/6 must call `verified_tracked_paths()`, not `git_tracked_paths()`.** The raw
enumerator stays public only because the characterization tests need a list that is *not*
gated. `test_verified_tracked_paths_refuses_an_incomplete_working_copy` is what holds the
wiring in place.

#### [x] 3. Always-created directories

**File**: `tools/deploy/pwgdeploy/fileset.py`

```python
REMOTE_DIRS_TO_CREATE = ("_data", "upload")          # tools/pwg_rel_create.sh:123-127
WRITABLE_REMOTE_PATHS = ("local", "_data", "upload", "plugins", "themes")
```

### Success Criteria

#### Automated Verification
- [x] `cd tools/deploy && uv run pytest tests/test_fileset.py` passes — 46 tests; whole suite
      **117 passed, 0 skipped**, measured 2026-08-31 with the typetags submodule checked out,
      green twice in a row and with the shuffle disabled
- [x] A characterization test asserts `select()` over the **real** tracked list keeps
      `plugins/persons/main.inc.php` and drops `plugins/persons/tests/Support/create-test-users.php`
- [x] Anti-vacuity: the real-list test asserts the input has > 3000 entries and the output > 2500
      before any exclusion count is asserted

#### Manual Verification
- [x] `pwg-deploy --list-files` output contains no `vendor/`, `node_modules/`, `tests/` or
      `.playwright-browsers/` path — **automated** as the last loop of
      `test_real_repository_file_set`. The `pwg-deploy --list-files` *command* cannot run until the
      CLI lands in Phase 6; re-confirm it there against the same figures. **The `vendor/` half of
      this criterion was wrong as written
      and has been corrected.** `themes/default/vendor/fontello/` (15 files) is a *tracked core
      asset* — the gallery's icon font — and must ship. The dependency-manager `vendor/`
      directories are each plugin's own composer output, which that plugin's `.gitignore` already
      keeps out of the tracked list, so they can never reach the enumeration. The automated
      successor, `test_real_repository_file_set`, therefore excludes `plugins/*/vendor/` by name
      and asserts the fontello asset **is** present, which is that loop's anti-vacuity guard.
      `node_modules/`, `/tests/` and `.playwright-browsers/`: 0 hits, measured 2026-08-31.
      `pwg-deploy --list-files` itself cannot run until the CLI lands in Phase 6.
- [x] `plugins/typetags/` files appear individually, not as one entry — **automated** as
      `test_every_declared_submodule_contributes_its_files` (asserts the submodule contributed
      > `MIN_SUBMODULE_PATHS` paths and that `select()` does not drop it wholesale), and the
      failure mode it cannot observe from an uninitialised working copy is now a hard deploy-time
      error rather than a manual check — see task 4 above. **Observed green 2026-08-31** after the
      submodule was checked out in this worktree: 157 tracked files under `plugins/typetags/`, 123
      of them published, 0 skipped tests. Original finding, kept for the record: **not satisfiable
      from a worktree with an uninitialised submodule, and this is a silent-data-loss gap, not a
      local inconvenience.** In `.claude/worktrees/ftp-deploy`, `plugins/typetags` is an empty
      directory, and `git ls-files -z --recurse-submodules` then omits the submodule *entirely* —
      neither the 157 files nor the gitlink appear. Enumeration still returns 3376 paths, so
      `GitError`'s empty-set guard does not fire and a deploy would silently publish a gallery
      whose Colored Tags plugin has no code. Measured 2026-08-31: 3376 tracked, 3209 selected,
      134.4 MB, **0** `plugins/typetags/` files.

**Deviation from the plan, decided and implemented**: Phase 2 as written had no guard against a
partially-enumerated file set. A `MIN_EXPECTED_PATHS` floor **and** a per-submodule contribution
check now raise `GitError` naming `git submodule update --init --recursive`. See task 4.

**Measured 2026-08-31, submodule checked out**: 3535 tracked → 3332 selected, 134.7 MB, 203
excluded, 123 published `plugins/typetags/` files.

### Blocker found while verifying Phase 2: the typetags submodule pin is unpushed

`git submodule update --init --recursive` **fails from a clean clone of this fork**:

```
fatal: remote error: upload-pack: not our ref 44fdd062d1ab2f1c19304a4b3987fb2dc2fedfcd
```

The superproject pins `plugins/typetags` at `44fdd06`, but
`github.com/christianbaumann/Piwigo-Colored-Tags` only has `e920a3b` — `git submodule status`
reports `heads/master-1-g44fdd06`, i.e. one local commit that was never pushed. The commit exists
solely in this machine's `.git/modules/plugins/typetags` object store, which is how it was
recovered for this verification (`git fetch <local module store> 44fdd06` from inside the
submodule, then `git submodule update --recursive`).

Consequences for this plan, none of them cosmetic:

- **The deploy cannot run from a fresh clone** until the pin is pushed or moved. The 157 typetags
  files are simply unobtainable, and Phase 2's `check_complete()` will refuse the run — loudly and
  correctly, which is the guard doing its job rather than a second bug.
- **`.claude/rules/plugin-test-suites.md` is now out of date.** It states a fresh clone needs
  `git submodule update --init --recursive` "(measured 2026-08-31 against a real clone)"; that
  command cannot presently succeed. Per `backpressure.md`'s *keep instructions honest*, whichever
  change resolves the pin fixes that sentence in the same commit.

Fix is one push of the Colored Tags repository, or moving the pin to `e920a3b` — an owner
decision, outside this plan's scope, recorded here rather than worked around.

**Implementation Note**: Pause for manual confirmation before Phase 3.

---

## Phase 3: Manifest and diff — IMPLEMENTED, VERIFIED 2026-08-31

### Overview

Decide what changed. The manifest is a local, git-ignored, per-target JSON file mapping remote
path → sha256 of the bytes last successfully uploaded there.

### Changes Required

#### [x] 1. Manifest format and per-target path

**File**: `tools/deploy/pwgdeploy/manifest.py`

```python
MANIFEST_VERSION = 1

def manifest_path(state_dir: Path, host: str, remote_root: str) -> Path:
    """tools/deploy/.state/<host><slugified remote_root>.json — one per target,
    so two web spaces never share state."""

def load(path: Path) -> dict[str, str]:      # {} when absent or a version mismatch
def save(path: Path, entries: Mapping[str, str]) -> None:   # atomic: tmp + os.replace
```

A version mismatch discards the manifest rather than guessing — the next run re-uploads
everything, which is correct-but-slow instead of fast-but-wrong.

#### [x] 2. Hashing

**File**: `tools/deploy/pwgdeploy/manifest.py`

```python
HASH_CHUNK_BYTES = 1 << 20
def file_hash(path: Path) -> str:            # sha256 hex, streamed
```

sha256 over file bytes rather than git blob SHA-1: the same code path then covers tracked files,
submodule files and the generated `config.inc.php`, and nothing has to know about git's blob
header. Hashing ~138 MB costs well under a second.

#### [x] 3. The diff

**File**: `tools/deploy/pwgdeploy/manifest.py`

```python
@dataclass(frozen=True)
class Diff:
    new: list[str]; changed: list[str]; unchanged: list[str]; removed: list[str]
    @property
    def pending(self) -> list[str]: ...      # new + changed, deterministic order

def diff(current: Mapping[str, str], previous: Mapping[str, str]) -> Diff:
    """removed = previous - current. Only paths the previous manifest recorded are
    ever eligible for deletion, so remote-authored content under upload/ and _data/
    is unreachable from here by construction."""
```

### Success Criteria

#### Automated Verification
- [x] `cd tools/deploy && uv run pytest tests/test_manifest.py` passes — 23 tests; whole suite 140
- [x] Round-trip test: `save()` then `load()` returns an equal mapping
- [x] `load()` of a `{"version": 999}` file returns `{}` and does not raise

#### Manual Verification
- [x] Deleting `.state/*.json` makes the next `--dry-run` report every file as new — **automated**
      as `test_a_deleted_manifest_makes_every_file_new`, which composes `manifest_path` + `load` +
      `diff` over real files on disk: it saves a manifest, asserts `pending == []`, unlinks the
      file, and asserts every path comes back as new. Its own anti-vacuity control is that first
      `pending == []` — a `load()` that always returned `{}` fails there rather than passing the
      test for the wrong reason, confirmed by mutation. What stays for Phase 5 is only the CLI's
      `--dry-run` *printing*, which is already one of that phase's listed checks.

**Deviation from the plan, decided and implemented**: `manifest_path()` slugifies host and remote
root instead of taking the root verbatim, so no separator or `..` can reach the file name — a
remote root of `/www/../../etc` would otherwise have written outside `.state/`. Covered by
`test_manifest_path_holds_no_separators`.

Nine tests beyond the plan's list: `test_manifest_path_is_a_json_file_inside_the_state_dir`,
`test_manifest_path_holds_no_separators`, `test_save_creates_the_state_directory` (a fresh clone
has no `.state/`), `test_load_of_unreadable_json_is_empty`, `test_hash_of_an_empty_file`,
`test_a_changed_byte_changes_the_hash` (anti-vacuity for the diff cases),
`test_pending_is_new_and_changed`, `test_removed_is_only_ever_previously_recorded_paths` and
`test_a_deleted_manifest_makes_every_file_new`.

The three guards were proved killable rather than assumed (host, no DDEV, so the Mutagen caveat in
`mutation-testing.md` does not apply; each `sed` was verified to have changed the file first):

| Mutant | Expected killer | Result |
|---|---|---|
| version check → always accept | `test_load_of_a_future_version_is_empty` | killed |
| `save()` writes the target directly, no tmp + `os.replace` | `test_save_is_atomic` | killed |
| `load()` always returns `{}` | `test_a_deleted_manifest_makes_every_file_new` (its `pending == []` control) | killed |

**Implementation Note**: Pause for manual confirmation before Phase 4.

---

## Phase 4: The Transport port and the FTPS adapter — IMPLEMENTED 2026-08-31; the one manual step is automated but unrun (credentials)

### Overview

One narrow port for everything the upload needs from a file server, an in-memory fake that the
whole of Phase 5 is tested against, and an `ftplib` adapter that refuses to speak cleartext.

### Changes Required

#### [x] 1. The port

**File**: `tools/deploy/pwgdeploy/transport.py`

```python
class Transport(Protocol):
    def connect(self) -> None: ...
    def close(self) -> None: ...
    def makedirs(self, remote_dir: str) -> None: ...      # mkdir -p, idempotent
    def put(self, local: Path, remote_path: str) -> None: ...
    def delete(self, remote_path: str) -> None: ...
    def chmod(self, remote_path: str, mode: str) -> bool: ...  # False when unsupported
    def exists(self, remote_path: str) -> bool: ...
```

Six operations, no more. `chmod` returns a bool rather than raising because `SITE CHMOD` is an
optional FTP extension — a server that refuses it is a warning, not a failed deploy.

#### [x] 2. The FTPS adapter

**File**: `tools/deploy/pwgdeploy/transport.py`

```python
class FtplibTransport:
    """ftplib.FTP_TLS. Decision 2: FEAT-probe at connect and require AUTH TLS."""
    def connect(self):
        # 1. FTP_TLS(timeout=CONNECT_TIMEOUT_SECONDS)  — explicit timeout, never blocking
        # 2. feat = self._ftp.sendcmd('FEAT')
        # 3. if 'AUTH TLS' not in feat: raise InsecureTransportError naming host and
        #    listing what FEAT did advertise — plain FTP sends the password in clear
        # 4. login(); prot_p(); set_pasv(True)
```

Constants, named rather than magic: `CONNECT_TIMEOUT_SECONDS = 30`,
`TRANSFER_BLOCKSIZE = 1 << 16`.

`makedirs` walks the path creating one segment at a time and treats "already exists" (550) as
success — the only way to be idempotent over FTP.

#### [x] 3. The fake

**File**: `tools/deploy/tests/fakes.py`

```python
class FakeTransport:
    """In-memory. Records puts/deletes/chmods in order; can be armed to fail on the
    Nth put, which is how the crash-safety test in Phase 5 is written."""
```

#### [x] 4. The manual step, made runnable — added during verification

**File**: `tools/deploy/pwgdeploy/smoke.py`

The phase's manual criterion — connect to the real web space, upload one file, see it over
HTTP — needs credentials, but it does not need a human in an FTP client. `smoke.run()` is that
procedure, port-typed and unit-tested against `FakeTransport`; the real adapters are what the
run adds underneath it:

    python3 -m pwgdeploy.smoke deploy.local.json

It uploads a probe named and *bodied* with a fresh random token, fetches it over HTTP,
compares the bytes, deletes it and confirms it is gone. The token is in the body so a stale
file or a caching proxy cannot satisfy the check by accident; a byte mismatch names both the
remote path and the URL, because "the FTP root and the document root are different
directories" is the failure this check exists to find.

`pwgdeploy/urls.py` (a Phase 5 deliverable) was **brought forward** rather than duplicated —
`smoke.py` needs the same two joins the upload does. Phase 5 inherits it, tests and all.

### Success Criteria

#### Automated Verification
- [x] `cd tools/deploy && uv run pytest tests/test_transport.py` passes — 20 tests; whole
      suite 185, twice in a row and with `-p no:randomly`
- [x] A `FEAT` response lacking `AUTH TLS` raises `InsecureTransportError` whose message contains
      the host and the advertised feature list (asserted with a real substring, not `pytest.raises`
      alone) — `test_feat_without_auth_tls_raises`, with
      `test_a_refused_handshake_never_sends_the_password` as its anti-vacuity partner: the point of
      the guard is the login that does **not** happen

#### Manual Verification
- [ ] Against the real web space: connect succeeds, `PROT P` is negotiated, and a single small
      file uploads and reappears over HTTP. **Automated** as
      `python3 -m pwgdeploy.smoke <credential file>` (task 4 above) — what stays manual is
      supplying `deploy.local.json`, which no automation can invent. Not yet run: no credential
      file exists in this checkout.
- [ ] Recorded in the hand-check ledger with the date — deferred to Phase 7 on purpose, because
      the run has not happened and a ledger row for an unperformed check is exactly the "marked
      done on prose alone" the ledger rule forbids

**Defect found while automating this step.** Running `python3 -m pwgdeploy.smoke
deploy.example.json` against a host that does not resolve produced a raw `socket.gaierror`
traceback: `FtplibTransport.connect()` let socket and `ftplib` errors escape untyped, so none of
`errors.py`'s exit codes applied to the single most common real failure — a wrong host or a
refused password. Fixed by wrapping the handshake in `TransportError`, with
`test_an_unreachable_host_is_a_transport_error_not_a_traceback` and
`test_a_refused_login_is_a_transport_error` watched red first, plus
`test_a_cleartext_server_is_still_reported_as_insecure_not_merely_as_a_failure` as the
anti-vacuity partner proving the wrapping does not swallow `InsecureTransportError`. The command
now exits 5 with a message naming host, port and user.

**Implementation Note**: Pause for manual confirmation before Phase 5.

---

## Phase 5: The upload run — IMPLEMENTED 2026-08-31; the two manual steps need credentials

### Overview

Orchestrate a transfer: enumerate, hash, diff, create directories, upload, prune, chmod, and
persist the manifest as it goes so an interrupted run resumes instead of restarting.

### Changes Required

#### [x] 1. The run

**File**: `tools/deploy/pwgdeploy/upload.py`

```python
@dataclass(frozen=True)
class UploadResult:
    uploaded: list[str]; deleted: list[str]; unchanged_count: int
    dirs_created: list[str]; chmod_supported: bool

def run(config, repo_root, state_dir, transport, *, dry_run=False,
        progress=None) -> UploadResult:
```

Order, and why each step sits where it does:

1. `fileset.select(git_tracked_paths(...))` → hash each → the current manifest.
2. `diff()` against the loaded previous manifest.
3. **dry-run returns here**, having touched nothing and connected to nothing.
4. `transport.connect()`.
5. `makedirs` for every distinct parent directory, plus `REMOTE_DIRS_TO_CREATE`.
6. Upload `diff.pending`, **writing the manifest after each file**. A crash at file 2000 of 3478
   therefore costs the remaining 1478, not the whole 138 MB. This is why the manifest records
   what was *successfully uploaded*, not what was *intended*.
7. Prune `diff.removed` — and only `diff.removed`, so nothing outside the previous manifest is
   ever a deletion candidate.
8. `chmod` the `WRITABLE_REMOTE_PATHS` set to `0777`, mirroring
   `tools/pwg_rel_create.sh:133-140`. A `False` return sets `chmod_supported = False` and emits
   one warning naming the paths to fix by hand in the FTP client.

#### [x] 2. Remote path joining — landed early, in Phase 4

**File**: `tools/deploy/pwgdeploy/urls.py`

```python
def remote_path(remote_root: str, rel_path: str) -> str:   # always forward slashes
def site_url(base_url: str, path: str) -> str:             # https://host/install.php
```

Pure, and separate from both adapters, because a path-joining bug that only shows up over FTPS is
the most expensive kind to find.

### Success Criteria

#### Automated Verification
- [x] `cd tools/deploy && uv run pytest tests/test_upload.py tests/test_urls.py` passes — 40 tests
      (26 upload, 14 urls); whole suite **210 passed, 0 skipped**, measured 2026-08-31, green
      three times in a row and with `-p no:randomly`
- [x] Second-run test: running `run()` twice against the same `FakeTransport` uploads N files then
      0, with `unchanged_count == N` (N asserted > 0 first)
- [x] Crash-safety test: `FakeTransport` armed to fail on put #3 leaves a manifest holding exactly
      the first two paths, and a re-run uploads only the remainder
- [x] Prune test: a path present in the previous manifest and absent from the file set is deleted;
      a path present on the fake but in **neither** manifest is untouched

#### Manual Verification
- [ ] A full first deploy to the real web space completes and the byte total matches ~138 MB —
      needs `deploy.local.json`, which does not exist in this checkout; the CLI that runs it
      lands in Phase 6, so this and the next criterion are performed there
- [ ] Interrupting a real run with Ctrl-C and re-running resumes rather than restarts

**Deviations from the plan as written, decided and implemented**:

- `run()` takes two keyword arguments the sketch does not name: `prune=True`, because `--no-prune`
  is a listed CLI flag and the run is where it has to be honoured, and `tracked=
  fileset.verified_tracked_paths`, the enumeration injected the same way `fileset` injects
  `run=subprocess.run` — the upload suite is about the transfer, not about git.
- `UploadResult` carries the whole `Diff` instead of a bare `unchanged_count`; the count stays as
  a property. A dry run must report what it *would* send, and the CLI's summary line needs
  new/changed/unchanged/removed — all four already live on `Diff`, so a second copy would rot.
- `manifest.save()` also runs after each **deletion**, not only after each upload. A prune
  interrupted halfway would otherwise leave the manifest claiming files that are gone.

**Mutant that survived the first table, and what it cost.** Turning the `chmod` loop's list into a
generator — `all([...])` → `all(...)` — left the suite green: `all()` then short-circuits at the
first refusal, so a server without `SITE CHMOD` is asked about one path and the warning names one
path instead of five. `test_chmod_refusal_is_a_warning_not_a_failure` now asserts the whole call
list and kills it. Three mutants that died on the first table: dropping the per-file
`manifest.save()` (9 tests), ignoring the `prune` flag (2), and pruning every manifest entry
rather than `diff.removed` (9).

**Implementation Note**: Pause for manual confirmation before Phase 6.

---

## Phase 6: Remote bootstrap over HTTP

### Overview

Turn the uploaded tree into an installed gallery: install, config, session, plugins, sync — each
step idempotent, so re-running the command is always safe.

### Changes Required

#### [ ] 1. The HTTP port and adapter

**File**: `tools/deploy/pwgdeploy/http.py`

```python
class HttpClient(Protocol):
    def get(self, url: str) -> Response: ...
    def post(self, url: str, fields: Mapping[str, str]) -> Response: ...

class UrllibClient:
    """urllib.request with a cookie jar, so the ws.php session cookie carries over to
    the admin.php form POST — which is exactly what tools/remote_sync.pl relies on."""
```

`HTTP_TIMEOUT_SECONDS = 60`; `SYNC_TIMEOUT_SECONDS = 600` for the `site_update` POST, which scans
106 photos and reads their metadata.

#### [ ] 2. Install

**File**: `tools/deploy/pwgdeploy/bootstrap.py`

```python
INSTALLED_MARKER = 'Piwigo is already installed'      # install.php:162

def is_installed(client, base_url) -> bool:
    """GET install.php and look for the marker. install.php:156-165 decides this from
    local/config/database.inc.php alone, so this is the same question the server asks."""

def install(client, config) -> None:
    """POST install.php?language=<lang>. The form at
    admin/themes/default/template/install.tpl:203-295 carries twelve named POST inputs;
    we send ten of them and deliberately omit newsletter_subscribe and
    send_credentials_by_mail — both are isset() checks (install.php:147-151), so
    omitting them suppresses the outbound fetchRemote() and the credential mail.
    `language` is a GET parameter the form's select navigates to, not a posted field."""
```

Posted fields: `dbhost`, `dbuser`, `dbpasswd`, `dbname`, `prefix`, `admin_name`, `admin_pass1`,
`admin_pass2` (= `admin_pass1`), `admin_mail`, `install=1`.

Failure handling: `install.php` re-renders its form with an error list rather than returning a
non-2xx. So a successful POST is confirmed by a **follow-up** `is_installed()` returning True; if
it does not, raise `InstallError` carrying the errors scraped from the response.

#### [ ] 3. Generated `local/config/config.inc.php`

**File**: `tools/deploy/pwgdeploy/bootstrap.py`

Decision 8 — generated from the JSON, never uploaded from the local copy:

```php
<?php
// Generated by tools/deploy — do not edit on the server, it is overwritten on deploy.
$conf['assume_https'] = true;
$conf['provenance_exiftool_path'] = '';
$conf['persons_exiftool_path'] = '';
```

Written to a temp file, hashed and uploaded through the same `Transport` and the same manifest as
every other file, so a changed `exiftool_path` in the JSON re-uploads it and an unchanged one does
not. `assume_https` is written even though research §4 measured that **nothing reads it** — it is
what the local install carries, and dropping it is a separate decision from this plan.

Uploaded **after** install: `install.php` writes `database.inc.php` into the same directory, and
ordering the two avoids any question about which run created `local/config/`.

#### [ ] 4. Session and plugin activation

**File**: `tools/deploy/pwgdeploy/bootstrap.py`

```python
PLUGINS_TO_ACTIVATE = ("typetags", "provenance", "persons")

def login(client, config) -> str:
    """POST ws.php?format=json method=pwg.session.login, then
    method=pwg.session.getStatus for pwg_token (include/ws_functions/pwg.php:398-407)."""

def activate_plugins(client, base_url, token) -> dict[str, str]:
    """pwg.plugins.performAction action=activate — webmaster + matching token
    (include/ws_functions/pwg.extensions.php:53-88). Decision 6: activation routes
    through activate -> install (admin/include/plugins.class.php:187-219), which is
    what creates each plugin's tables; inserting piwigo_plugins rows directly would
    skip install() and leave no schema.
    Reads pwg.plugins.getList first and skips plugins already 'active', so a re-run
    is a no-op rather than an error."""
```

#### [ ] 5. Filesystem sync

**File**: `tools/deploy/pwgdeploy/bootstrap.py`

```python
def sync(client, base_url) -> None:
    """POST admin.php?page=site_update&site=1 with the field set from
    tools/remote_sync.pl:41-56: sync=files, display_info=1, add_to_caddie=1,
    privacy_level=0, sync_meta=1, simulate=0, subcats-included=1, submit=1.
    Needs the admin session cookie from login(); site id 1 is the row install.php
    creates at :390-394 with galleries_url './galleries/'."""
```

Runs last, because it scans the `galleries/` tree the upload just placed. Filenames are already
known to satisfy `sync_chars_regex` (research §8), so no filtering is needed here.

#### [ ] 6. The CLI

**File**: `tools/deploy/pwgdeploy/cli.py`

```
usage: pwg-deploy [-h] [--dry-run] [--list-files] [--no-bootstrap] [--no-prune]
                  [--verbose] CONFIG_JSON
```

- `--dry-run` — enumerate, hash, diff, print; connect to nothing.
- `--list-files` — print the selected file set and exit.
- `--no-bootstrap` — upload only; skip install, plugins and sync.
- `--no-prune` — never delete, even inside the manifest.
- Each `DeployError` subclass maps to its own exit code; the message names the fix.

### Success Criteria

#### Automated Verification
- [ ] `cd tools/deploy && uv run pytest` passes the whole suite
- [ ] `cd tools/deploy && uv run pytest` passes **twice in a row**, each run in a different
      order — `pytest-randomly` shuffles by default, so two consecutive green runs are two
      different orderings and a hidden inter-test dependency surfaces as a flake
- [ ] A reported failure reproduces from its printed seed:
      `uv run pytest --randomly-seed=<n>`
- [ ] `ddev exec php -l` on nothing — no PHP is changed in this plan; note this explicitly
      rather than claiming a PHP check ran
- [ ] `bash tools/test-hooks.sh` still passes (the commit gate is untouched)

#### Manual Verification
- [ ] Against the real web space: a first run installs, activates three plugins and syncs; the
      gallery renders and the four recovered albums are visible
- [ ] A second run reports `already installed`, `nothing to do`, and 0 pending transfers
- [ ] `admin.php?page=plugins` shows typetags, provenance and persons active
- [ ] The pre-install probe (research decision 1) and `admin.php?page=maintenance&action=phpinfo`
      (decision 12) are run by hand and their `exec`/`imagick` answers recorded

**Implementation Note**: Pause for manual confirmation before Phase 7.

---

## Phase 7: Documentation and decisions

### Overview

Record the rules, the how-to, and the two known gaps — so the next person cites a decision instead
of re-litigating it.

### Changes Required

#### [ ] 1. The how-to

**File**: `tools/deploy/README.md`

Diataxis how-to: prerequisites, copy the example JSON, the one command, what each flag does, what
is uploaded and what is not, and — stated, not implied — that the target is a sandbox and this
tool is **never** safe to point at a production install.

#### [ ] 2. Rules file and its read-trigger

**File**: `.claude/rules/deployment.md`, `CLAUDE.md`

The rules file carries the command, the credential-file location, what the exclusion list holds
and why, and the manual probe steps. `CLAUDE.md` gains one line under *Additional rules* with a
read-trigger naming the task — "read before changing the deploy tool or deploying to the web
space" — and stays under its 100-line cap.

#### [ ] 3. Decisions

**File**: `docs/agents/decisions/0020-remote-instance-is-a-sandbox.md`,
`docs/agents/decisions/0021-no-database-transfer-to-the-remote.md`

- 0020 records research decision 9's framing: the remote is disposable, so `restore` dropping
  provenance columns is tolerable here and would not be in production; this must be revisited
  before any real gallery is hosted.
- 0021 records that no DB transfer exists and why — content is re-created by `site_update` and
  `pwg.persons.rescan`, and provenance values have no path to the remote at all.

#### [ ] 4. Backlog and the known gap

**File**: `docs/backlog.md`

One entry for research decision 10's accepted silent-failure mode: a plugin schema change whose
`Version:` header is not bumped never reaches the remote table, and persons has no
`ALTER … MODIFY` path so a *changed* column definition does not propagate even with a bump.

#### [ ] 5. Hand-check ledger

**File**: `docs/agents/TESTING.md`

Add the dated entries for what has no automated oracle: the `FtplibTransport` smoke run, the real
first deploy, and the two manual host probes — each with the reason it cannot be automated (no FTP
server and no remote web space in CI, because there is no CI).

### Success Criteria

#### Automated Verification
- [ ] `awk 'END{print NR}' CLAUDE.md` reports < 100
- [ ] `awk 'END{print NR}' .claude/rules/deployment.md` reports < 500
- [ ] `bash tools/test-hooks.sh` passes

#### Manual Verification
- [ ] A fresh reader can deploy from `tools/deploy/README.md` alone
- [ ] Each decision file states what was decided, why, and what would reverse it

---

## Testing Strategy

The pyramid here is unusually base-heavy on purpose: the two things this tool does that can fail
in the world — FTPS and a remote HTTP endpoint — have **no** local test double worth building
(there is no FTP server and no CI), so every decision is pushed down into a pure function and the
adapters are kept decision-free and hand-checked.

### Test Design Techniques Applied

Tags per `.claude/rules/test-design.md`: `[HAPPY]` `[NEG]` `[ECP]` `[BVA]` `[ST]` `[DT]` `[ERR]`.

- **Decision table** applies to `diff()` — two conditions (in current / in previous) and, when in
  both, hash equal or not; four outcomes.
- **State transition** applies to the bootstrap: `fresh → installed → configured → plugins active
  → synced`, and to the run-twice path through it.
- **Boundary values** apply to `mysql.prefix` (0, 1, 20, 21 chars) and `ftp.port` (0, 1, 65535,
  65536).
- **Equivalence classes** apply to the exclusion filter: each rule kind is one class.
- **Not applicable, recorded rather than omitted**: no arithmetic threshold exists anywhere in
  the tool, so BVA has nothing to say outside config validation; and there is no concurrency, so
  no interleaving cases.

### Unit Tests

#### `tests/test_config.py`

**Happy path**
- [x] `test_loads_the_example_file` — `deploy.example.json` itself loads into a `DeployConfig`
      `[HAPPY]` — this doubles as a **structural guard**: the committed example cannot drift out
      of sync with the loader without a red test
- [x] `test_defaults_are_applied` — omitting `port`, `prefix`, `language`, `assume_https`,
      `exiftool_path` yields the documented defaults `[HAPPY]`

**Negative**
- [x] `test_missing_section_names_the_section` — no `mysql` key → `ConfigError` naming `mysql` `[NEG]`
- [x] `test_unknown_top_level_key_is_rejected` — a typo'd section fails rather than being ignored `[NEG]`
- [x] `test_empty_password_is_rejected` `[NEG]` `[ECP]`
- [x] `test_admin_username_with_a_quote_is_rejected` — mirrors `install.php:287-291` `[NEG]`
- [x] `test_relative_base_url_is_rejected` `[NEG]`
- [x] `test_remote_root_with_dotdot_is_rejected` `[NEG]`

**Boundaries**
- [x] `test_prefix_length` — 0 / 1 / 20 / 21 chars: reject, accept, accept, reject `[BVA]`
- [x] `test_prefix_leading_digit_is_rejected` and `test_prefix_underscore_and_dollar_accepted` `[ECP]`
- [x] `test_port_bounds` — 0 / 1 / 65535 / 65536 `[BVA]`
- [x] `test_base_url_trailing_slash_is_stripped` and `test_remote_root_is_normalised`
      (`""`, `"/"`, `"piwigo"`, `"/piwigo/"` all → `"/piwigo"` or `""`) `[ECP]`

**Added during Phase 1 verification** (not in the original list):
- [x] `test_every_rejection_names_the_offending_field` — parametrised over all twelve rejection
      paths; a message that does not name its field wasted the run `[NEG]` `[ECP]`
- [x] `test_the_example_copies_to_a_working_local_credential_file` `[HAPPY]`
- [x] `tests/test_gitignore.py` — structural guard that `deploy.*.json`, `.state/`, `__pycache__` and `.venv` stay ignored
      while the tool itself stays tracked `[NEG]` `[BVA]`

#### `tests/test_fileset.py`

- [x] `test_keeps_an_ordinary_plugin_file` `[HAPPY]`
- [x] `test_drops_a_tests_segment_anywhere_in_the_path` `[ECP]`
- [x] `test_keeps_a_file_named_tests_js` — `tests` matches a **segment**, not a substring `[BVA]`
- [x] `test_drops_each_excluded_basename` — parametrised over `EXCLUDED_BASENAMES` `[ECP]`
- [x] `test_drops_each_excluded_prefix` — parametrised over `EXCLUDED_PREFIXES` `[ECP]`
- [x] `test_keeps_local_index_php_guards` — `local/**/index.php` survives while
      `local/config/*` does not `[BVA]`
- [x] `test_excludes_the_deploy_tool_itself` `[NEG]`
- [x] `test_empty_git_output_raises_git_error` — an empty file set must fail, never deploy
      nothing `[NEG]`
- [x] `test_git_failure_names_the_command` `[NEG]`
- [x] `test_real_repository_file_set` — runs `git ls-files --recurse-submodules` against this
      checkout; asserts input > 3000 entries **before** asserting anything about the output, and
      that the output contains no dependency-manager `vendor/`, `node_modules/` or `tests/` path
      `[ERR]` *(characterization: its oracle is the current repository contents, not a
      requirement)*

**Added during Phase 2** (not in the original list):
- [x] `test_an_excluded_basename_is_not_matched_as_a_substring` — parametrised over
      `EXCLUDED_BASENAMES`; `not-a-composer.json` stays. The obvious `endswith` implementation
      passes every other basename test and fails only this one `[BVA]`
- [x] `test_select_preserves_input_order_and_drops_nothing_else` — anti-vacuity for every
      `== []` assertion above, each of which a filter returning `[]` for everything would satisfy
      `[HAPPY]`
- [x] `test_git_tracked_paths_runs_recurse_submodules` — asserts the exact argv, so decision 7's
      flag cannot be dropped silently `[HAPPY]`
- [x] `test_the_created_directories_are_the_two_the_release_script_creates` — structural guard
      against `tools/pwg_rel_create.sh:123-127` drifting apart `[HAPPY]`

**The completeness guard** (task 4):
- [x] `test_check_complete_accepts_a_whole_enumeration` — anti-vacuity for every rejection case
      below, each of which a guard that raised unconditionally would satisfy `[HAPPY]`
- [x] `test_check_complete_accepts_a_repository_with_no_submodules` `[ECP]`
- [x] `test_check_complete_path_floor` — `MIN_EXPECTED_PATHS` minus one / exactly / plus one; the
      floor is inclusive `[BVA]`
- [x] `test_check_complete_rejects_an_uninitialised_submodule` — message names the submodule
      *and* the fix command `[NEG]`
- [x] `test_check_complete_is_not_fooled_by_a_prefix_match` — `plugins/typetags-backup/` does not
      count as `plugins/typetags` having contributed `[BVA]`
- [x] `test_declared_submodule_paths_parses_git_config` — `.gitmodules` is the source of truth;
      the path is never hardcoded `[HAPPY]`
- [x] `test_declared_submodule_paths_is_empty_without_gitmodules` — exit 1 means "no match", not
      failure `[NEG]` `[ECP]`
- [x] `test_declared_submodule_paths_raises_on_a_real_git_failure` `[NEG]`
- [x] `test_verified_tracked_paths_refuses_an_incomplete_working_copy` — proves the guard is
      *wired into* the deploy entry point, on a scripted double so it holds regardless of this
      working copy `[NEG]`
- [x] `test_verified_tracked_paths_returns_a_whole_enumeration` — anti-vacuity for the above
      `[HAPPY]`
- [x] `test_every_declared_submodule_contributes_its_files` — characterization; **skips**, naming
      the fix command, where the submodule is not checked out `[ERR]`
- [x] `test_verified_tracked_paths_agrees_with_the_raw_enumeration` — same, skips likewise `[ERR]`

**Mutation spot-check** (`.claude/rules/mutation-testing.md` — Phase 2's rules are the whole
point of the phase, so its five exclusion decisions were audited rather than deferred; run on the
host, no DDEV, so the Mutagen caveat does not apply. Each `sed` was verified to have changed the
file before the suite ran):

| Mutant | Expected killer | Result |
|---|---|---|
| `tests` segment match → substring match | `test_keeps_a_file_named_tests_js` | killed |
| `_is_local_guard` exception removed | `test_keeps_local_index_php_guards` | killed |
| empty-output `GitError` guard disabled | `test_empty_git_output_raises_git_error` | killed |
| basename equality → `endswith` | `test_an_excluded_basename_is_not_matched_as_a_substring` | killed |
| `--recurse-submodules` dropped from argv | `test_git_tracked_paths_runs_recurse_submodules` | killed |

And over the completeness guard added as task 4:

| Mutant | Expected killer | Result |
|---|---|---|
| floor `<` → `<=` | `test_check_complete_path_floor` at exactly the floor | killed |
| submodule contribution check removed | `test_check_complete_rejects_an_uninitialised_submodule` | killed |
| prefix match loses its trailing `/` | `test_check_complete_is_not_fooled_by_a_prefix_match` | killed |
| `git config` exit 1 treated as a hard failure | `test_declared_submodule_paths_is_empty_without_gitmodules` | killed |
| `verified_tracked_paths` skips `check_complete` | `test_verified_tracked_paths_refuses_an_incomplete_working_copy` | **survived on the first pass, then killed** |

The last row is the one worth keeping. The guard's *wiring* was initially witnessed only by a
characterization test that skips on an uninitialised submodule — so in this worktree the mutant
that disabled the whole check passed the suite. The successor test drives the entry point with a
scripted double and is independent of the working copy. Recorded per `mutation-testing.md`: a
survivor is a finding, and this one showed a guard that would have been silently inert exactly
where it was needed.

#### `tests/test_manifest.py`

- [x] `test_diff_decision_table` — parametrised over the four cases: in current only → new; in
      both, hash differs → changed; in both, hash equal → unchanged; in previous only → removed
      `[DT]`
- [x] `test_diff_of_two_empty_manifests_is_empty` `[BVA]`
- [x] `test_first_run_is_all_new` — empty previous `[BVA]`
- [x] `test_pending_order_is_deterministic` — two calls yield the same list `[HAPPY]`
- [x] `test_hash_of_a_known_byte_string` — sha256 of a fixture with a hard-coded digest `[HAPPY]`
- [x] `test_hash_streams_across_the_chunk_boundary` — a file of `HASH_CHUNK_BYTES + 1` bytes
      hashes equal to `hashlib.sha256` over the whole `[BVA]`
- [x] `test_save_then_load_round_trips` `[HAPPY]`
- [x] `test_load_of_a_missing_file_is_empty` `[NEG]`
- [x] `test_load_of_a_future_version_is_empty` — discard, do not guess `[NEG]` `[ECP]`
- [x] `test_save_is_atomic` — a pre-existing manifest is intact after a failed write `[ERR]`
- [x] `test_manifest_path_differs_per_target` — two hosts, and one host with two remote roots,
      produce three distinct paths `[ECP]`

#### `tests/test_transport.py`

- [x] `test_feat_without_auth_tls_raises` — message contains host **and** the advertised
      features `[NEG]`
- [x] `test_a_refused_handshake_never_sends_the_password` — anti-vacuity for the above: no
      `login` call, and the password appears nowhere in the call log `[NEG]`
- [x] `test_feat_with_auth_tls_logs_in_and_calls_prot_p` — asserts the call order on a
      scripted double `[HAPPY]` `[ST]`
- [x] `test_prot_p_comes_after_login` — the ordering on its own, so a reordering that keeps
      both calls present still fails `[ST]`
- [x] `test_the_connection_is_given_an_explicit_timeout` — `CONNECT_TIMEOUT_SECONDS` read from
      the module, never retyped `[ERR]`
- [x] `test_an_unreachable_host_is_a_transport_error_not_a_traceback` — the defect the smoke
      run found `[NEG]`
- [x] `test_a_refused_login_is_a_transport_error` `[NEG]`
- [x] `test_a_cleartext_server_is_still_reported_as_insecure_not_merely_as_a_failure` —
      anti-vacuity: the wrapping must not swallow the specific type `[NEG]`
- [x] `test_makedirs_creates_each_segment_once` `[HAPPY]`
- [x] `test_makedirs_treats_already_exists_as_success` — 550 on an existing dir `[ERR]`
- [x] `test_makedirs_of_the_root_creates_nothing` `[BVA]`
- [x] `test_makedirs_of_a_relative_path_creates_each_segment` — a remote root of `""` `[ECP]`
- [x] `test_put_stores_the_file_bytes_under_the_remote_path` `[HAPPY]`
- [x] `test_delete_removes_the_remote_path` `[HAPPY]`
- [x] `test_chmod_sends_site_chmod` `[HAPPY]`
- [x] `test_chmod_returns_false_when_site_chmod_is_refused` — not an exception `[NEG]`
- [x] `test_exists_is_true_for_a_path_the_server_reports` `[HAPPY]`
- [x] `test_exists_is_false_when_the_server_refuses_the_path` `[NEG]`
- [x] `test_close_quits_the_session` `[HAPPY]`
- [x] `test_close_before_connect_is_a_no_op` — a failed handshake still runs the caller's
      cleanup `[NEG]` `[BVA]`

**Mutation spot-check** (`.claude/rules/mutation-testing.md`; run on the host, no DDEV, so the
Mutagen caveat does not apply — each mutant was verified to have changed the file first):

| Mutant | Expected killer | Result |
|---|---|---|
| the `AUTH TLS` condition forced false | `test_feat_without_auth_tls_raises` and its anti-vacuity partner | killed (both) |
| `makedirs` catches `ZeroDivisionError` instead of `error_perm` | `test_makedirs_treats_already_exists_as_success` | killed |

#### `tests/test_smoke.py` — added during Phase 4 verification

- [x] `test_the_probe_is_uploaded_read_back_and_deleted` — the whole call order `[HAPPY]` `[ST]`
- [x] `test_the_probe_body_carries_the_token` `[HAPPY]`
- [x] `test_an_empty_remote_root_uploads_beside_the_login_directory` `[ECP]` `[BVA]`
- [x] `test_bytes_that_do_not_match_are_a_failure_naming_both_paths` `[NEG]`
- [x] `test_a_missing_file_is_a_failure_not_a_silent_pass` — `None` must not compare equal to
      the uploaded body `[NEG]`
- [x] `test_a_probe_still_served_after_delete_is_reported_not_raised` `[NEG]`
- [x] `test_the_session_is_closed_even_when_the_check_fails` `[NEG]`
- [x] `test_each_run_uses_a_fresh_token` `[ST]`
- [x] `test_main_without_a_credential_file_exits_two` `[NEG]`
- [x] `test_main_reports_a_bad_credential_file_with_the_config_exit_code` `[NEG]`

| Mutant | Expected killer | Result |
|---|---|---|
| the uploaded-vs-fetched byte comparison forced false | the two `[NEG]` body tests | killed |
| `transport.close()` dropped from the `finally` | `test_the_session_is_closed_even_when_the_check_fails` | killed |
| `remote_path` always prefixes the root, empty or not | `test_an_empty_remote_root_...` and two `test_urls` cases | killed |

#### `tests/test_upload.py`

- [x] `test_first_run_uploads_every_file` — count asserted > 0 first `[HAPPY]`
- [x] `test_second_run_uploads_nothing` — `unchanged_count == N`, `uploaded == []` `[ST]`
- [x] `test_changed_file_is_re_uploaded_alone` `[ST]`
- [x] `test_dry_run_never_touches_the_transport` — `FakeTransport` records zero calls,
      `connect` included `[NEG]`
- [x] `test_manifest_persists_after_each_file` — armed to fail on put #3, the manifest holds
      exactly the first two paths `[ERR]`
- [x] `test_resume_after_failure_uploads_only_the_remainder` `[ST]`
- [x] `test_prune_deletes_only_previously_recorded_paths` — a path on the fake that is in
      neither manifest is untouched `[NEG]`
- [x] `test_no_prune_flag_deletes_nothing` `[NEG]`
- [x] `test_creates_the_data_and_upload_directories` `[HAPPY]`
- [x] `test_chmod_refusal_is_a_warning_not_a_failure` — the run completes with
      `chmod_supported is False` `[NEG]`
- [x] `test_parent_directories_are_created_before_their_files` — order assertion on the fake
      `[ST]`

#### `tests/test_urls.py`

- [x] `test_remote_path_joins_with_forward_slashes` — including a root of `""` and `"/"` `[ECP]`
      — landed in Phase 4, which needed the same joins for the smoke check
- [x] `test_remote_path_never_doubles_a_slash` `[BVA]`
- [x] `test_remote_path_of_an_empty_relative_path_is_the_root_itself` `[BVA]`
- [x] `test_remote_path_uses_forward_slashes_whatever_the_host_os_is` — anti-vacuity: no
      `os.path.join` may creep in `[NEG]`
- [x] `test_site_url_joins_base_and_path` `[HAPPY]`
- [x] `test_site_url_never_doubles_a_slash` `[BVA]`
- [x] `test_site_url_keeps_the_scheme_separator` `[NEG]`

#### `tests/test_bootstrap.py`

- [ ] `test_is_installed_true_on_the_marker` — uses `INSTALLED_MARKER`, **read from the module**,
      never a second copy of the string in the test `[HAPPY]`
- [ ] `test_is_installed_false_on_the_install_form` `[NEG]`
- [ ] `test_install_posts_the_ten_expected_fields` — exact field set asserted, read from a
      module constant rather than typed a second time in the test `[HAPPY]` `[DT]`
- [ ] `test_install_omits_newsletter_subscribe` — the field is **absent**, not `0`
      (`install.php:147-151` is an `isset()`) `[NEG]`
- [ ] `test_install_omits_send_credentials_by_mail` — same reason `[NEG]`
- [ ] `test_admin_pass2_mirrors_admin_pass1` `[HAPPY]`
- [ ] `test_install_raises_when_the_marker_never_appears` — a re-rendered form with errors →
      `InstallError` carrying the scraped errors `[NEG]`
- [ ] `test_install_is_skipped_when_already_installed` `[ST]`
- [ ] `test_login_reads_the_token_from_get_status` `[HAPPY]`
- [ ] `test_login_failure_raises_remote_http_error` — bad credentials `[NEG]`
- [ ] `test_activate_plugins_posts_action_and_token_per_plugin` `[HAPPY]`
- [ ] `test_activate_skips_already_active_plugins` — from `pwg.plugins.getList` `[ST]`
- [ ] `test_activate_error_names_the_plugin` — one plugin fails, the message says which `[NEG]`
- [ ] `test_sync_posts_the_remote_sync_field_set` — the exact fields from
      `tools/remote_sync.pl:41-56` `[HAPPY]`
- [ ] `test_generated_config_contains_the_exiftool_paths` — both plugin keys, from the JSON
      `[HAPPY]`
- [ ] `test_generated_config_is_valid_php_open_tag` `[HAPPY]`
- [ ] `test_bootstrap_step_order` — install → config → login → plugins → sync, asserted on the
      fake client's call log `[ST]`

#### `tests/test_cli.py`

- [ ] `test_missing_config_argument_exits_two` `[NEG]`
- [ ] `test_each_error_type_maps_to_its_own_exit_code` — parametrised over the `DeployError`
      subclasses `[DT]`
- [ ] `test_no_bootstrap_flag_skips_the_http_phase` `[ST]`
- [ ] `test_list_files_prints_and_exits_without_connecting` `[NEG]`

### Integration Tests

**There are none, and that is a deliberate, recorded gap.** An integration test here would need an
FTPS server and a remote Piwigo, neither of which exists locally or in CI (there is no CI —
`.claude/rules/backpressure.md`: never invent a pipeline that doesn't exist). Standing up
`pyftpdlib` plus a TLS certificate was considered and rejected: it would add a runtime dependency
and a certificate to maintain in order to test an adapter that contains no decisions.

The substitute is explicit, not silent: the adapters are kept decision-free, and their behaviour is
verified once by hand and recorded in the ledger (Phase 7, item 5).

### End-to-End Tests

None. The existing Playwright suites drive the **local** gallery; pointing one at a live remote
web space would make the suite depend on an external host and on credentials no clone has. The
first real deploy is the manual acceptance run in Phase 6.

### Regression — Affected Existing Functionality

This plan changes **no PHP, no template and no plugin code**, so no existing suite is at risk. The
only shared files touched are additive:

- [ ] Root `.gitignore` — new rules only. Verify `git status --porcelain` still shows the same
      tracked set for `plugins/`, `themes/` and `galleries/` after the change
- [ ] `CLAUDE.md` — one added line; the < 100-line cap is asserted in Phase 7
- [x] `bash tools/test-hooks.sh` — the commit gate is untouched; run it to prove that. Run
      2026-08-31: the gate's two behavioural cases pass (`git rejects a real commit`, `git accepts
      a clean commit`), as do all documentation-cap cases. **4 cases fail for environment reasons
      unrelated to this phase**, each naming its own fix: `plugins/typetags` has no
      `core.hooksPath` (`tools/install-hooks.sh`), and `vendor/bin/phpunit` is absent for all
      three plugins (`composer install` per plugin). Neither is reachable from this worktree —
      DDEV is bound to the main checkout, so `ddev exec composer install` would install into the
      main checkout rather than here. Phase 2 changed no PHP, no hook and no gate, so the gate is
      untouched as claimed; the 4 failures predate it
- [ ] The three plugin suites are unaffected and are **not** re-run as part of this plan; saying so
      is more honest than running them to produce a green line that means nothing here

### Manual Testing Steps

1. Run the pre-install probe on the host (research decision 1) with an unguessable filename; record
   `exec`, `disable_functions`, `imagick`, `exif`, `gd`, `PHP_VERSION`, `memory_limit`,
   `max_execution_time`; delete the file immediately.
2. Copy `deploy.example.json` → `deploy.local.json`, fill in the real credentials.
3. `pwg-deploy --dry-run deploy.local.json` — confirm the file count and byte total look right and
   nothing dev-only appears.
4. `pwg-deploy deploy.local.json` — the full first run.
5. Open the site: the gallery renders, the four recovered albums are there, photos have thumbnails.
6. `admin.php?page=plugins` — all three fork plugins active.
7. `admin.php?page=maintenance&action=phpinfo` (decision 12) — confirm `disable_functions` from
   inside the running install.
8. Re-run `pwg-deploy deploy.local.json` — 0 transfers, install skipped, plugins skipped.
9. Touch one file locally, re-run, confirm exactly one upload.
10. Ctrl-C mid-upload on a forced full run, re-run, confirm it resumes.

### Test Commands

```bash
# Unit — the whole suite; no DDEV, no DB, no network
cd tools/deploy && uv run pytest

# One module
cd tools/deploy && uv run pytest tests/test_manifest.py

# Suite hygiene: twice in a row, each in a different order (pytest-randomly shuffles)
cd tools/deploy && uv run pytest && uv run pytest

# The commit gate (unchanged by this plan, run to prove it)
bash tools/test-hooks.sh
```

**One half of the suite-hygiene rule has no mechanical enforcement here, and that is recorded
rather than dropped.** `testing.md` asks a suite to fail on warnings *and* on risky /
assertion-free tests. `-W error` covers the first; pytest has no equivalent of PHPUnit's
`failOnRisky`, so an assertion-free test would pass silently. The substitute is review: every
test listed above names the assertion it makes, and a test with no `assert` is a review
defect (`test-design.md`, anti-vacuity).

No `php -l` run is claimed: this plan adds no PHP file. The generated
`local/config/config.inc.php` is a string built in Python and is covered by
`test_generated_config_is_valid_php_open_tag`.

## Performance Considerations

- **First deploy moves ~138 MB**, 87 MB of it `galleries/`. On a domestic uplink that is the
  dominant cost and there is nothing to optimise — it is one-time. Every later deploy matches the
  manifest and transfers only what changed (research decision 4).
- **Hashing 138 MB** with streamed sha256 costs well under a second and is done before any
  connection, so a `--dry-run` is effectively instant.
- **`site_update` scanning 106 photos** reads metadata per file; `SYNC_TIMEOUT_SECONDS = 600`
  covers it with margin. If the host's `max_execution_time` cuts it short, the sync is re-runnable
  and picks up where it left off — which is why it is the last step and idempotent.
- **Per-file manifest writes** add 3478 small local writes on a first run. Measured against the
  alternative — losing a partial 138 MB transfer — this is the cheap side of the trade.

## Migration Notes

Nothing to migrate. There is no existing deployment, no existing remote instance, and no state
this tool replaces. The first run creates everything; a lost `.state/` manifest costs one full
re-upload and nothing else.

Reversal is equally cheap: delete the remote `local/config/database.inc.php` and the remote
database, and the next run installs from scratch.

## References

- [docs/agents/research/2026-08-30-ftp-deployment-and-remote-install.md](../research/2026-08-30-ftp-deployment-and-remote-install.md)
  — every finding and all twelve decisions this plan builds on
- `docs/agents/research/2026-08-29-per-photo-freetext-field-and-metadata-writeback.md:1996-2273`
  — ALL-INKL PrivatPlus feasibility, exiftool-by-FTP, and the probe script
- `install.php:156-165, 258-433` — the install marker and the install transaction
- `include/ws_functions/pwg.php:398-407` — `pwg.session.getStatus` and `pwg_token`
- `include/ws_functions/pwg.extensions.php:53-88` — `pwg.plugins.performAction`
- `admin/include/plugins.class.php:187-219` — `activate` falls through to `install`
- `tools/remote_sync.pl:41-56` — the `site_update` field set to replay
- `tools/pwg_rel_create.sh:123-140` — empty directories and the chmod set
- `.claude/rules/testing.md`, `.claude/rules/test-design.md`, `.claude/rules/backpressure.md`
