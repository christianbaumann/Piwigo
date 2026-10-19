---
datetime: 2026-10-19T12:30:24Z
author: Christian Baumann
tags: [tools-deploy, albums, remote, ws-api]
---

# Create a local folder tree as albums on the remote

A local command takes a folder on the local disk and creates it, with all sub-folders, as an
album tree on a Piwigo install. It creates **albums only**. Photos reach the remote some other way.

```bash
cd tools/deploy
uv run pwg-albums [--dry-run] [--parent <id>] deploy.<target>.json <folder>
```

Like `pwg-deploy`, it is never safe against a production install
([decision 0021](../../../docs/agents/decisions/0021-remote-instance-is-a-sandbox.md)).

## Example

```
~/Scans/Familie_1970/            remote before          remote after
├── Urlaub/                      (root)                 (root)
│   └── Italien/                 └── Verschiedenes      ├── Verschiedenes
└── Hochzeit/                                           └── Familie_1970     created
                                                            ├── Urlaub       created
                                                            │   └── Italien  created
                                                            └── Hochzeit     created
```

Run it again after `Familie_1970` exists, and nothing happens: the top album is skipped with
everything below it.

## Flow

```
local folder ──walk──► local tree ─┐
                                   ├─► plan() ──► [dry-run: print, stop]
remote ──getList recursive──► remote tree ─┘        │
                                                     ▼
                                       pwg.categories.add, top-down
                                                     │
                                                     ▼
                       .state/albums-<timestamp>.json + summary line on the terminal
```

1. Check: `<folder>` is a directory. Log in (`bootstrap.login()`).
2. Walk the local folder: sub-folders only, hidden ones (`.*`) skipped, symlinks not followed,
   names in Unicode NFC.
3. Read the remote tree: one `pwg.categories.getList` call with `recursive=true`.
4. `plan()` (pure): one action per local folder, top-down.
5. With `--dry-run`, print the plan and stop. Otherwise create each album with
   `pwg.categories.add` (`name`, `parent`).
6. Write the report, print the summary line, the errors and the report's path.

## Reuse

Everything the command needs to reach the remote already exists in `tools/deploy/pwgdeploy/`:

| Need | Existing code |
|---|---|
| credential file, validation | `config.py` |
| HTTP with session cookie | `http.UrllibClient` |
| login | `bootstrap.login()` |
| ws call, server error message passed through | `bootstrap.ws_call()` |
| name comparison as MariaDB does it | `seed.collated()` |

New: one module (walk, `plan()`, create, report) and one entry in `[project.scripts]` of
`tools/deploy/pyproject.toml`, next to `pwg-deploy`.

## Key Decisions

### Albums only, no photos

- **Decision:** The command creates the album tree and nothing else.
- **Reason:** That is its whole job. How photos reach the remote is a separate concern.
- **Trade-offs:** Rejected: uploading through `pwg.images.upload`, copying over FTPS into
  `galleries/` with a `site_update` scan, and copying into the local `galleries/` with a deploy.

### Through the ws API, as a second entry point in `tools/deploy`

- **Decision:** `pwg-albums`, a new console script in the `tools/deploy` package, calling
  `pwg.categories.add` (admin only, `ws.php`).
- **Reason:** Config, login and ws calls exist there already. A separate script would copy them,
  and a mode flag in `pwg-deploy` would add to `cli.py`'s mode conflict lists.
- **Trade-offs:** Albums are virtual (not tied to a `galleries/` folder) and exist only on the
  target install; the database never travels
  ([decision 0023](../../../docs/agents/decisions/0023-no-database-transfer-to-the-remote.md)).

### Target: root, or `--parent <id>`

- **Decision:** The folder becomes an album at the remote root, or under the album
  `--parent <id>` names.
- **Reason:** The root covers the normal case. `--parent` places a tree inside an existing album.
- **Trade-offs:** None worth noting.

### An existing album is skipped with everything below it

- **Decision:** An album exists when one with the same parent and a name equal under
  `seed.collated()` exists. It is skipped, and its sub-folders are not looked at. The first
  match counts.
- **Reason:** Simple to predict. A re-run never adds to an album that is already there.
- **Trade-offs:** A new sub-folder inside an existing album is never created; create it by hand.
  `seed.collated()` also ignores accents, so `Muller` counts as an existing `Müller`. Rejected:
  skipping only the album itself and going down into it.

### Album name and folder selection

- **Decision:** The name is the folder name in NFC. Every sub-folder counts, empty ones too.
  Hidden folders are skipped, and symlinks are not followed. New albums get core's defaults
  (public, visible, default position).
- **Reason:** macOS can give names in NFD. Core's `get_tag_ids()` fix exists for the same
  problem with tags ([fork-local-core-edits.md](../../../.claude/rules/fork-local-core-edits.md)).
  An empty album is hidden from visitors anyway (galleryrules, `events_empty_album.inc.php`).
- **Trade-offs:** No flags for status or visibility. Two sibling folders that differ only in
  case: the second is found as existing and skipped.

### Few checks, and the server's own refusals

- **Decision:** Before the run: `<folder>` is a directory, and the login works. Everything else
  (non-admin account, blank or too-long name) is left to the server, whose message
  `ws_call()` passes through.
- **Reason:** Not overengineering this. The server already gives a precise refusal.
- **Trade-offs:** Rejected: an admin check through `pwg.session.getStatus`, name length checks,
  checks for colliding sibling or ambiguous remote names, collecting every problem first.

### `--dry-run` reads the remote

- **Decision:** `--dry-run` logs in, reads the remote tree and prints the plan, but creates
  nothing.
- **Reason:** The plan depends on what exists remotely; an offline dry run could not tell
  `create` from `skip`.
- **Trade-offs:** A dry run needs the remote to answer.

### On an error, go on

- **Decision:** A failed `pwg.categories.add` is recorded and the run goes on. Its children are
  recorded as `skipped (parent failed)`. Exit code ≠ 0.
- **Reason:** One bad folder name should not stop the rest of the tree.
- **Trade-offs:** With the skip rule, a re-run does not repair a failed sub-album when its top
  album was created: create it by hand, or delete the top album and re-run. Rejected: deleting
  what the run created.

### A JSON report in `.state/`

- **Decision:** `tools/deploy/.state/albums-<timestamp>.json`, one entry per album with its
  path, album id (if any), and status: `created`, `skipped (exists)`,
  `skipped (parent failed)`, `failed` (with the server's message), `would create`. The terminal
  shows a summary line, the errors and the report's path.
- **Reason:** A long tree does not fit on a screen. `.state/` is already git-ignored
  (`.gitignore:48`).
- **Trade-offs:** Report files accumulate in `.state/`; delete them by hand.

### Tests: unit for `plan()`, one integration test against DDEV

- **Decision:** pytest unit tests for `plan()`: a new album, an existing one, an existing one
  with children, a failed parent, `--parent`. One integration test against DDEV with
  `deploy.ddev.json` that creates a tree under a uniquely named root album, checks it and
  deletes it. It fails naming the file when that file is missing; it is never skipped.
- **Reason:** `plan()` holds all decisions. The integration test covers the adapters and the
  real server's answers.
- **Trade-offs:** No adapter tests with a fake client; the integration test covers them.
  The remote host itself is not tested.

### Docs: a README section

- **Decision:** A section in [tools/deploy/README.md](../../../tools/deploy/README.md).
- **Reason:** That is where an operator looks for the deploy tool's commands.
- **Trade-offs:** No entry in `.claude/rules/deployment.md`, no decision file.

## Related

- [tools/deploy/README.md](../../../tools/deploy/README.md) — the deploy tool
- [.claude/rules/deployment.md](../../../.claude/rules/deployment.md) — deploy context for agents
- `ws.php` — `pwg.categories.add`, `pwg.categories.getList`
- [Piwigo web API](https://github.com/Piwigo/Piwigo/wiki/Piwigo-Web-API)
