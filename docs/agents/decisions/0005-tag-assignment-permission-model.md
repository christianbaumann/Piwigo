# 0005 — Tag assignment is open to all logged-in users

Date: 2026-08-28 (recording a decision originally made 2026-04-24)
Status: settled, with one question explicitly left open

## Decision

`typetags.image.addTag` and `typetags.image.removeTag` gate on `is_a_guest()` plus a
`pwg_token` check, and nothing else. They deliberately carry **no** `admin_only` option.
Any authenticated user can assign or remove a colored tag on any image.

## Why

This is the recorded design decision from the feature's research
(`../research/2026-04-24-picture-page-tag-assignment.md:258`, "Permission level: All
logged-in users (not guests)"), not an oversight in the implementation. It is why the two
methods exist at all: the obvious alternative, `pwg.images.setInfo`, is registered
`admin_only` (`ws.php:878`), which would have made the feature admin-only and defeated its
purpose. The research weighed a plugin-registered WS method against a bespoke `ajax.php`
endpoint and chose the former as the idiomatic Piwigo shape.

The absence of `admin_only` is therefore correct as specified, and a test asserting an
admin gate would be asserting a requirement that does not exist.

## The question this leaves open

**A logged-in user can tag an image in a category they cannot browse.** Neither method
performs a per-image visibility check, so an id the user could never reach through the UI
is still a valid target for a direct web-service call.

This is recorded rather than fixed. It was not examined when the permission model was
chosen — the research settled *which users* may tag, and never asked *which images*. Fixing
it means resolving per-user category permissions inside both handlers, which is a real
change to the feature's semantics and belongs to whoever decides the answer, not to a
testing plan.

Its practical weight depends on the install: on a single-owner gallery where every logged-in
account is trusted, it is nothing. It matters on an install with restricted categories and
untrusted accounts.

## Consequences

- `AddTagTest::testGuestIsRejected` and `RemoveTagTest::testGuestIsRejected` pin the one
  gate that does exist.
- No visibility test exists, and its absence is recorded as deliberate non-coverage in
  [`../TESTING.md`](../TESTING.md).
- Revisiting the open question starts here rather than from scratch.
