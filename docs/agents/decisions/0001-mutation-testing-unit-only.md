# 0001 — Mutation testing applies to the unit layer only, and stays prose

Date: 2026-08-28
Status: settled

## Decision

Mutation testing is applied to **unit tests only** — never to an integration, E2E, or
structural guard test. The record is a prose table (mutant → expected killer → result),
never a script that patches and reverts source files. It is not a routine step: it runs at
the end of an implementation plan, or on a phase where a silent regression would be
severe, and nowhere else.

## Why

**Unit only.** A mutant is useful because it answers "which test is watching this line?".
A red end-to-end run does not answer that — it says the page broke, not which mutation
broke it. Running mutants against a browser test therefore teaches nothing about test
strength; it only re-confirms the suite runs at all. The same holds for a structural guard,
whose subject is a file's contents rather than a code path.

Where a rule is witnessed only by a higher-layer test, that is a gap in the pyramid. It is
closed by pushing the rule down to the unit layer, not by mutating the browser.

**Prose, not a script.** A patch/run/revert harness is a second thing to keep correct, and
it stops working silently the moment the line it patches moves — the exact class of
fragile self-defeating tooling this practice exists to avoid. It would also be an apparatus
built to prove another apparatus.

**Not a default step.** Each mutant is a full test run, and doing it honestly by hand is
slow. Per-commit or per-task mutation buys almost nothing over well-designed tests
(equivalence classes, boundaries, anti-vacuity), which is the actual default.

## Consequences

- The mutant table lives in [`../TESTING.md`](../TESTING.md), maintained by hand, dated.
- A surviving mutant is recorded as a finding with its reading — either the test is
  genuinely too weak, or the boundary is unreachable in the real input domain. Guessing
  between the two, or swapping in an easier mutant, is not allowed.
- The 2026-08-29 run produced one of each: `$l >= 0.45` survives because the threshold is
  mathematically unreachable on 8-bit channels; `strlen($color) >= 7` survived because the
  test was weak, and the test was strengthened until the mutant died.

## Also recorded in

`.claude/rules/mutation-testing.md`, which is the stack-independent form an agent loads
automatically. This decision is the *why*; that file is the *how*.
