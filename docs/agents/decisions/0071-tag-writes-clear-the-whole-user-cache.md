# 0071 — Tag writes clear the whole user cache

Date: 2026-10-16
Status: accepted; supersedes [0004](0004-unscoped-tag-cache-invalidation-accepted.md)
Implements the design section *Tag writes clear the whole user cache* of change
`.agents/changes/2026-10-15-exploratory-testing-answers`.

## Context

Decision 0004 accepted that typetags' tag writes end with
`UPDATE piwigo_user_cache SET nb_available_tags = NULL`, on the grounds that over-invalidation
"cannot under-invalidate". persons' `persons_invalidate_tag_cache()` did the same.

That premise was false. `get_available_tags()` (`include/functions_tag.inc.php`) keeps each user's
tag list in `$persistent_cache`, under a key made of the user id and the user_cache row's
`cache_update_time`. Nulling `nb_available_tags` leaves that row and its time, so the key stays
and the list a user read before stays: the tag list, a tag's page and Schlagwort-Gruppen showed
a tag after its last photo lost it, and missed a new one, until something else rebuilt the cache
(SES-2026-10-14-22 F1, SES-2026-10-13-02 F4). Core's own tag writes call
`invalidate_user_cache()`, which is why a core tag change refreshed the lists and a + or a person
mark did not.

## Decision

- `typetags.image.addTag`, `removeTag` and `addNewTag` (`plugins/typetags/main.inc.php`) and
  `persons_invalidate_tag_cache()` (`plugins/persons/include/index.inc.php`, used by every
  region write, rename and delete that changes a photo's person tags) call core's
  `invalidate_user_cache()`, including `admin/include/functions.php` themselves, since `ws.php`
  does not load it.
- Tested by a requirement, not by the column: a user who read the tag list sees the tag come
  and go right after the write (typetags' `CacheInvalidationTest`, persons'
  `TagListFreshnessTest`). The two old cases asserting `nb_available_tags IS NULL` are replaced
  by them.

### What was rejected

- Scoping the invalidation to the users who can see the photo: it would need core's permission
  walk for every write and gains nothing on an install of this size (0004's reasoning still
  holds for that part).
- Purging only `$persistent_cache`: `get_available_tags()` keys by `cache_update_time`, so a
  purge alone works today, but it depends on an internal detail; `invalidate_user_cache()` is
  what core does after its own tag writes.

## Consequences

- Each tag write rebuilds every user's cache once, on that user's next request: the album
  permissions (`user_cache_categories`) as well as the counts. On this install that is a few
  queries per user.
- The deliberate non-coverage row in `docs/agents/TESTING.md` for the scoping now cites this
  decision.
