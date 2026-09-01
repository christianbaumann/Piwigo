# 0026 — tracked `galleries/` photos are prune-eligible, and the prune names them

Date: 2026-09-01
Status: accepted
Answers question 2 of
[`docs/agents/research/2026-09-01-ftp-deploy-blank-vs-existing-remote.md`](../research/2026-09-01-ftp-deploy-blank-vs-existing-remote.md)

## Context

`.gitignore` ignores `/galleries/*` and then re-includes four album directories by name — the
recovered family scans. The database rows and the originals under `upload/` were lost on
2026-08-29, so those copies are the only ones left, and tracking them is what puts them on the
remote at all: no database content is transferred
([decision 0023](0023-no-database-transfer-to-the-remote.md)), and the remote's albums and
photos are re-created by the `site_update` scan out of the uploaded files.

Being tracked means being in the file set, which means being hashed into the manifest, which
means being **prune-eligible**: deleting one from the working copy makes the next run delete it
from the server. 106 published files, measured 2026-09-01, sit in that position, and the report
folded them into one `N removed` count alongside a pruned `.php`.

The question was whether that is a hole to close — exclude `galleries/` from the prune, the way
`upload/` and `_data/` are excluded by never being in the manifest in the first place.

## Decision

**Leave them prune-eligible. Add a report line that names them.**

Removing a scan from the working copy *should* propagate to the remote. That is what makes the
working copy the source of truth for the published gallery, and an exclusion would mean the only
way to unpublish a photo is to delete it over FTP by hand — the orphan state the manifest can
never reach again. `upload/` and `_data/` are excluded because they are server-authoritative:
the remote writes them and this checkout does not. `galleries/` is the opposite of that.

What is genuinely different about these files is not who owns them but **what a mistake costs**.
Every other published path is a copy of something git holds; a wrongly pruned `theme.css` comes
back on the next run. A wrongly pruned scan does not come back at all. So the compensating
control is at the report, not at the rule: `_report_gallery_deletions()` in `cli.py` emits a
`galleries` line whenever a prune would reach — or did reach — a path under `galleries/`, naming
up to `MAX_REPORTED_GALLERY_DELETIONS` of them and counting the rest. It fires on `--dry-run`
(predicted from `diff.removed`) and on a real run (read back from `deleted`), and never under
`--no-prune`, which deletes nothing.

## Consequences

- `--dry-run` is now the check before any run that reports a non-zero `removed`: it names the
  photos by path, so an unintended deletion is caught while it is still a prediction.
- The line is absent on an ordinary run. That absence is the signal; a line printed every time
  would be read as decoration, which is why `test_no_gallery_line_when_the_prune_touches_no_photo`
  asserts the absence with an anti-vacuity check that a deletion really did happen.
- `fileset.gallery_paths()` is the classifier, pure and taking remote paths, so a non-empty
  `remote_root` is handled by `urls.remote_path()` rather than by a second string rule.
- Guarded by six unit tests in `tests/test_fileset.py` (prefix, not substring; nested remote
  root; order preservation as anti-vacuity) and five in `tests/test_cli.py` (dry run, real run,
  both absences, and the cap).

## What would reverse this

The recovered scans ceasing to be the only copy — a backup the deploy could restore from, or
the originals resurfacing under `upload/`. Then a wrongly pruned photo is recoverable like any
other published file, the asymmetry this decision is built on disappears, and the report line
becomes noise worth removing.
