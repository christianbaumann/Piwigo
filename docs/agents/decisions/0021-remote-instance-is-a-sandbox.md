# 0021 — The remote instance is a sandbox, not production

Date: 2026-08-31
Status: accepted
Implements decision 9 of the FTP deployment plan
([`docs/agents/plans/2026-08-31-ftp-deployment-and-remote-install.md`](../plans/2026-08-31-ftp-deployment-and-remote-install.md))

## Context

`tools/deploy` publishes this fork to a shared web space and completes the install there. The
question that shapes every other choice in the tool is what that remote instance *is*: a
throwaway target that exists to prove the fork runs on a real host, or the gallery people will
eventually use.

It matters because the tool is destructive in three ways that are fine for one and not the
other:

- **It prunes.** A path the previous manifest recorded and the file set no longer holds is
  deleted from the server.
- **It overwrites `local/config/config.inc.php`** on every run whose generated content differs
  — anything edited on the server is lost without a prompt.
- **It runs `install.php` unauthenticated.** `install.php:258-433` has no CSRF token and no
  captcha; the whole install is one POST branch, which is what makes it scriptable at all.

And one way that is quiet rather than loud: the `site_update` scan re-creates albums and photos
from the uploaded files, so **`restore` drops the provenance columns** — the values live in
database columns this deploy has no path to (see
[decision 0023](0023-no-database-transfer-to-the-remote.md)) and nothing on the remote can
reconstruct them.

## Decision

**The remote instance is disposable.** It may be wiped and re-deployed at any time, and losing
everything on it costs nothing that is not also in this repository. The tool is written for
that assumption and says so in three places that an operator actually reads: the `--help`
description, the first paragraph of `tools/deploy/README.md`, and
`.claude/rules/deployment.md`.

Concretely, this is what the assumption buys:

- Prune needs no confirmation prompt. `--dry-run` predicts what it would delete, and
  `--no-prune` turns it off, but nothing blocks.
- The generated config needs no merge with what is on the server.
- Provenance values being absent from the remote is a known, accepted state rather than data
  loss.
- No backup step exists anywhere in the tool.

It was in fact exercised: the web space was wiped by hand on 2026-08-31 after the verification
runs, and the recovery was to delete the target's manifest and deploy again.

## Consequences

- **Nothing in `tools/deploy` may be pointed at a real gallery** as it stands. The same
  sentence already applies to every plugin test suite in this repository, for the same reason.
- A production instance is a **separate decision and a separate posture**, and this decision
  file is what it supersedes. At minimum it would need: a confirmation before any prune, a
  path for provenance values to reach the remote (or an accepted, documented absence), a
  backup taken before `install.php` is ever posted to, and an answer for the unauthenticated
  install branch.
- The install marker is one file-level fact — `PHPWG_INSTALLED` in
  `local/config/database.inc.php` (`install.php:156-165`), never a database check. On a
  sandbox, the failure mode that pairing produces (a database holding a schema while the config
  file is gone, so the tool cheerfully installs over it) is a re-deploy. On production it is
  the accident this decision exists to keep out of scope.

## What would reverse this

Hosting a gallery anyone depends on at the deployed URL. The moment the remote holds something
that is not reproducible from this repository — a photo uploaded through the admin UI, a
provenance value edited on the server, a person tagged into a file that exists only there —
the assumption is false and this decision needs a successor before the next deploy, not after.
