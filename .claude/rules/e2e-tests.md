---
description: E2E/browser test structure, selector policy, flakiness rules
paths: ["**/*.spec.js", "**/e2e/**", "**/tests/e2e/**"]
---

# E2E Tests

Framework-agnostic rules for browser-driven end-to-end tests. Layer placement and when a
behaviour belongs here at all are in `testing.md`.

## Three-layer separation

Enforced by convention, not by the framework:

- **Spec/test files** orchestrate and assert. No locators live here.
- **Page objects** own every locator and interaction for exactly one view/page.
- **A seeding/fixture layer** owns test data — reuses the same fixture builder the
  integration layer uses wherever possible, rather than a second implementation.

A locator appearing in a spec file is the first symptom of a suite becoming
unmaintainable. Grep for it: a check that finds a bare `locator(`/`querySelector` outside
the page-object file is a real regression, not a style nitpick.

## Author against the real running app, not from reading markup

Draft and develop each spec by driving the app live (record/inspect via the framework's
own codegen/inspector tools), then commit the result. Writing a selector purely from
reading a template file and hoping is guessing — the DOM a test sees can be assembled or
mutated at runtime by client-side JavaScript, which is not the DOM the template shows.

Before writing a spec, confirm in a live session (not from source):
- what the actual DOM looks like once any runtime JS has run
- what sibling/ordering assumptions (e.g. text-node separators) any removal/reorder logic
  depends on

## Selector policy

Locate by stable, intentionally-emitted identifiers: ids, data attributes, and classes the
application emits on purpose. Never by position within framework- or theme-generated
markup (`nth-child`, layout-dependent XPath) — that markup is free to change shape on any
unrelated update.

## No retries, no parallelism, no bare sleeps

- `retries: 0`, single worker. A flaky test is fixed or made deterministic — never retried
  into green, never disabled to make CI quiet.
- Wait on events and locator/network state, never on a bare timeout. If a bare sleep is
  ever unavoidable, name it individually with the reason and do not add a second one by
  precedent.
- Tracing on for every test, written to a discoverable output path — a failure without a
  trace is a failure you get to debug once and then never again.

## Distinguish lookalike failure modes

When testing error handling, cover the failure modes that actually differ in the code path
they exercise, not just "an error happened":
- a network-level failure (aborted/timed-out request) vs.
- a **successful response carrying an application-level failure status** (HTTP 200 with an
  error payload) — these often hit different client-side code paths (e.g. one callback vs.
  another), and a fix for one does not imply the other is handled.

## Config and secrets

Config lives in environment variables or a config file with framework defaults; secrets
are git-ignored, never hardcoded into a spec or support file. Every value is overridable
from the command line/environment for CI or a different target host.

## Mapping to a checklist

When closing out a manual QA checklist, map each surviving manual item to exactly one
named spec (or explicitly to the hand-check ledger in `test-design.md` if it cannot be
automated) — a checklist item with no named successor is not closed.
