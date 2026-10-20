# 0088 — The remote holds state the repository cannot rebuild, so it is backed up

Date: 2026-10-20
Status: accepted
Supersedes: [0021](0021-remote-instance-is-a-sandbox.md)
Change: [`.agents/changes/2026-10-20-remote-backup/design.md`](../../../.agents/changes/2026-10-20-remote-backup/design.md)

## Context

Decision 0021 called the remote a disposable sandbox and named its own reversal: the remote
holding something not reproducible from this repository. That is now the case:

- photos uploaded through the remote's screens (`upload/`)
- edits made on the remote and their backups in `_data/photoedit/originals/`
  ([decision 0087](0087-photoedit-is-active-on-the-remote.md))
- albums created remotely
- provenance values, users, comments, tag assignments and album permissions, which live only in
  the database
- regions, dates and info texts that persons and photoinfo wrote into remote `galleries/` files

Nothing in the tool can put any of this back, and the deploy is the most likely way to destroy it.

## Decision

**The remote is a gallery with data of its own. It is backed up, and a deploy takes a snapshot
first.** The snapshot is pulled to the Mac by `pwg-backup`; `pwg-deploy` refuses to deploy when it
fails, and `--no-backup` skips it explicitly. Design, layout and retention are in the change
linked above.

Still destructive, accepted because the pre-deploy snapshot holds what they would remove:

- **Prune** deletes every path the previous manifest recorded and the file set no longer holds.
  Published code comes back on the next run; a pruned `galleries/` scan is in the snapshot
  ([decision 0090](0090-the-gallery-deletion-report-line-stays-beside-the-backup.md)).
- **The generated `local/config/config.inc.php`** overwrites the remote's. The snapshot holds the
  previous one.
- **`install.php` is posted unauthenticated.** Unchanged. The manifest/remote agreement check
  (decision 0027) still refuses to run it over an installed remote.

## Consequences

- "Never point `tools/deploy` at a real gallery" no longer holds for the deploy. It still holds
  for every plugin test suite and for the handbook seed.
- The README, `--help` text and `.claude/rules/deployment.md` stop calling the target a sandbox
  in the task that builds the backup.
- A backup that has never been restored has not been tested; the design therefore includes a
  restore and a periodic restore check.
- The snapshots hold password hashes, e-mail addresses and scans of private people, unencrypted by
  the owner's choice. The folder lives outside the repository.

## What would reverse this

The remote going back to holding only what a deploy rebuilds. Then the backup is overhead and
this decision is superseded in turn.
