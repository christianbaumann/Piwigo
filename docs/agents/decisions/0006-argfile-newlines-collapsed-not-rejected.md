# 0006 — A newline in provenance text is collapsed to a space, not rejected

Date: 2026-08-29
Status: settled

## Decision

`provenance_sanitize_argfile_value()` replaces `\r\n`, `\r` and `\n` with a single space
before a value is written to an exiftool argfile, then trims. It does not reject the value
and does not attempt to escape it.

The implementation plan
([`../plans/2026-08-29-provenance-metadata-writeback.md`](../plans/2026-08-29-provenance-metadata-writeback.md),
Phase 2) left this open as "rejected or escaped". Neither was taken; this file records why,
so the choice is cited later rather than re-argued.

## Why

**Escaping is not available.** exiftool's `-@ argfile` reads one argument per line and
defines no continuation or escape syntax. A newline inside a value is not something the
format can represent, so there is nothing to escape it into.

**Rejecting would break the field's intended use.** `provenance_note` and the album's note
are `text` columns holding free text an administrator types into a textarea. Multi-line
input is the expected case, not an attack. A validator that refused it would make the
write-back fail on ordinary data and push the administrator into reformatting notes to suit
a file format they never see.

**Silent splitting is the failure this prevents.** Without the collapse, the tail of a
multi-line note becomes a second argfile line — and if that tail begins with `-`, exiftool
reads it as a flag. `BuildArgfileTest::testNewlineInAValueNeverProducesASecondLine` uses
`-overwrite_original` as the tail for exactly that reason.

**The loss is confined to layout.** The database keeps the note verbatim; only the copy
embedded in the image file loses its line breaks. EXIF/IPTC/XMP caption slots are
single-line description fields by convention, so the reformatting matches what a reader of
those fields expects anyway.

## Consequences

- Text in the image file may differ from text in the database by whitespace alone. Any
  future file-vs-DB divergence check (backlog, decision 4a in the research) must compare
  sanitized values, not raw ones, or every multi-line note reports as divergent.
- `provenance_compose_caption()` deliberately does **not** collapse newlines; sanitizing
  happens once, in `provenance_build_argfile()`, at the boundary with the file format.
- If a caller ever needs the raw text in a file, it needs a different transport than the
  argfile — not a change to this function.
