---
description: Quality-gate philosophy — ratchets, decision logs, single-source-of-truth, documented gaps
paths: ["**"]
---

# Backpressure

How to add friction against regressions without inventing a pipeline the project doesn't
have, and without pretending a check ran that didn't.

## Ratchet over rewrite

When a codebase has a large backlog of pre-existing violations (lint, style, coverage,
architecture), do not attempt a bulk fix and do not block on the backlog. Ratchet instead:
measure the current violation count or set, then gate only on **growth** — new files, or
new occurrences in changed lines. A gate that demands instant perfection on an inherited
codebase gets disabled; a gate that only stops things getting worse gets kept. If a check
must stay report-only because the backlog is large, say so explicitly and record *why* it
is excluded from the definition of done, rather than silently omitting it.

Concrete ratchet shapes, pick what fits the check:
- diff-scoped: lint/format/tests run only against files changed vs. the base branch
- size ratchet: a new file over a line-count limit fails; an existing over-limit file may
  not grow further
- coverage floor per module, defaulting to the current value, raised over time
- added-lines-only pattern grep (see `precommit-hooks.md`)

## Never invent a pipeline that doesn't exist

If a project has no CI, no linter, and no test runner beyond a couple of scripts, apply the
*intent* of these rules (verify before committing) with whatever checks actually exist. Do
not claim a lint pass, a coverage number, or a CI status that no command in this repo can
produce. State plainly which mechanical checks exist and which are manual.

## Decision log

Record irreversible or debatable decisions — including explicit decisions *not* to add a
gate, *not* to fix something, or to accept a known tradeoff — as small numbered files in a
`decisions/` directory, one decision per file, so later work cites the decision instead of
re-litigating it. A decision to skip a fix is exactly as worth recording as a decision to
ship one; both are otherwise invisible to the next person (or agent) touching the code.

## Single source of truth

State a given rule, threshold, or magic string in exactly one place. Everywhere else
either cites it or reads it programmatically (see "do not transcribe production data into
a test" in `test-design.md`). When two files can both plausibly hold the same fact, one of
them is stale already; delete the copy, don't maintain two.

## Documentation files have a length budget

`CLAUDE.md` stays under **100 lines**; a file in `.claude/rules/` stays under **500**. These are
hard caps, not targets — a file that exceeds one is split in the same change that made it too
long, not later.

The cap exists because `CLAUDE.md` is loaded into every session's context whether the task needs
it or not, while a rules file is loaded only when its read-trigger matches. Length in the root
file is therefore a tax on every task; length in a rules file is paid only by the tasks that
need it.

When a section outgrows the cap:

- Move it to `.claude/rules/<topic>.md`, one topic per file, kebab-case, **verbatim** — a command
  string reworded in transit is a command that no longer runs.
- Link it from `CLAUDE.md` under `## Additional rules` with a **read-trigger** naming the task,
  not the content ("read before running or adding a test in `plugins/*`"). A link with no trigger
  is a file the agent has no cue to open — content deleted with extra steps.
- Leave no copy behind. Two homes for one fact is the *single source of truth* violation above,
  and the copy is always the one that goes stale.

What stays in `CLAUDE.md`: what is genuinely repo-wide and needed on most tasks — the project
overview, how to start the environment, where agent artifacts go, and the rules index itself.

## Meta-rule: keep instructions honest

When something recorded in project instructions or rule files stops being true — a
capability shows up, a constraint disappears, a claim like "no test suite" or "no
dependency manager" becomes false — fix it in the same commit that made it untrue. Stale
process documentation is worse than none, because it is trusted by default.

## Report gaps, don't hide them

Anything a gate or suite cannot cover — a subjective judgment call, a check that needs
infrastructure this project doesn't have — gets named explicitly (a hand-check ledger, a
"not automatable, here's why" note) rather than silently dropped from the checklist. A
checklist item that disappears without a successor is not the same as one that was closed.
