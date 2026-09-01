# 0027 — the manifest and the remote must agree, and a disagreement aborts

Date: 2026-09-01
Status: accepted
Answers questions 1 and 6 of
[`docs/agents/research/2026-09-01-ftp-deploy-blank-vs-existing-remote.md`](../research/2026-09-01-ftp-deploy-blank-vs-existing-remote.md)

## Context

The tool keeps one local manifest per target and consults nothing else to decide what to send
(`upload.py`). The server is never read back. That asymmetry is deliberate — it is what makes
the prune safe for `upload/` and `_data/` — but it also means the manifest is only ever a
*picture* of the remote, and nothing checked that picture against the server.

Two states make the picture a lie, and each was silent:

1. **The web space was emptied by something other than this tool.** The manifest still holds a
   hash per path, so the run reports `0 new, 0 changed`, uploads nothing, and leaves a broken
   site. This happened on 2026-08-31.
2. **The manifest is gone while the remote is a fully installed gallery** — a second machine, a
   wiped `.state/`. The run re-uploads everything, and every remote path this file set no longer
   carries becomes an orphan the prune can never reach, because prune only ever considers what
   the *previous* manifest recorded.

Both were reachable in one report: `manifest … (existing)` and `install installed` could print
in the same run. `bootstrap.is_installed()` already asked the remote the right question, but it
ran in the bootstrap half, *after* the upload, and nobody compared the two answers.

## Decision

**Ask `install.php` before uploading anything, and abort when the answer contradicts the
manifest. Never self-heal.**

`preflight.check_state()` is a pure two-condition decision table; `preflight.probe()` is the one
impure GET, through the injected HTTP port. Both refusals raise `StateMismatchError` (exit `9`)
and name the operator's next action: the manifest file to delete in case 1, `--audit` in case 2.

Self-healing was the alternative in both directions and was rejected in both:

- Healing case 1 means silently re-uploading 128 MB because a file on this machine looked
  wrong. The cost of being wrong about that inference is a full transfer nobody asked for, and
  the operator would not know it happened.
- Healing case 2 means adopting a server whose contents nobody has looked at, and then pruning
  against it. An empty manifest cannot tell an orphan from a file the deploy put there.

`--adopt-remote-state` is the named escape hatch. It turns either refusal into a warning that
carries the same words, so the operator is told what they overrode rather than merely permitted
to override it. There is no way to reach the old behaviour by accident.

## Consequences

- The guard is **skipped on `--dry-run`**, which opens no connection at all, and the report says
  `preflight skipped (--dry-run opens no connection)`. A silently skipped guard is one an
  operator believes ran.
- It **still runs under `--no-bootstrap`**: the upload is exactly the half it protects.
- A run costs one extra HTTP round trip, against a run that already takes tens of seconds.
- The first run after this change may abort where the previous version proceeded. That is the
  point; the message names both ways out.
- Guarded by 16 unit tests in `tests/test_preflight.py` (all four table cells, each with and
  without `--adopt-remote-state`, both messages asserted by the *values* they must carry) and
  seven in `tests/test_cli.py`, including `test_a_refused_preflight_uploads_nothing` — a guard
  that aborted after the transfer it was meant to stop would be worthless.

## What would reverse this

The tool learning to read the server back as a matter of course — a manifest rebuilt from a
remote listing rather than trusted. `--audit` is the first step in that direction, but it
deliberately only reports (decision 0030). If a later version could reconstruct the
manifest from the server safely, case 2 would become a recoverable state rather than a refusal.
