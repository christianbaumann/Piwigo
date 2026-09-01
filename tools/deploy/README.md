# pwg-deploy — deploy this fork to an FTPS web space

Uploads this Piwigo fork to a shared web space over FTPS — only the files the install needs,
and only the ones that changed since the last run — then completes the remote install over
HTTP: `install.php`, the generated `local/config/config.inc.php`, activation of the three
fork-local plugins, and a `site_update` scan that turns the uploaded `galleries/` tree into
albums and photos.

> **The target is a sandbox instance.** This tool installs a gallery, overwrites a config file
> and deletes remote paths. It is **never** safe to point at a production install. See
> [decision 0021](../../docs/agents/decisions/0021-remote-instance-is-a-sandbox.md).

## Prerequisites

- [uv](https://docs.astral.sh/uv/) and Python 3.11+. There are no runtime dependencies —
  the tool is stdlib-only — so `uv` only fetches the interpreter and the dev test runner.
- A web space with **FTPS** (`AUTH TLS`). Plain FTP is refused, loudly: the tool would
  otherwise send the password in clear.
- A MySQL/MariaDB database on that host, not yet holding a Piwigo schema.
- The submodule checked out, or the deploy refuses to run:

  ```bash
  git submodule update --init --recursive
  ```

  `plugins/typetags` is a submodule. `git ls-files --recurse-submodules` drops an
  uninitialised one *silently*, so without this the tool would publish a gallery whose plugin
  has no code. It fails instead, naming this command.

## Set up the credential file

```bash
cp tools/deploy/deploy.example.json tools/deploy/deploy.local.json
$EDITOR tools/deploy/deploy.local.json
```

Any `deploy.*.json` other than the example is git-ignored, in the repository root and in
`tools/deploy/` alike. Nothing secret is ever committed.

| Section | Field | Meaning |
|---|---|---|
| `ftp` | `host` `user` `password` | FTPS credentials for the web space |
| | `port` | default `21` — FTPS explicit, not the implicit-TLS 990 |
| | `remote_root` | the directory the gallery is served from, e.g. `/piwigo` or `/` |
| `mysql` | `host` `user` `password` `database` | as the **host** sees them, usually `localhost` |
| | `prefix` | table prefix, default `piwigo_` |
| `admin` | `username` `password` `email` | the webmaster account `install.php` creates |
| `site` | `base_url` | where the gallery answers, no trailing slash |
| | `language` | install language, default `de_DE` |
| | `assume_https` | written into the generated config |
| | `exiftool_path` | `""` when the binary is on the host's `PATH` |

Values are validated locally against the same rules the remote enforces, so a bad table prefix
or a quote in the webmaster name fails in milliseconds instead of after a 128 MB upload. Every
rejection names the offending field.

## Deploy

```bash
cd tools/deploy
uv run pwg-deploy deploy.local.json
```

That is the whole command. It is safe to run repeatedly: each step asks the server what state
it is in before changing it, so a second run uploads only what changed, skips the install and
reports the plugins already active.

### Flags

| Flag | Effect |
|---|---|
| `--dry-run` | enumerate, hash and diff; open no socket. Reports what *would* be sent **and deleted** |
| `--list-files` | print the published file set and exit |
| `--audit` | list the remote and report what the manifest does not cover; delete nothing |
| `--no-bootstrap` | upload only; skip install, config, plugins and sync |
| `--no-prune` | never delete, not even a path the previous manifest recorded |
| `--adopt-remote-state` | upload even when the manifest and the remote disagree about the install |
| `--allow-version-change` | upload even when the remote runs a different core version |
| `--verbose` | name each uploaded path as it goes |

Exit codes are one per failure mode — `3` bad credential file, `4` git, `5` transport, `6` no
FTPS offered, `7` remote HTTP, `8` `install.php` refused, `9` manifest and remote disagree,
`10` core versions differ — and `130` for Ctrl-C.

Interrupting is safe. The manifest records completed uploads only, so re-running the same
command resumes from where it stopped rather than starting over.

## What gets uploaded, and what does not

The file set is `git ls-files --recurse-submodules` minus everything the remote does not need.
Excluded: `docs/`, `.claude/`, `.githooks/`, `.ddev/`, `local/config/`, the whole of `tools/`
([decision 0022](../../docs/agents/decisions/0022-the-tools-directory-is-not-published.md)),
any `tests/` directory at any depth, and each plugin's dev-tooling manifests
(`composer.json`, `package.json`, `phpunit.xml`, `playwright.config.js`, …).

Two deliberate exceptions:

- `local/**/index.php` **is** published — those are the directory-listing guards. Without them
  the server offers an index of the directory holding the database credentials.
- `themes/default/vendor/fontello/` **is** published. It is a tracked core asset, the gallery's
  icon font, not dev tooling; the exclusion matches each plugin's own `vendor/`, never a bare
  `vendor/`.

`local/config/config.inc.php` is *generated* from the credential file after the install, not
copied from this checkout. `local/config/database.inc.php` is written by `install.php` on the
server and never leaves this machine.

`upload/` and `_data/` are created empty and are server-authoritative — the deploy never writes
into them and the prune never touches them.

No database content is transferred, in either direction; the remote's albums and photos are
re-created from the uploaded files by the `site_update` scan. See
[decision 0023](../../docs/agents/decisions/0023-no-database-transfer-to-the-remote.md).

## The manifest is the only record of remote state

Each target gets one manifest under `tools/deploy/.state/`, keyed by host and remote root. It
holds a hash per uploaded path, and it is the **only** thing the tool consults to decide what
to send — by design, the server is never read back.

> **Wiping the remote means deleting that target's manifest.** If the web space is emptied by
> any means other than this tool's own prune, the manifest becomes a lie — it describes a server
> holding none of those files. The next run **refuses**, naming the manifest file to delete.

Before uploading anything, every run that opens a connection asks `install.php` whether the
gallery is installed and compares that answer against the manifest. The two disagree in exactly
two ways, and both abort with exit `9`:

| Manifest | Remote | Verdict |
|---|---|---|
| empty | not installed | a first run — proceed |
| has entries | installed | an update run — proceed |
| has entries | not installed | the remote was emptied behind the tool's back; delete the manifest |
| empty | installed | no local state for an installed server; anything it holds that this run does not send becomes an orphan no later run can reach |

`--adopt-remote-state` turns either refusal into a warning and uploads anyway. The guard is
skipped on `--dry-run`, which opens no connection at all, and the report says so. See
[decision 0027](../../docs/agents/decisions/0027-manifest-and-remote-must-agree-on-installation.md).

Reading only the manifest is also what makes the prune safe. It only ever considers paths the
previous manifest recorded, which is why it cannot touch `upload/`, `_data/`, or anything a
person put on the server by hand — and equally why it can never clean up after anything that
bypassed it. A file dropped from the manifest while still on the server is an orphan no future
run can reach; it has to be deleted over FTP by hand. `--audit` is how you find them.

## The remote's core version has to match this checkout

The same preflight asks an installed gallery for its `PHPWG_VERSION` (`pwg.getVersion`) and
compares it against `include/constants.php` in this working copy. Any difference — in either
direction — aborts with exit `10`:

```
VersionError: local PHPWG_VERSION is 17.1.0, the remote reports 17.0.0beta1. Uploading would
put core PHP on a schema this tool did not migrate; it does not run upgrade.php. Run
upgrade.php on the remote yourself, or pass --allow-version-change.
```

The comparison is exact string equality, never an ordering: `17.0.0beta1` is not a semver, and
this tool must not decide which of two versions is newer. **It never migrates.** No database
content moves in either direction and nothing here posts to `upgrade.php` — run that yourself,
in a browser, then re-run the deploy. `--allow-version-change` turns the refusal into a warning
for the case where you know the schema is already right. See
[decision 0028](../../docs/agents/decisions/0028-core-version-is-detected-never-migrated.md).

## `--audit` — what the server actually holds

The one mode that reads the server back. It lists the remote tree, compares it against the
manifest, and prints three buckets. It uploads nothing, installs nothing and **deletes
nothing** — removing an orphan stays a hand operation over FTP.

```
$ uv run pwg-deploy --audit deploy.local.json
Piwigo deploy -> bilder.example.de:/
  manifest    /…/.state/bilder.example.de_root.json (existing, 3309 entries)
  transport   FTPS to bilder.example.de:21
  listed      3411 files in 402 directories (skipped: _data/ upload/)
  covered     3308 files the manifest records and the server holds
  orphans     103 files on the server the manifest does not cover:
              plugins/removed_plugin/main.inc.php
              … and 83 more
  missing     1 file the manifest records and the server does not hold:
              themes/modus/theme.css
  This is a read-only report. Nothing was deleted.
```

Each bucket names at most 20 paths and summarises the rest as `… and N more`; the block above
is trimmed to one name per bucket for length, which is why 103 orphans show a single line.

- **orphans** are what no run can reach: prune only considers paths the previous manifest
  recorded. `local/config/database.inc.php` is always among them — `install.php` wrote it on
  the server and it never leaves this machine — and that is correct, not a fault.
- **missing** means the manifest claims a file the server does not hold, so the next deploy
  would call it unchanged and never send it. Delete the manifest, or pass
  `--adopt-remote-state`, to force a full re-upload.
- `upload/` and `_data/` are **not** walked. They are server-authoritative, the manifest
  records nothing under either, and listing them would bury the real orphans under thousands
  of files that are not orphans. The report names what it skipped.

It needs `MLSD`, and says so loudly if the server does not offer it; there is no `NLST`
fallback, because `NLST` cannot tell a file from a directory. Empty directories are never
removed by anything in this tool
([decision 0029](../../docs/agents/decisions/0029-empty-remote-directories-are-never-removed.md)).
See [decision 0030](../../docs/agents/decisions/0030-the-audit-is-read-only-and-exists-stays-size-based.md).

### The one prune worth reading twice

The four recovered album directories under `galleries/` are tracked, published, and therefore
prune-eligible like any other file: deleting a scan from the working copy deletes it from the
server on the next run. That is intended — the working copy is the source of truth for the
published gallery, and no database content is transferred either way. What is different is the
cost of a mistake. Every other published path is a copy of something git holds and comes back on
the next run; a wrongly pruned scan does not come back at all.

So the prune names them. Whenever a run would delete — or did delete — a path under
`galleries/`, the report carries an extra line listing the photos by name:

```
  upload      0 new, 0 changed, 3375 unchanged, 3 removed (would send)
  galleries   1 of the 3 would delete a tracked photo:
              galleries/1992_Rund_um_Sefferweich/img_0421.png
```

It appears on `--dry-run` as a prediction and on a real run as a report, and never under
`--no-prune`. Its absence is the signal that nothing irreplaceable is at stake. See
[decision 0026](../../docs/agents/decisions/0026-tracked-gallery-photos-are-prune-eligible.md).

## Testing

```bash
cd tools/deploy && uv run pytest
```

404 tests, measured 2026-09-01. Everything that decides *what* to do is a pure function and is
unit-tested; the two adapters that cannot run without the world — FTPS and the remote HTTP
endpoint — hold no decisions and are covered by hand checks recorded in
[`docs/agents/TESTING.md`](../../docs/agents/TESTING.md).
