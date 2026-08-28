---
description: Test-case design technique, assertion quality, anti-vacuity, fixtures
paths: ["**"]
---

# Test Design

How to choose test cases and how to tell a real assertion from one that cannot fail.
Layer placement lives in `testing.md`. Verifying test *strength* (not just test *design*)
via mutation is a separate, occasional concern — not a default step — see
`mutation-testing.md`.

## Technique legend

State it once, here, and tag test-case bullets with it in plans and specs rather than
restating the definitions elsewhere.

| Tag | Meaning |
|---|---|
| `[HAPPY]` | happy path |
| `[NEG]` | negative case |
| `[ECP]` | equivalence class partition |
| `[BVA]` | boundary value |
| `[ST]` | state transition |
| `[DT]` | decision table |
| `[ERR]` | error guessing / characterization (documents actual behaviour, not a requirement) |

A technique that does not apply to the unit under test is recorded **with its reason**,
not silently omitted — e.g. "decision table not applicable: one condition, two outcomes."

## Watch it fail first

Write the test, run it, and watch it fail *before* the code it describes exists. Read the
failure message and confirm it fails for the expected reason — a test can go red for a
reason unrelated to the behaviour it claims to check. A test written against code that
already exists only records that code; it never drove it, and passing on its first run is
the tell that it did not.

## Proving a check can actually fail

A green test proves nothing on its own. Before trusting it:

1. Run it repeatedly, alone and inside the full suite.
2. Break the behaviour it claims to check and confirm it goes red.
3. Reverse or weaken the assertion and confirm that goes red too (unit layer only — a
   mutation of an E2E or integration test does not localize which change broke it).

## Anti-vacuity

Every guard that scans, counts, or greps anything carries a **lower-bound constant**, and
every count assertion is preceded by a guard proving the count is not zero. A scan that
reads nothing, or a count fixture that yields zero, must fail loudly rather than pass by
accident.

- `assertSame(1, substr_count($haystack, $needle))` alone can pass on an empty haystack. Add
  `assertGreaterThan(MIN_BYTES, strlen($haystack))` first.
- A test asserting "K badges rendered" for a fixture that forces K must itself assert K > 0.
- Whoever deletes the guard line removes the watchman, not the risk — do not "simplify away"
  an anti-vacuity assertion because it looks redundant.

A trailing `|| true`, an `assertTrue(true)`, or any condition that is a tautology by
construction is not a passing test — it is a test that has stopped existing. Grep for this
pattern in review; it is exactly the kind of defect a pre-commit ratchet exists to catch
(see `precommit-hooks.md`).

## Do not transcribe production data into a test

When a test's assertion must match a literal that production code also hardcodes (a
search string, a threshold, a magic constant), extract it into one named constant in
production code and have the test read that constant. A second, independently typed copy
in the test rots silently the day only one of the two is updated.

## Structural guard tests

Ordinary tests that assert things a compiler or type system does not watch: a template
string a prefilter depends on still being present, a fixture a generator still relies on
matching, a config default that a runtime feature actually needs, a file that was supposed
to stay excluded from a build still being excluded. These run inside the normal suite —
they need no separate tooling — and they exist because no other layer would report the
regression at all: the page would simply render without the feature, silently.

## Fixture provenance

Every fixture earns a recorded purpose: what case it covers, and how it is built. Rule for
adding a new one — cover a case that actually differs; a fixture that differs only in an
irrelevant field (a different name, same shape) proves nothing the existing one does not.

Fixtures **force** their precondition and **assert** it took effect before the test body
runs — a test must never run over a state it merely hopes for (e.g. `SELECT ... LIMIT 1`
off whatever the database happens to hold). Cleanup restores original state, but no
assertion may depend on cleanup having run, and cleanup must not run in a way that erases
the evidence of a failure (skip cleanup-on-failure, or capture evidence first).

## Hand-check ledger

For behaviour no automated layer can reach (genuinely subjective judgment: does contrast
look legible, does a transition feel right), keep a dated ledger recording what was
checked, by whom/how, and — once something automates it — which test replaced it, so the
ledger shrinks over time instead of accumulating. Nothing gets marked done on prose alone;
if it isn't in the ledger with a reason it can't be automated, it isn't verified.
