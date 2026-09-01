# Deployment

Read before changing `tools/deploy` or deploying this fork to the web space. The how-to for an
operator is [`tools/deploy/README.md`](../../tools/deploy/README.md) — this file carries what an
agent needs that the how-to does not: where the decisions live, what the exclusion list means,
and the traps that cost a session.

## The command

```bash
cd tools/deploy
uv run pwg-deploy deploy.local.json          # upload + install + plugins + sync
uv run pwg-deploy --dry-run deploy.local.json    # opens no socket; predicts deletions too
uv run pwg-deploy --list-files deploy.local.json # the published file set, one path per line
uv run pwg-deploy --audit deploy.local.json      # read-only: lists the remote, names orphans
uv run pytest                                    # 404 tests, measured 2026-09-01
```

Stdlib-only at runtime; `uv` fetches the interpreter and pytest and nothing else. The tool works
from any directory — it resolves the repository root from its own file — but the credential path
is taken as given, so `cd tools/deploy` first is the shortest correct form.

**The target is a sandbox and this tool is never safe against a production install**
([decision 0021](../../docs/agents/decisions/0021-remote-instance-is-a-sandbox.md)). It prunes
without a prompt, overwrites the remote config, and posts to `install.php` unauthenticated.

## The credential file

Not committed. `deploy.example.json` is the committed structure; a real one is any other
`deploy.*.json`, git-ignored both in the repository root and in `tools/deploy/`. The guard is
`tools/deploy/tests/test_gitignore.py`, which is the only thing that would notice a `.gitignore`
rewrite dropping those rules — nothing else in the tool sees a leak until it is in a pushed
commit.

Every value is validated locally against the rule the remote enforces (`config.py` cites the
`install.php` line for each), so a bad table prefix fails in milliseconds rather than after a
128 MB upload. Validation lives there and nowhere else.

**Validation checks "non-empty string", not "you actually edited this field".** Every password in
`deploy.example.json` is the literal placeholder `REPLACE_ME`, and `_required_string()` accepts it
unchanged — nothing catches a forgotten `admin.password` before it is submitted to `install.php`
as the real webmaster password. Confirmed 2026-09-01: a deploy ran clean with `admin.password`
still `REPLACE_ME`, and that literal string became the working login. Diff every field in a new
`deploy.*.json` against the example before the first run against a given target.

## What is published

`git ls-files --recurse-submodules`, minus `EXCLUDED_PREFIXES` / `EXCLUDED_BASENAMES` /
`EXCLUDED_DIR_NAMES` in `pwgdeploy/fileset.py`. That module is the single source of truth; this
file names only what needs a reason:

- **`tools/` in full** — core loads nothing from it at runtime
  ([decision 0022](../../docs/agents/decisions/0022-the-tools-directory-is-not-published.md)).
- **`handbuch/` ships, `handbuch/tools/` does not** — the German handbook is application content
  since [decision 0025](../../docs/agents/decisions/0025-handbuch-moves-into-the-application-tree.md);
  its generator/checker scripts are dev tooling and stay off the web space, same as `tools/`.
- **`local/config/`, but the `index.php` inside it survives** — those are the directory-listing
  guards. Without them the server offers an index of the directory holding the DB credentials.
- **each plugin's `vendor/`, never a bare `vendor/`** — `themes/default/vendor/fontello/` is a
  tracked core asset, the gallery's icon font, and must ship.
- **`tests/` as a path segment, not a substring** — a file named `tests.js` stays.

`local/config/config.inc.php` is *generated* from the credential file by `bootstrap.py`, never
uploaded from this checkout, and is named once in `fileset.GENERATED_REMOTE_PATHS` so the diff
hides it from all four buckets. A generated path missing from that tuple looks `removed` on every
run after the first.

An **uninitialised submodule is dropped silently** by `--recurse-submodules`, so `fileset` refuses
an enumeration below `MIN_EXPECTED_PATHS` and refuses one where a `.gitmodules` submodule
contributed no files. A partial deploy is worse than a failed one: it succeeds, and the missing
code is found by whoever visits the broken page.

## The manifest is the only record of remote state

One JSON per target under `tools/deploy/.state/`, keyed by host and remote root. The tool never
reads the server back to decide what to send.

- **Wiping the remote means deleting that target's manifest.** Since
  [decision 0027](../../docs/agents/decisions/0027-manifest-and-remote-must-agree-on-installation.md)
  that is a guard rather than a trap: `preflight.check_state()` compares the manifest's entry
  count against what `install.php` says *before* the upload, and refuses with exit `9` in both
  directions — a recorded manifest over a blank remote (the old `0 new, 0 changed` over a broken
  site), and no manifest at all over an installed one. `--adopt-remote-state` overrides either;
  `--dry-run` skips the check and says so, because it opens no connection.
- **Prune only ever considers what the previous manifest recorded.** That is what makes it safe
  for `upload/` and `_data/`, and exactly why it can never clean up after anything that bypassed
  it. A path dropped from the manifest while still on the server is an orphan no run can reach.
  `--audit` is the only thing that can *see* one — it lists the remote over `MLSD`, compares it
  against the manifest and prints orphans and missing files by name — but it deletes nothing,
  and neither does anything else here: removing an orphan is a hand operation over FTP, and no
  code path issues `RMD`, so pruning a plugin's last file leaves its empty tree on the server
  forever ([decision 0029](../../docs/agents/decisions/0029-empty-remote-directories-are-never-removed.md),
  [decision 0030](../../docs/agents/decisions/0030-the-audit-is-read-only-and-exists-stays-size-based.md)).
- **The tracked `galleries/` scans are prune-eligible on purpose**
  ([decision 0026](../../docs/agents/decisions/0026-tracked-gallery-photos-are-prune-eligible.md)),
  and they are the only published files no later run could put back. `cli._report_gallery_deletions()`
  names them on their own report line whenever a prune reaches one; the line's absence is the
  signal that nothing irreplaceable is at stake.
- `FtplibTransport.exists()` asks `SIZE`, and a server refuses `SIZE` for a **directory** — it
  will report a directory that is plainly there as "already gone". Deliberately left that way
  (decision 0030): nothing in the tool calls it, and reimplementing it on `list_dir` would be a
  second way to ask one question. Check anything that might be a directory with `list_dir`.

## What has no local test double

FTPS and the remote HTTP endpoint. There is no FTP server and no CI, so every decision is pushed
down into a pure function and both adapters are kept decision-free. What that leaves is
hand-checked and recorded, dated, in [`docs/agents/TESTING.md`](../../docs/agents/TESTING.md) —
add to the ledger rather than claiming a check no command produced.

## The manual host probes

Neither is automated on purpose: leaving a file at a guessable URL on a public host is worse than
a five-minute check. Both were run 2026-08-31 against `bilder.foerderverein-sefferweich.de` and
their answers are in the plan's Phase 6 section.

1. **Pre-install probe** — upload a one-off PHP file under a random name through the tool's own
   `FtplibTransport`, fetch it over HTTP, delete it in a `finally` that re-checks the URL no
   longer answers. Asks: `disable_functions`, `exec()`, `exiftool -ver`, `class_exists('Imagick')`,
   GD formats.
2. **`admin.php?page=maintenance&action=phpinfo`** — the same questions from inside the installed
   gallery, for the ones the probe cannot see. **Needs a `pwg_token`**: `maintenance_env.php:25`
   calls `check_pwg_token()` whenever `action` is set, so the bare URL redirects with no error
   (confirmed 2026-09-01 — page title `redirection`, empty body). Reach it by clicking through
   Werkzeuge → Wartung → Server-Umgebung → phpinfo in a logged-in session rather than typing the
   URL — the link there carries `&pwg_token=`.

The host answered `exec()` enabled and exiftool 12.76 present, so the provenance and persons
write-back works there. Note the version gap: local is 13.25, and no suite has run against 12.76.

## No database is transferred

In either direction
([decision 0023](../../docs/agents/decisions/0023-no-database-transfer-to-the-remote.md)) — and
because the schema therefore stays whatever the remote's last `install.php`/`upgrade.php` left,
the preflight compares `include/constants.php`'s `PHPWG_VERSION` against the remote's
`pwg.getVersion` and refuses any difference with exit `10`
([decision 0028](../../docs/agents/decisions/0028-core-version-is-detected-never-migrated.md)).
Exact string equality, never an ordering; `--allow-version-change` overrides. **The tool never
posts to `upgrade.php`** — run it in a browser yourself, then re-run the deploy. Albums
and photos are re-created by the `site_update` scan; person regions by `pwg.persons.rescan` out
of the image files. **Provenance columns have no path to the remote at all** — they live in the
database and the file is only an export target — so a photo whose provenance was not written back
before upload has none on the remote.
