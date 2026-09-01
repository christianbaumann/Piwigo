# 0028 — a core version difference is detected and refused, never migrated

Date: 2026-09-01
Status: accepted
Answers question 3 of
[`docs/agents/research/2026-09-01-ftp-deploy-blank-vs-existing-remote.md`](../research/2026-09-01-ftp-deploy-blank-vs-existing-remote.md)

## Context

The deploy sends core PHP and nothing else: no database content moves in either direction
([decision 0023](0023-no-database-transfer-to-the-remote.md)). The remote's schema is whatever
the last `install.php` or `upgrade.php` run left there.

That is fine while both sides are the same Piwigo. It stops being fine the moment this checkout
tracks a newer upstream: the upload replaces every core file, the remote database is left at the
old schema, and core discovers the mismatch at request time — an install that answers 500, or
worse, one that answers 200 against half-migrated tables. Nothing in the tool looked at the
version, so this failed only after 128 MB had already been written over a working gallery.

`pwg.getVersion` returns `PHPWG_VERSION` verbatim (`include/ws_functions/pwg.php:125-128`) and
`ws.php:57-62` registers it with no `admin_only`, so the question is cheap to ask.

## Decision

**Compare `include/constants.php`'s `PHPWG_VERSION` against the remote's `pwg.getVersion` in the
preflight, and abort on any difference with exit `10`. Never post to `upgrade.php`.**

Three parts of this are deliberate:

- **Exact string equality, not an ordering.** `17.0.0beta1` is not a semver, and "which of these
  two is newer" is a question this tool must not answer. A downgrade is as dangerous as an
  upgrade, and a wrong ordering rule would silently permit exactly the case it was meant to
  catch. Any difference is a refusal.
- **Detect only.** Posting to a migration endpoint unauthenticated — which is what this tool's
  HTTP half does everywhere else — is not acceptable even against the sandbox
  ([decision 0021](0021-remote-instance-is-a-sandbox.md)): `upgrade.php` rewrites the schema, it
  is the one action here with no undo, and a half-run migration leaves a gallery neither version
  can read. The operator runs it themselves, in a browser, and then re-runs the deploy.
- **An unreadable *local* version is also a refusal**, sharing exit `10`. Both failure modes are
  one question — "which core is this?" — and a caller branching on the answer takes the same
  action either way. Defaulting to "assume they match" would report agreement the tool never
  established.

The probe logs in before asking, although the method is public: an install with guest access
disabled refuses every ws method but `pwg.session.login`, and one code path that works against
both beats an unauthenticated call with a login retry.

`--allow-version-change` is the named escape hatch, for the case where the operator has already
run `upgrade.php` and knows the schema is right. It turns the refusal into a warning carrying the
same words.

## Consequences

- A remote that is not installed has no version, so a first run compares nothing. `None` is that
  state, and it is not a difference.
- The preflight costs two more HTTP round trips on an installed remote (`pwg.session.login`,
  `pwg.getVersion`), and none on a dry run, which opens no connection.
- The `preflight` report line carries the version, not just a verdict — `installed, 17.0.0beta1`
  — so an operator can see what the two sides agreed on rather than trusting that they did.
- The version line says nothing about the host's *other* versions. The remote runs exiftool
  12.76 against this machine's 13.25 and no suite has run against 12.76; that gap is unchanged
  by this guard and is recorded in `.claude/rules/deployment.md`.
- Guarded by 8 unit tests in `tests/test_version.py` (the parse, including the two shapes that
  are not this define) and 12 in `tests/test_preflight.py` / `tests/test_cli.py`, among them
  `test_a_version_difference_uploads_nothing`.

## What would reverse this

This tool gaining an authenticated admin session it could drive a migration with, *and* a way to
verify the result — a post-upgrade check that the schema version matches the core version. Until
both exist, refusing is the only outcome that cannot corrupt a gallery.
