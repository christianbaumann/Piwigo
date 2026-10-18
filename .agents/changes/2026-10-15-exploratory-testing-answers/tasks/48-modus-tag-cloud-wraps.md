---
id: 48
dependencies:
- 37
---

# Task 48: The tag cloud does not widen the page

Added 2026-10-18 from task 37's uncovered gap (user's answer 3). The letter list on phones stays in `docs/backlog.md`.

## References

* Task 37 (Deviations), decision 0032, `docs/backlog.md`
* `themes/modus/css/tags.css` (`#fullTagCloud SPAN { white-space: nowrap }`), `tags.php`

## Work

* [x] A long tag in the tag cloud wraps instead of widening the page: `#fullTagCloud SPAN` in `themes/modus/css/tags.css` now `white-space: normal; overflow-wrap: anywhere` (the seed lifts `full_tag_cloud_items_number` so the tag is in the cloud)
* [x] Add the edit to `.claude/rules/fork-local-core-edits.md` (task 37's modus bullet, its "tag cloud not covered" sentence fixed); remove the tag-cloud line from `docs/backlog.md`

## Verification

* [x] E2E: 255-character tag in the cloud, no horizontal page scroll at 1920 and 390 wide; short multi-word tags still break only at spaces
  **Note:** Verified via `long-words.spec.js` (cloud case per width; red before the fix: scroll width 2013 against 391 at 390 px; the short-tag test counts line boxes against words)
  Scripted flip 2026-10-18 (verify): `white-space: nowrap` restored in `tags.css` -> both cloud cases (1920, 390) red; fix back -> both green.
* [x] provenance E2E `long-words.spec.js` and `roles.spec.js` still pass (see the final run in the report)

## Deviations

* `wrap-seed.php` sleeps 1 s after invalidating the user cache. The tag list is cached per user under `cache_update_time` (one-second resolution); a view in the same second as the previous run's last one got a list without the new tag, which made the cloud case, and once the existing tag-list case, flaky.
* The short-tag test counts distinct line tops of the link's client rects against its word count rather than the draft's rect width check.
* `tags.css` has CRLF line endings; the edit keeps them.
