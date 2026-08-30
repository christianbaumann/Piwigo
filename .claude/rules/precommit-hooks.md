---
description: Pre-commit hook design — ratchet principle, self-test, submodule installation
paths: [".githooks/**", "tools/install-hooks.sh", "tools/test-hooks.sh"]
---

# Pre-commit Hooks

A ratchet, not a wall: block new breakage, leave pre-existing breakage alone, and stay
bypassable. Backpressure philosophy is in `backpressure.md`.

## The ratchet principle

Grade every check on **added lines only** (e.g. `git diff --cached -U0 | grep '^+'`), not
the whole file. Pre-existing violations are grandfathered; only new occurrences block the
commit. This is what makes a hook adoptable in a codebase with no prior gate — a hook that
demands the whole tree be clean on day one gets disabled, not fixed around.

Only add a hook once there is a real, fast command to gate on. A hook that fails when its
underlying tool/stack is down gets disabled by habit — check for that precondition and
degrade to a printed warning plus a skip, never a silent pass and never a hard failure that
blocks commits unrelated to the missing tool.

## What belongs in the hook

- Fast, deterministic checks only: syntax/lint on staged files, the unit test suite if and
  only if it runs in well under a few seconds with no external service.
- Integration and E2E suites stay **out** of the hook — they need a running stack, and a
  hook that fails whenever that stack is down is a hook people disable outright.
- `--no-verify` bypass always available, by design. The hook is backpressure, not a lock.

## Version-controlled, not `.git/hooks`

Install via `git config core.hooksPath <versioned-dir>` rather than copying scripts into
`.git/hooks` (which is not tracked by git and does not survive a clone). This keeps the
hook itself reviewable and diffable like any other source file.

**Submodules need their own `core.hooksPath`.** The superproject's `core.hooksPath` does
not apply to commits made inside a submodule — every submodule commit is its own repo
from git's point of view. An installer script must set the config in every repo that will
receive commits, or the hook silently never runs on the commits that matter.

## Self-test the hook

A hook nobody has watched fail is a hook nobody actually knows works, and a hook that
silently stops blocking is worse than no hook. Ship a self-test that:

- stages throwaway probe files (never a real commit),
- needs **at least two red cases and one green case** — proving the hook can both block
  and let a clean change through, not just one or the other,
- asserts each probe file actually differs from a clean baseline before running the hook on
  it, so a probe pattern that stops matching anything fails loudly instead of the self-test
  passing over nothing,
- restores the tree via a `trap` on `EXIT`/`INT`/`TERM`, so an interrupted run does not
  leave probes staged.

Before trusting a hook in the workflow, watch it fail once by hand, not only via the
self-test script.

## This repo's commit gate

`.githooks/pre-commit` is version-controlled and installed with `bash tools/install-hooks.sh`, which sets `core.hooksPath` on **both** the superproject and `plugins/typetags` — a superproject `core.hooksPath` does not apply to submodule commits, and every plugin commit is one. Run it after a fresh clone.

It runs four checks:

1. `php -l` on the staged content of staged `*.php` files.
2. No newly *added* `|| true` in a staged test file — added lines only, so pre-existing code is
   grandfathered.
3. The documentation length budget: a staged `CLAUDE.md` over 100 lines, or a staged
   `.claude/rules/*.md` over 500, blocks with a message naming the split procedure. Measured on
   the staged content, not the working tree. Unlike checks 1 and 2 this is a **hard cap, not a
   ratchet** — no tracked file exceeded either limit when it was introduced (2026-08-30), so
   there was no inherited backlog to grandfather. The rationale for the caps is in
   `backpressure.md`.
4. Every plugin's unit suite. If DDEV is down these are skipped with a printed warning rather
   than a silent pass.

`git commit --no-verify` bypasses all of it.

`.githooks/lib.sh` holds every shared constant — the test-path and vacuous-assertion patterns,
`UNIT_SUITES` (one command per gated plugin), and the two length caps with the path patterns
they apply to — so the hook and its self-test cannot drift apart. `tools/test-hooks.sh` builds
its probes from those constants rather than from typed copies, which is why raising a cap cannot
leave a probe testing the old one.
