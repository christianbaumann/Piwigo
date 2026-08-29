# 0009 — Provenance text is stripped of markup, and `allow_html_descriptions` is ignored

Date: 2026-08-29
Status: settled

Raised by Phase 4 of `docs/agents/plans/2026-08-29-provenance-metadata-writeback.md`, which
introduced the first provenance text an administrator types.

## Decision

`pwg.provenance.setAlbumInfo` runs `strip_tags()` over all four album fields and stores the
result. `$conf['allow_html_descriptions']` — the setting core consults before letting an album
description keep its markup (`include/functions_html.inc.php`) — is **not** consulted, in either
direction. Turning it on does not make provenance text accept markup.

Over-long text in the two `VARCHAR(255)` fields is **refused with `PwgError(400)`**, never
truncated.

## Why

**The destination is a metadata packet, not a web page.** These four values are copied onto every
photo in the album and written into the image files as EXIF `ImageDescription`, IPTC
`Caption-Abstract` and XMP. Those slots hold plain text; `<b>` reaching them is not styling, it is
two stray characters an external viewer shows verbatim, and it is what a future reader of the file
would take for part of the physical album's name.

**Honouring the setting would make the file contents depend on a gallery display option.** The
same album, saved on two installs that differ only in `allow_html_descriptions`, would produce
different bytes inside the photographs. A provenance record must not vary with a rendering
preference.

**Truncation is the worse failure.** The point of the feature is that the record of where a scan
came from is accurate. A name silently cut at 255 characters is a wrong provenance fact that
nobody is told about; a refused save is a visible one the administrator can correct. This is the
same reasoning as [0006](0006-argfile-newlines-collapsed-not-rejected.md) reached in the other
direction — there, collapsing was chosen because a newline carries no provenance meaning, while
here the cut characters do.

The note column is `TEXT` and therefore has no cap at all — nothing has to be refused.

## Consequences

- `provenance_clean_short_text()` and `provenance_clean_note()` are the single definition of this
  rule; every later write path (photo edit, copy-down, inheritance) goes through them rather than
  re-deciding.
- The character cap is measured with `mb_strlen()`, not `strlen()`: `VARCHAR(255)` on a utf8mb4
  table holds 255 characters. This is deliberately unlike `PROVENANCE_IPTC_MAX_BYTES`, which is a
  real byte budget imposed by the IPTC packet.
- An administrator who genuinely wants angle brackets in a provenance value cannot have them.
  Accepted: no physical album is named `<b>`.
