# 0003 — `typetags.image.addTag` / `removeTag` stay answerable over GET

Date: 2026-08-28
Status: settled — accepted as-is

## Decision

Neither web-service method sets the `post_only` option. Both answer to GET as well as POST,
and that is left alone. A characterization test records the behaviour rather than a fix
changing it.

## Why

**`pwg_token` already carries the CSRF guard.** Both handlers call `check_pwg_token()`
before any write, and the token is per-session and not guessable from a third-party page.
The protection `post_only` would add on top of that is the narrow case of a state-changing
GET being triggered by an `<img>` or prefetch — which still cannot pass the token check.

**Adding it would break external callers.** The Piwigo web-service API is a public surface;
any script or integration currently calling these methods over GET would start failing with
no deprecation path. That is a real cost against a benefit already covered.

**It is not the defect this plan set out to fix.** Promoting it to a change is a separate
decision with its own compatibility question, not something to fold silently into a
testing plan.

## Consequences

- `AddTagTest::testMethodAlsoAnswersToGet` `[ERR]` pins the current behaviour, with a
  docblock stating that no requirement stands behind it — it reports a change, it does not
  find a defect. `WsClient::callGet()` exists for it.
- If `post_only` is ever added, that test is the thing that goes red first, which is the
  point of it.
- Recorded as deliberate non-coverage in [`../TESTING.md`](../TESTING.md).
