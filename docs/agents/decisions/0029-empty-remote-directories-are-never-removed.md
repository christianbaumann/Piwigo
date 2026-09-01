# 0029 — empty remote directories are never removed

Date: 2026-09-01
Status: accepted
Answers question 4 of
[`docs/agents/research/2026-09-01-ftp-deploy-blank-vs-existing-remote.md`](../research/2026-09-01-ftp-deploy-blank-vs-existing-remote.md)

## Context

The prune deletes files. Nothing in `pwg-deploy` has ever issued `RMD`, so a directory whose
last file was pruned stays on the server as an empty directory forever. Deleting
`plugins/foo/` from the working copy removes its files on the next run and leaves its tree
behind, one empty directory per level, with no report line mentioning it.

Phase 5 of the plan gave `Transport` a listing operation for `--audit`, which removes the
mechanical reason not to: `RMD` needs to know a directory is empty, and until now nothing
could ask.

## Decision

**Leave them. No `RMD`, no directory removal, listing operation or not.**

An empty directory costs an inode on a shared web space and is invisible to every visitor —
`local/**/index.php` guards the one directory whose listing would matter, and it is published
precisely so a directory listing is never served. Against that, directory removal is where an
over-broad delete stops being recoverable: a wrong file deletion loses one file that the next
run puts back, while a wrong `RMD` against a path the tool mis-derived takes whatever the
server had under it — including `upload/` and `_data/`, the two trees this tool never wrote
and could never restore.

The listing operation does not change that calculus. It makes "is this directory empty?"
answerable, not "is this directory mine to delete?", and the second question is the one that
would have to be right every time.

## Consequences

- Removing a plugin or a theme from the working copy leaves its empty directory tree on the
  remote permanently. Deleting it is a hand operation over FTP, like removing an orphan
  ([decision 0030](0030-the-audit-is-read-only-and-exists-stays-size-based.md)).
- `--audit` counts those directories in its `listed … in N directories` figure but does not
  single them out. A directory holding no files is not an anomaly worth a report line; on a
  gallery it is the ordinary state of `_data/` between two thumbnail generations.
- `Transport` stays without an `rmd` operation, so no future caller can reach one by accident.

## What would reverse this

A host that charges for or limits inodes, or a remote tree that has accumulated enough dead
directories to make `--audit` unreadable. Both are measurable, and the audit is what would
measure them — at which point the removal could be built against a listing that has been read
by a human first, rather than derived.
