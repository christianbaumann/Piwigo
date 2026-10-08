# 0042 — The caption is photoinfo's date and info text, a blank line, then the provenance text

Date: 2026-10-08
Status: accepted

## Context

The standard caption slots (`XMP-dc:Description`, `IPTC:Caption-Abstract`, `EXIF:ImageDescription`)
hold one text each, and both `plugins/provenance` and `plugins/photoinfo` want their text visible
there. Two writers each writing only their own text would erase the other's.

## Decision

- Every caption, whichever plugin writes it, is composed of blocks joined by a blank line:
  photoinfo's block first (readable date on its first line, the info text with markup stripped
  below), then provenance's one-line block. An empty block is left out.
- Provenance fires `trigger_change('provenance_caption_parts', $blocks, $image)` inside
  `provenance_write_back()` only, not in the Provenienz row's composer, so the picture page's
  Provenienz row stays provenance-only. photoinfo's handler puts its block first.
- photoinfo's own saves compose the same caption through the same filter, so a save in either plugin
  rebuilds the whole text.
- The info text alone is also written to `XMP-pwginfo:Info`, because it cannot be cut back out of a
  composed caption (decision 0043). The blank line travels in a value file (decision 0038).

## Consequences

- A photo without an info text or date gets exactly the caption provenance wrote before.
- A third plugin wanting caption space adds a block to the same filter; the slot list and joiner
  stay in provenance.
- A composed caption over IPTC's 2000-byte cap is truncated in the IPTC slot only, as provenance
  always did (`provenance_truncate_for_iptc()`); the XMP and EXIF slots carry the whole text.
