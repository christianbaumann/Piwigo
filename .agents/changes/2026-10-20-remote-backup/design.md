---
datetime: 2026-10-20T10:03:38+02:00
author: Christian Baumann
tags: [backup, deploy, remote, all-inkl, tools]
---

# Remote backup: DB, photos and config

A backup tool for the remote gallery on all-inkl.com. It pulls snapshots to the Mac, runs before
every deploy, and can restore a snapshot to the remote. Agreed in a brainstorm on 2026-10-20
(git `1521e4e3b`).

## Why now

[Decision 0021](../../../docs/agents/decisions/0021-remote-instance-is-a-sandbox.md) calls the
remote a disposable sandbox, and
[decision 0023](../../../docs/agents/decisions/0023-no-database-transfer-to-the-remote.md) moves no
database in either direction. Both name what reverses them: the remote holding state the
repository cannot rebuild. That is now the case:

| Remote-only state | Source |
|---|---|
| photos uploaded through the remote's screens, in `upload/` | core upload |
| edits to photo files and their backups in `_data/photoedit/originals/` | [decision 0087](../../../docs/agents/decisions/0087-photoedit-is-active-on-the-remote.md) |
| albums created remotely | `pwg-albums` (`tools/deploy/pwgdeploy/albums.py`) |
| provenance values, users, comments, tag assignments, album permissions | database only |
| regions, dates, info texts written into remote `galleries/` files | persons, photoinfo |

A deploy prune can delete `galleries/` files
([decision 0026](../../../docs/agents/decisions/0026-tracked-gallery-photos-are-prune-eligible.md)),
and a deploy overwrites a remotely edited `galleries/` file without a warning (0087). Nothing today
can put any of this back.

## Overview

```
          Mac                                         all-inkl web space
 ┌───────────────────────────┐                ┌─────────────────────────────────┐
 │ pwg-backup                │ 1 FTPS put     │ <random>.php  (temporary,       │
 │  (tools/deploy)           │ ─────────────▶ │   token-gated, deleted in       │
 │                           │ 2 HTTPS        │   a finally)                    │
 │                           │ ─────────────▶ │   ├─ mysqldump / PHP dumper ──▶ │──▶ MariaDB
 │                           │ ◀── JSON ───── │   └─ file list: path,           │
 │                           │                │        filemtime, filesize      │
 │                           │ 3 FTPS get     │ dump.sql.gz (outside web root)  │
 │                           │ ◀───────────── │ upload/ galleries/              │
 │                           │   only changed │ _data/photoedit/originals/      │
 │                           │   files        │ local/config/*.inc.php          │
 │ ~/Backups/piwigo-remote/  │ 4 FTPS delete  │                                 │
 │  2026-10-20T1003/  ◀──┐   │ ─────────────▶ │ script + dump removed           │
 │  2026-10-19T0300/ ────┘   │                └─────────────────────────────────┘
 │   unchanged photo =       │
 │   hardlink to previous    │
 └───────────────────────────┘
```

One run:

1. Upload the temporary script under a random name, with a one-time token.
2. Ask it for the DB dump, then for the file list.
3. Diff the list against the last snapshot. Fetch the dump and the changed files over FTPS.
   Hardlink the unchanged ones from the previous snapshot.
4. Delete the script and the dump. Rename `.partial` to the final snapshot name. Apply retention.

## Snapshot layout

```
~/Backups/piwigo-remote/                 path set in the credential file
├── 2026-10-19T030000/                   daily
├── 2026-10-20T100338-predeploy/         taken by pwg-deploy
│   ├── db/dump.sql.gz
│   ├── files/upload/...                 hardlinks where unchanged
│   ├── files/galleries/...
│   ├── files/_data/photoedit/originals/...
│   ├── files/local/config/config.inc.php, database.inc.php
│   ├── deploy-manifest.json             copy of tools/deploy/.state/<target>.json
│   └── snapshot.json                    git commit deployed, timestamps, per-file
│                                        size + mtime + sha1, drift report
├── 2026-10-20T110000.partial/           unfinished run, resumed next time
└── backup.log
```

Not in a snapshot: `_data/i`, `_data/templates_c`, `_data/combined` (all can be rebuilt), and the
code (git has it; `snapshot.json` records the commit).

## Key Decisions

### Purpose: disaster recovery, plus a snapshot before every deploy

- **Decision:** Snapshots are pulled to the Mac to rebuild the remote after host loss, a bad prune
  or a wrong delete. `pwg-deploy` takes one first and refuses to deploy when it fails, with a new
  exit code. `--no-backup` skips it explicitly.
- **Reason:** The deploy is the most likely way to destroy remote state. A backup taken right
  before it covers that case.
- **Trade-offs:** Rejected: a remote→local mirror into DDEV (user rows, hashes, config paths and
  ids all differ, per 0023). Rejected: a failed backup that only warns.

### DB channel: a temporary PHP script over FTPS and HTTPS

- **Decision:** The host has no SSH. The tool uploads a script under a random name. The script
  accepts only a one-time token within a short time window, and the tool deletes it in a
  `finally`. It dumps with `exec('mysqldump --single-transaction ...')`, using the credentials in
  `local/config/database.inc.php`. Without `mysqldump` it falls back to a pure-PHP dumper (mysqli,
  `SHOW CREATE TABLE` plus batched `INSERT`s), and the report names the path that ran.
- **Reason:** It is the only automatable path without SSH, and it follows the pre-install probe
  pattern in [deployment.md](../../../.claude/rules/deployment.md#the-manual-host-probes).
  `exec()` is enabled on the host.
- **Trade-offs:** For the length of a run, a script that can dump the database sits on a public
  host. That is the risk 0023 named, now accepted with mitigations: random name, token, short
  window, deletion. `--audit` reports a leftover script. Rejected: a permanent webmaster ws method
  (a standing attack surface), KAS backups (no backup before a deploy, restore only by hand),
  phpMyAdmin (manual).

### Where the dump is written

- **Decision:** Outside the web root, if the FTP account reaches such a directory. Otherwise in a
  random-named directory with a deny-all `.htaccess`. Deleted after download.
- **Reason:** A dump at a URL anyone can request is a leak, even for seconds.
- **Trade-offs:** Rejected: streaming the dump in the HTTP response. It writes nothing to disk, but
  a large DB can hit `max_execution_time` mid-stream.

### Change detection: an exact file list from the script

- **Decision:** The same script returns `upload/`, `galleries/` and
  `_data/photoedit/originals/` as JSON with exact `filemtime` and `filesize`. A file counts as
  changed when either value differs from the last snapshot. FTPS fetches only the changed files.
  The tool checks each downloaded file's size and records a local `sha1` in `snapshot.json`.
- **Reason:** It is exact to the second and does not depend on how the host formats `MLSD`.
- **Trade-offs:** A file rewritten within the same second with the same size is missed; this is
  accepted. Rejected: `MLSD` only (precision unknown), a remote `sha1` per file (reads the whole
  gallery on the host every run), size only (misses a turn that keeps the byte count).

### Incremental storage: hardlinked snapshot folders

- **Decision:** Each snapshot is a complete, timestamped tree. A photo unchanged since the
  previous snapshot is a hardlink to that copy, as with `rsync --link-dest`. A full copy is
  never stored twice.
- **Reason:** It is the user's requirement. Every snapshot is browsable and restorable on its own,
  any snapshot can be deleted safely, and the stdlib is enough (`os.link`).
- **Trade-offs:** No dedupe below file level: an edited photo is stored again in full. The backup
  folder must sit on one file system (APFS). Rejected: restic (dependency, needs a staging mirror
  because it cannot read FTP), a mirror plus change folders (restore must replay history).

### Resumable first run

- **Decision:** A run writes into `<timestamp>.partial/`. A later run continues from the newest
  `.partial` and renames the folder only once it is complete. Retention and the pre-deploy check
  ignore `.partial` folders.
- **Reason:** The first run fetches the whole gallery over FTPS and may not finish in one go.
- **Trade-offs:** One more state to handle. Rejected: seeding the first snapshot from the local
  `galleries/` (the remote's copies can differ, per 0087).

### Contents

- **Decision:** DB dump, `upload/`, `galleries/`, `_data/photoedit/originals/`,
  `local/config/config.inc.php` and `database.inc.php`, the deploy manifest, and the deployed git
  commit.
- **Reason:** This is everything that cannot be rebuilt. The manifest is needed so a deploy after
  a restore agrees with the remote
  ([decision 0027](../../../docs/agents/decisions/0027-manifest-and-remote-must-agree-on-installation.md)).
- **Trade-offs:** Derivatives and compiled templates are left out; after a restore they get
  rebuilt on demand. Rejected: all of `_data/`, the whole web root.

### Storage location, no encryption

- **Decision:** A folder outside the repository, set in the credential file. No encryption at all,
  on the Mac or on any offsite copy (the user's explicit choice).
- **Reason:** Plain files keep every snapshot browsable and restorable with no extra tool.
- **Trade-offs:** The dump holds password hashes and e-mail addresses, and the photos are scans of
  identifiable private people. Only the location of the folder protects them. Never inside the
  repository, not even git-ignored.

### Offsite copy: out of scope for v1

- **Decision:** A how-to in the README (for example, copying the backup folder to an external disk
  or to cloud storage) and a `docs/backlog.md` entry.
- **Reason:** It keeps v1 small. The Mac copy already covers the loss of the host.
- **Trade-offs:** Losing the Mac loses the backups until the offsite step exists.

### Consistency between DB and files

- **Decision:** DB first, then files. Both timestamps are recorded. A file with no DB row, or the
  reverse, is reported as drift. No maintenance mode.
- **Reason:** The gallery has little traffic, and visitors are not locked out.
- **Trade-offs:** A photo uploaded during the run can be in the files but not in the dump.
  Rejected: `gallery_locked` for the run.

### Schedule and failure reporting

- **Decision:** Manual runs, a run before every deploy, and a daily `launchd` job on the Mac.
  Every run appends to `backup.log`. A failed run also sends a mail, through the credential file's
  `mail` section (stdlib `smtplib`).
- **Reason:** A scheduled run that fails silently is the same as no backup.
- **Trade-offs:** The daily run happens only while the Mac is awake; `launchd` catches up after
  sleep. Rejected: a cron job on the host (the backup would sit on the machine it protects).

### Retention

- **Decision:** Pre-deploy snapshots are kept 90 days, daily ones 14 days. After that, one per
  week for 6 months, then one per month. The tool deletes only its own snapshot folders and never
  the newest complete one.
- **Reason:** Hardlinks make old snapshots cheap. Pre-deploy snapshots are the restore points that
  matter most.
- **Trade-offs:** The disk grows with every edited photo. The retention rule is a pure function
  and gets unit-tested.

### Restore

- **Decision:** `pwg-backup --restore <snapshot> --yes` first takes a fresh snapshot, then uploads
  the files over FTPS and imports the dump through the same kind of temporary, token-gated script.
  It also writes the snapshot's deploy manifest back to `tools/deploy/.state/`. A periodic restore
  check (`pwg-backup --check`) imports the newest dump into a separate DDEV database, never `db`
  (that would overwrite the local gallery), and compares row counts with `snapshot.json`.
- **Reason:** A backup that has never been restored has not been tested.
- **Trade-offs:** The restore script executes uploaded SQL on the host, which is exactly the
  pattern 0023 rejected. Accepted because only `--restore --yes` uploads it and it lives only for
  the run. Rejected: a manual restore only (phpMyAdmin import of a large dump is fragile).

### Code structure

- **Decision:** A new entry point `pwg-backup` in `tools/deploy`, stdlib only, reusing `config.py`,
  `FtplibTransport` (`transport.py`) and `UrllibClient` (`http.py`). Every decision is a pure
  function, unit-tested with `uv run pytest`: the diff against the last snapshot, the snapshot
  plan, retention, drift. The adapters make no decisions, like the rest of the tool
  ([deployment.md](../../../.claude/rules/deployment.md#what-has-no-local-test-double)).
- **Reason:** Same credential file, same transports, same test style. `pwg-deploy` calls it
  in-process for the pre-deploy snapshot.
- **Trade-offs:** `tools/deploy` grows. The PHP script is a template inside the package, rendered
  per run with its token, and it never ships with the deploy (`tools/` is not published,
  [decision 0022](../../../docs/agents/decisions/0022-the-tools-directory-is-not-published.md)).

## Interfaces

Fixed before the tasks were split, so tasks 02 to 05 and 10 can be built in parallel against them.

```
tools/deploy/pwgdeploy/
├── agent.php          NEW  the temporary PHP script (template; token hash, expiry, dump dir filled in per run)
├── agent.py           NEW  render(token, expiry, dump_dir) -> str
│                           AgentClient: dump(), list_files(), import_dump(path), cleanup()
├── snapshot.py        NEW  pure + local fs: diff(), build() with hardlinks, .partial resume, snapshot.json
├── retention.py       NEW  pure: select_for_deletion(snapshots, now) -> list[name]
├── backup.py          NEW  orchestration: upload agent -> dump -> list -> diff -> fetch -> build -> cleanup
├── backup_cli.py      NEW  `pwg-backup` (backup, --restore, --check)
├── notify.py          NEW  log line + failure mail (smtplib, config.mail)
├── transport.py       +    Transport.get(remote_path, local)
├── config.py          +    optional `backup` section: {"dir": "~/Backups/piwigo-remote"}
└── cli.py             +    pwg-deploy runs the backup first; --no-backup; new exit code
```

### Agent protocol

POST to `<base_url>/<random>.php`, form fields `action` and `token`. The script stores only the
token's sha256 and an expiry time, and answers every request after expiry with 403. Answers are
JSON:

| `action` | Extra fields | Answer |
|---|---|---|
| `dump` | none | `{"path": "<FTP-relative>", "size": n, "method": "mysqldump" \| "php", "counts": {"images": n, "categories": n, "tags": n, "users": n}}` |
| `list` | none | `{"files": [{"path": "upload/...", "size": n, "mtime": t}, ...], "db_paths": ["upload/...", ...]}`: `files` over `upload/`, `galleries/`, `_data/photoedit/originals/`; `db_paths` is every `images.path`, for the drift report |
| `import` | `path` (a dump uploaded over FTPS) | `{"statements": n}` |
| `cleanup` | none | `{"removed": [...]}`: the dump, the dump dir if the script made it, then the script itself |

A refusal or failure answers a non-200 status with `{"error": "<message>"}`.

### `snapshot.py`

```python
RemoteFile(path: str, size: int, mtime: int)
Entry(path: str, size: int, mtime: int, sha1: str)          # one row of snapshot.json "files"
diff(previous: dict[str, Entry] | None, remote: list[RemoteFile]) -> Plan(fetch, link, gone)
build(root: Path, name: str, plan: Plan, fetch: Callable[[str, Path], None]) -> Path
```

`snapshot.json` carries `files` (path -> Entry), `git_commit`, `db_started`, `files_started`,
`finished`, `kind` (`daily` | `manual` | `predeploy` | `prerestore`), `dump_method`, `drift`, and
`counts` (the dump answer's row counts, the oracle of the restore check).

## Documents to change

- New decisions: a successor to 0021 (the remote is now the real gallery) and to 0023 (DB export
  and restore through a temporary script). Revisit 0026, whose reversal condition this backup
  meets.
- `tools/deploy/README.md`: the sandbox warning, `pwg-backup` usage, `launchd` setup, the offsite
  how-to.
- `.claude/rules/deployment.md`: the pre-deploy backup, the new exit code, the temporary-script
  risk.
- `docs/agents/TESTING.md`: hand checks with no local test double (the remote script, FTPS
  download, mail).

## Before building: one host probe by hand

Same shape as the pre-install probe in
[deployment.md](../../../.claude/rules/deployment.md#the-manual-host-probes). It answers:

- Is `mysqldump` on the host's `PATH`, and does it run under `exec()`?
- Does the FTP account reach a directory outside the web root that PHP can write to?
- What are `max_execution_time` and `memory_limit` (needed to size the PHP dumper's batches)?
- What size is the DB, and the whole of `upload/` + `galleries/` + `originals/` (first-run time)?

## Decisions taken during implementation (2026-10-20)

Answered by the user after tasks 01-10 were built:

| Question | Decision |
|---|---|
| Host probe (task 01) | Run by a subagent once the user allows it to read `tools/deploy/deploy.local.json` |
| Dump directory | A setting in the credential file (`backup` section); the default stays a random-named directory under the web root with a deny-all `.htaccess` |
| Retention of `manual` and `prerestore` | `manual` like `daily` (14 days), `prerestore` like `predeploy` (90 days) |
| Exit codes | Separate codes for a deploy stopped by its predeploy snapshot and for a failed restore, instead of reusing `11` |
| New mtimes after a restore | Accepted: the next backup downloads every restored file once more |
| Size mismatch during a fetch | Accepted: the run aborts and the next run retries |
| `test_albums_ddev` flake | Fixed: the test picks the newest report file |
