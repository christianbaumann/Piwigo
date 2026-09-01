# 0031 — Person names are visible to guests, as ordinary tags

Date: 2026-09-01
Status: accepted
Supersedes nothing. Names the trade-off left implicit by
[0019-person-region-permission-model.md](0019-person-region-permission-model.md) and
[0020-persons-index-is-derived-the-file-is-the-source-of-truth.md](0020-persons-index-is-derived-the-file-is-the-source-of-truth.md).

## Context

`plugins/persons` mirrors every tagged person as an ordinary Piwigo tag so that browsing,
search and the public tag page work with no new code — decision 0020 records that the tag
mirror, not a second identity system, is the deliberate design. Decision 0019 gates the
region overlay, the boxes and the `Personen` row behind `!is_a_guest()`, because a marked
face is a stronger disclosure than a plain colour tag and the *where* in the image should
not leak to an anonymous visitor.

That gate stops at the overlay. The tag mirror is an ordinary row in
`piwigo_image_tag`/`piwigo_tags`, and core's own related-tags rendering on the picture page
(`related_tags`, `themes/default/template/picture.tpl:210-217`) has no persons-specific
exception. A person's *name* therefore appears in the `Schlagworte` row and on the public
tag page for a visitor who is not logged in, even though the region that placed it there
never does.

This was implemented and verified deliberately —
`PicturePageSourceTest::testAGuestSeesNoOverlay` in `plugins/persons/tests/Integration/`
asserts the overlay markup absent and, in the comment at lines 218-222, explains in prose
why the name is not asserted absent alongside it. Until this pass that explanation lived
only in that comment, not as a decision a later reader could cite.

The 2026-09-01 handbook walkthrough found `handbuch/05-personen.html:17` claiming
"Nicht angemeldete Besucher sehen von alldem nichts" — untrue of the name, true of
everything else. Phase 2 of the implementing plan corrected the handbook page; this
decision records the trade-off it now describes.

## Decision

Keep the tag mirror's guest visibility as-is. A person's name is visible to an anonymous
visitor in the `Schlagworte` row and on the public tag page; the region overlay, the boxes
and the `Personen` row stay behind `!is_a_guest()` per decision 0019.

### What was rejected

Filtering a person-derived tag name out of `related_tags` for guests was considered and
rejected for this pass. Decision 0020 made the tag mirror deliberately indistinguishable
from an ordinary tag — no `id_typetags`-style side table marks a tag as person-derived at
the row level that core's related-tags query could exclude. Hiding the name would mean one
of:

- adding a persons-aware filter into core's own related-tags rendering, which every other
  decision in this project has avoided (core carries no fork-local changes beyond the two
  `trigger_notify()` calls the provenance plugin needed — see the CLAUDE.md project
  overview), or
- marking person-derived tags at the row level after all, which reopens the question
  decision 0020 closed in the other direction.

Both are larger changes with their own regression risk, out of proportion to a
documentation-driven finding. Revisit if a future requirement makes the name itself the
sensitive fact, not just the region.

## Consequences

- `handbuch/05-personen.html` documents this: `:17` says guests see neither the boxes nor
  the `Personen` row, and `:126` explains that the name still reaches the `Schlagworte`
  row and the public tag page because every marked person is also an ordinary tag.
- `PicturePageSourceTest::testAGuestSeesNoOverlay` keeps its explanatory comment; this
  decision is the citable record the comment pointed at needing.
- No code changes. This is a decision not to change behaviour, recorded so the next reader
  cites it instead of re-discovering the gap.
