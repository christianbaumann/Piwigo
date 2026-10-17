# 0076 — The unused-tag cleanup keeps coloured and person tags, through a core filter

Date: 2026-10-17
Status: settled

Design: `.agents/changes/2026-10-15-exploratory-testing-answers/design.md`, *The unused-tag cleanup
leaves coloured and person tags alone* (SES-2026-10-14-12).

## Decision

Core's `get_orphan_tags()` (`admin/include/functions.php`) passes the rows it selects through a new
fork-local `trigger_change('get_orphan_tags', $rows)`. `plugins/galleryrules`
(`include/events_orphan_tags.inc.php`) drops from them the tags in a colour group
(`piwigo_tags.id_typetags IS NOT NULL`) and a person's tags (`piwigo_persons.tag_id`). Every caller
sees the result: the tags page's cleanup and its confirm list, and both maintenance paths.

The design put the exclusion into `get_orphan_tags()` itself. Core would then name two plugins'
tables; the filter keeps that knowledge in a plugin, and core carries one line.

The same change shows the confirm's names one per line and replaces core's German wording
(`1 nicht benutzte Schlagworte`, `11 verwaiste :`) with `Nicht benutzte Schlagworte (n):`, in
`local/language/de_DE.lang.php`. The tag menu's count ("1 Foto") is fixed in both places that
render it: `tags.js` and the server-side `tagContent` in `tags.tpl`, which the design did not list.

## Reason

Groups hold their tags on purpose; a tag in one with no photo is not clutter. A person's tag
belongs to the person, whether or not a photo carries it.

## Trade-offs

A cleanup without `galleryrules` active deletes those tags again. The plugin is in the deploy's
activation list. Rejected: SQL in core (see above); only fixing list and wording.
