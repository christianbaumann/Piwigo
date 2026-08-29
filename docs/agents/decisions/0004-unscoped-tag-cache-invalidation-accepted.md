# 0004 — The unscoped `nb_available_tags` invalidation is accepted, not fixed

Date: 2026-08-28
Status: settled — accepted tradeoff

## Decision

Both `ws_typetags_image_addTag()` and `ws_typetags_image_removeTag()` end with

```sql
UPDATE piwigo_user_cache SET nb_available_tags = NULL;
```

with no `WHERE` clause, so assigning one tag to one image invalidates the cached tag count
for **every** user. This is left as it is (`main.inc.php:231-235`, `:271-275`).

## Why

It is a performance characteristic, not a correctness bug. Over-invalidation is always
safe: the count is recomputed on next use, and a `NULL` that did not need to be `NULL`
costs one query, never a wrong answer. Under-invalidation would be the dangerous direction,
and this cannot under-invalidate.

Scoping it correctly means computing which users can actually see the image — walking
category permissions and group membership for a write that touches a single row. That is a
materially larger change, in a place where getting it wrong produces stale counts that are
hard to notice, and it buys nothing that a user would perceive on an install of this size.

## Consequences

- No test asserts the scoping, because there is no requirement to assert. Recorded as
  deliberate non-coverage in [`../TESTING.md`](../TESTING.md).
- What *is* tested is that the invalidation happens at all —
  `CacheInvalidationTest::testAddNullsAvailableTagCount` and
  `::testRemoveNullsAvailableTagCount`, each with an anti-vacuity guard asserting the value
  was non-null before the call.
- If the install ever grows to a size where this shows up in page timings, this decision is
  the thing to revisit — cite it rather than rediscovering the tradeoff.
