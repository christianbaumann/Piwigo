# 0038 — Multi-line exiftool values travel in value files, not on argfile lines

Date: 2026-10-07
Status: accepted

## Context

The composed caption is the info text, a blank line, then the provenance text
(`.agents/changes/2026-10-07-photo-date-and-info/design.md`). exiftool reads its argfile one
argument per line, and provenance's writer collapsed every line break to keep a value from becoming
a second, possibly flag-shaped, argument (decision C8 of the write-back research). A blank line
cannot survive that.

exiftool's own escape for this, an argfile line starting with `#[CSTR]`, does not keep every
character: measured 2026-10-07 against exiftool 13.25, `$HOME` came back as `\$HOME`, and its source
(`FilterArgfileLine()`) escapes `@` the same way. A caption with an e-mail address or a price would
be changed silently.

## Decision

A value with a line break is written to a file in the operation directory and the argfile line
names it: `-XMP-dc:Description<=<operation dir>/<image id>-caption.txt`. A value without one stays a
plain `-TAG=value` line, byte for byte as before. `provenance_argfile_line()` makes the choice,
`provenance_argfile_value_files()` lists the files to write; both writers (provenance's
write-back, photoinfo's save) use them, and the operation directory's `finally` removes the files
with the argfile.

Provenance's own block stays one line (`provenance_caption_block()` collapses a note typed over
several lines), so a photo without an info text gets exactly the caption it got before.

## Consequences

- Still one line, one argument: no value reaches an argfile line with a line break in it.
- Measured 2026-10-07: `$`, `@`, quotes, backslashes and blank lines round-trip through all caption
  slots and `XMP-pwginfo:Info`. `-TAG<=file` predates exiftool 12.76 on the remote.
- exiftool reads `%` in a `<=` file name as a file-name token. The part of the path this code adds
  is the operation id (hex) and the image id; the rest is `PROVENANCE_ARGS_DIR`, which follows the
  install path, so an install under a directory with `%` in its name would break this.
