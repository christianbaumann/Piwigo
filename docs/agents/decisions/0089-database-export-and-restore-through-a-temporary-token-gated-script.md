# 0089 — Database export and restore go through a temporary, token-gated script

Date: 2026-10-20
Status: accepted
Supersedes: [0023](0023-no-database-transfer-to-the-remote.md) for the remote-to-Mac export and for restoring a snapshot to the remote. The rest of 0023 stands: a deploy transfers no database, and the local install's rows never go to the remote.
Change: [`.agents/changes/2026-10-20-remote-backup/design.md`](../../../.agents/changes/2026-10-20-remote-backup/design.md)

## Context

0023 said that once the remote holds state no file can re-derive, the direction that matters is
remote to Mac and the decision needs a successor covering export. It also rejected a PHP endpoint
that runs uploaded SQL as a remote code execution primitive on a public host. The state exists
now ([0088](0088-the-remote-holds-state-the-repository-cannot-rebuild.md)), and the host has no
SSH, so a script on the host is the only automatable path.

## Decision

`pwg-backup` uploads a PHP script under a random name over FTPS, calls it over HTTPS, and deletes
it in a `finally`. The script:

- stores only the sha256 of a one-time token and an expiry time, and answers 403 to anything
  else or after expiry
- dumps with `mysqldump --single-transaction` through `exec()`, falling back to a pure-PHP dumper;
  the report names the path that ran
- writes the dump outside the web root if the FTP account reaches such a directory, otherwise
  into a random-named directory with a deny-all `.htaccess`; the dump is deleted after download
- on restore, imports the snapshot's dump. Only `pwg-backup --restore <snapshot> --yes` uploads
  a script with that action, after taking a fresh snapshot

## Accepted risk

While a run lasts, a script that can read the whole database (and, on restore, run SQL) sits on a
public host. Mitigations: unguessable name, one-time token compared by hash, short expiry,
deletion in a `finally`, `--audit` reporting a leftover script, and the import action existing
only in the restore run's script. The risk is not removed: anyone who learns the name and the
token inside the window gets the same access.

Rejected: a permanent webmaster ws method (a standing attack surface), the host's own backups
(none before a deploy, restore only by hand), phpMyAdmin (manual, fragile for large dumps).

## Consequences

- The script template lives in `tools/deploy` and never ships with the deploy
  ([decision 0022](0022-the-tools-directory-is-not-published.md)).
- A local DDEV mirror of the remote stays rejected: user rows, hashes, config paths and ids
  differ, which was 0023's third reason. The periodic restore check imports into a separate
  database, never `db`.

## What would reverse this

SSH or a host-provided scripted dump becoming available, or a leak traced to the script.
