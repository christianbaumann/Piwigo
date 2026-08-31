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
| `--no-bootstrap` | upload only; skip install, config, plugins and sync |
| `--no-prune` | never delete, not even a path the previous manifest recorded |
| `--verbose` | name each uploaded path as it goes |

Exit codes are one per failure mode — `3` bad credential file, `4` git, `5` transport, `6` no
FTPS offered, `7` remote HTTP, `8` `install.php` refused — and `130` for Ctrl-C.

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
> any means other than this tool's own prune, the manifest becomes a lie: the next run reports
> `0 new, 0 changed`, uploads nothing, and leaves the site broken.

The same asymmetry is what makes prune safe. It only ever considers paths the previous manifest
recorded, which is why it cannot touch `upload/`, `_data/`, or anything a person put on the
server by hand — and equally why it can never clean up after anything that bypassed it. A file
dropped from the manifest while still on the server is an orphan no future run can reach; it
has to be deleted over FTP by hand.

## Testing

```bash
cd tools/deploy && uv run pytest
```

288 tests, measured 2026-08-31. Everything that decides *what* to do is a pure function and is
unit-tested; the two adapters that cannot run without the world — FTPS and the remote HTTP
endpoint — hold no decisions and are covered by hand checks recorded in
[`docs/agents/TESTING.md`](../../docs/agents/TESTING.md).
