# 0090 — The gallery-deletion report line stays beside the backup

Date: 2026-10-20
Status: accepted
Revisits: [0026](0026-tracked-gallery-photos-are-prune-eligible.md), against its *What would reverse this*
Change: [`.agents/changes/2026-10-20-remote-backup/design.md`](../../../.agents/changes/2026-10-20-remote-backup/design.md)

## Context

0026 keeps tracked `galleries/` scans prune-eligible and compensates with the `galleries` report
line, because a wrongly pruned scan was the one loss no later run could undo. It said the line
becomes noise "if there is a backup the deploy could restore from". A pre-deploy snapshot
([0088](0088-the-remote-holds-state-the-repository-cannot-rebuild.md)) holds the remote's copy
of every scan the prune can reach.

## Decision

**Prune stays as it is, and the report line stays.** The condition for removing it is only
partly met:

- A snapshot makes the loss recoverable, not free. Recovery is a restore from the Mac, by hand.
- The snapshot is absent when the run uses `--no-backup`, and absent when the backup folder was
  lost or pruned by retention. The line is the only warning in those cases.
- The line is silent on an ordinary run. It costs nothing there.

0026's reasoning about prune eligibility is unchanged.

## Consequences

- No code change in `cli._report_gallery_deletions()`.
- The sentence in 0026 that calls these scans "the only copy" is no longer exact once snapshots
  exist; this file is the correction. 0026 itself is not edited.

## What would reverse this

A backup that is mandatory, cannot be skipped and is checked before every deploy. Then the line
can go.
