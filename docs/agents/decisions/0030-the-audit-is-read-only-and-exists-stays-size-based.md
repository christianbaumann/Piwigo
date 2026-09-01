# 0030 — `--audit` reports and never deletes, and `exists()` keeps its `SIZE` blindness

Date: 2026-09-01
Status: accepted
Answers question 5 of
[`docs/agents/research/2026-09-01-ftp-deploy-blank-vs-existing-remote.md`](../research/2026-09-01-ftp-deploy-blank-vs-existing-remote.md),
and closes the second of its two "still open" items.

## Context

The prune computes `removed` from the previous manifest alone, so a path present on the server
but absent from the manifest can never be reached again by any run. This is not hypothetical:
during the Ctrl-C experiment of 2026-08-31 manifest entries were dropped by hand and the
corresponding files had to be deleted over FTP manually. Until Phase 5 the tool had no way to
even see such a file — `Transport` had six operations and none of them listed a directory.

Adding the listing raised two questions the research note left open: what `--audit` does with
what it finds, and whether `exists()` — which asks `SIZE` and therefore reports a directory
that is plainly there as "already gone" — should be fixed now that a listing could answer
correctly.

## Decision

**`--audit` lists, compares and reports. It deletes nothing.** Removing an orphan stays a hand
operation over FTP.

**`exists()` keeps its `SIZE` implementation, blindness included.**

An audit's whole value is that its output can be trusted without a second thought: an operator
runs it on a remote nobody has looked at — often the exact state the missing-manifest guard
([decision 0027](0027-manifest-and-remote-must-agree-on-installation.md)) refuses a deploy
over — and reads a list of paths. A mode that also deleted would be acting on a classification
no human had yet checked, against files the tool by definition knows nothing about. That is the
same reasoning that keeps `RMD` out of the tool entirely
([decision 0029](0029-empty-remote-directories-are-never-removed.md)); here it applies with the
listing in hand rather than for want of one.

`exists()` is left alone for a different reason. Nothing in the tool calls it — it is used only
by `smoke.py` and its own tests — so its blindness costs nothing today, and re-implementing it
on top of `list_dir` would create a second way to ask one question. Two ways to ask one question
is the copy that rots: the day only one is updated, the tool has two answers about the same
server. The blindness is recorded here and in a comment at the call site instead, so the next
reader finds a decision rather than a bug.

## Consequences

- `--audit` is a standalone mode like `--list-files`: it runs no preflight, no upload and no
  bootstrap, and always exits `0`. A report is not a failure, so a script wrapping it sees a
  success even when orphans are found.
- The read-only claim is mechanical, not prose: `test_audit_deletes_nothing` asserts
  `"delete" not in transport.names()` against the same call log every other transport test
  reads, and `test_audit_uploads_nothing_and_runs_no_bootstrap` asserts no `put` and not one
  request to the gallery.
- `_data/` and `upload/` are skipped by name, read from `fileset.REMOTE_DIRS_TO_CREATE`. They
  are server-authoritative, the manifest records nothing under either, and listing them would
  bury the real orphans under thousands of files that are not orphans. The report names what it
  skipped rather than skipping silently.
- `local/config/database.inc.php` is written by `install.php` on the server and is reported as
  an orphan. That is correct — no run of this tool could ever delete it — and the generated
  `local/config/config.inc.php` is the one exception the comparison knows about by name.
- `MLSD` only, with no `NLST` fallback: `NLST` cannot tell a file from a directory, and probing
  each of several thousand names with a `CWD` is both slow and a second thing to keep correct.
  A server that refuses `MLSD` fails loudly, naming the command and stating that a deploy is
  unaffected.

## What would reverse this

Orphans becoming routine rather than exceptional — a workflow that regularly drops manifest
entries, so hand-deletion over FTP is the usual path rather than the rare one. Then a
`--audit --delete` that acts on a list the operator has just read on screen would be worth
building, with the confirmation step this decision's read-only mode does not need.
