---
description: Mutation testing — when it's warranted (rarely), scope, and how to record results
paths: ["**"]
---

# Mutation Testing

Mutation testing checks whether existing tests can actually detect a change in behaviour —
it verifies test *strength*, which is a different question from test *design* (choosing
which cases to write, covered in `test-design.md`). A well-designed suite can still be
mutation-weak if its assertions are too loose to notice a small change.

## Not a default technique — it is costly and slow

Do not run mutation testing as a routine step of ordinary development, and do not do it
per-commit or per-task. It is expensive (each mutant is a full test-run) and slow to do by
hand honestly (see below). Reserve it for:

- the **end of an implementation plan**, once, as a final strength check over the unit
  suite the plan built, or
- a genuinely **critical phase/step** of a plan — one where a silent, undetected regression
  would be severe (e.g. the core calculation a whole feature depends on) — and only when
  that phase explicitly calls for it.

If neither applies, skip it. Writing well-designed tests per `test-design.md` (equivalence
classes, boundaries, anti-vacuity) is the default; mutation testing is an occasional audit
of that work, not a step repeated alongside it.

## Scope: unit layer only

Apply mutation reasoning only to unit tests. Never to a UI, E2E, or structural guard test —
a red end-to-end run does not say *which* mutation caused it, so running mutants against it
teaches nothing about test strength; it only re-confirms the suite runs at all.

## Keep it as prose, not a script

Record mutants as a table (mutant → which test kills it) rather than automating patch/run/
revert cycles against source files. A script that patches and reverts source is a second
thing to keep correct, and it silently stops working the moment the patched line moves —
the exact kind of fragile, self-defeating tooling this practice is meant to avoid.

Minimum shape of the table:

| Mutant | Expected killer | Result |
|---|---|---|
| `>` → `>=` on a threshold | the boundary-value test pair | killed / not killed |
| a comparison operator flip | the equivalence-class test for that branch | killed / not killed |
| a return value swapped for its sibling | the negative/default-case test | killed / not killed |
| a loop/condition negated (`in_array` → `!in_array`) | the partition/branch test | killed / not killed |

## Record what did not die, honestly

"Nothing else moved" is a real, useful claim: it means a mutant killed exactly the tests
watching it, and no more, no less. A mutant that survives is itself a finding, not a
failure to hide — often it means either the test is genuinely too weak, or (less obviously)
that the boundary the mutant targets is unreachable in the real input domain. Both are
legitimate conclusions; record which one applies and why, rather than guessing or quietly
swapping in an easier mutant to make the table look complete.
