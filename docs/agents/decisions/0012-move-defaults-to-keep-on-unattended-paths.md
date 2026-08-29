# 0012 — A move keeps the provenance a photo already has

Date: 2026-08-29
Status: accepted
Supersedes the behaviour shipped in Phase 7 (commit c51ecfe09)

## Context

`move_images_to_categories()` (`admin/include/functions.php:2154`) breaks the old links and then
calls `associate_images_to_categories()` at `:2183`, which is where this fork's
`associate_images_to_categories` trigger fires. So a move and a plain association are
indistinguishable at the only hook the plugin has, and Phase 7's inheritance overwrote the four
album-sourced columns for both. `InheritTest::testMovingToAnotherAlbumReplacesTheInheritedValues`
recorded that.

Decision Q7 answered "ask the user", which needs a prompt in the UI and a documented default
for `pwg.images.setCategory` and the Batch Manager, neither of which can prompt on an
unattended call.

Core offers nothing else to hook: `dissociate_images_from_category()` (`:2115`) fires no trigger
at all, `move_images_to_categories()` has none of its own, and `ws_images_setCategory()`
(`include/ws_functions/pwg.images.php:3006`) fires none either.

## Decision

**The unattended default is `keep`.** A photo that already carries any of the four album-sourced
values is left alone by an association; one that carries none still inherits.

The choice travels as an explicit request parameter, `provenance_move_mode`, resolved by
`provenance_resolve_mode()` — `keep`, `clear` or `replace`. Anything unusable resolves to
`keep`: the parameter rides on a core web-service method the plugin cannot add a parameter to,
so there is no call of ours to return an error from, and falling back to the mode that destroys
nothing is the only safe reading of a value nobody can be asked about.

Because a move cannot be told from an association, the rule is stated in terms of the *photo*
rather than the operation: an association never overwrites provenance a photo already has. That
needs no distinction core cannot provide.

Apply (`pwg.provenance.applyToPhotos`) is unchanged and still overwrites unconditionally. It is
the deliberate administrator action; a link is not. Decision C3's "album-authoritative" stands
for apply, not for inheritance.

## Consequences

- A move never silently rewrites where a scan came from. The destination album's values arrive
  when someone asks for them — through apply, or through the move prompt's `replace`.
- `keep` will not fill the gaps of a partially filled photo: a record half from one album and
  half from another would say a scan came from two places at once.
- Phase 7's recorded behaviour is deliberately replaced, not fixed. The superseded assertion is
  mapped to its two successors in commit 9aef092fe.
- Dissociation is not covered and cannot be without a further core trigger. It removes a link
  without asserting anything about origin, so `keep` is the right answer for it anyway.
