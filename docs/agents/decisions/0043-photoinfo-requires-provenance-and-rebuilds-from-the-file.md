# 0043 — photoinfo requires provenance, and a rescan rebuilds its values from the file

Date: 2026-10-08
Status: accepted

## Context

photoinfo writes into the same files as provenance. Concurrent exiftool writes destroy a file, and
two plugins with two locks would let them run (research, "Concurrency and Locking on Originals").
The remote's database is never transferred (decision 0023).

## Decision

- photoinfo refuses to activate unless provenance is active (`photoinfo_maintain::activate()`).
  It writes through `provenance_exiftool_run()`, which takes provenance's per-image lock, passing
  its own `-config` (`exiftool/pwginfo.config`, the `pwginfo` namespace only: a photoinfo run never
  writes provenance's `pwgprov` tags). It fills provenance's caption slots through
  `provenance_caption_values()`. Photoedit's lock handoff is provenance's, so it covers photoinfo
  with no code of its own.
- `pwg.photoinfo.rescan` (admin only, POST, `pwg_token`, at most 10 ids per call) rebuilds
  `comment` and the date columns from `XMP-pwginfo:Info` and `XMP-pwginfo:DateEDTF`. It writes the
  database only, never the file. A file without the tags leaves the photo as it is.
- The rescan reads only the five EDTF forms photoinfo writes. Anything else (`1965/`, `1965?`,
  `196X`, `1965~/1970`, …) is reported in `failed` with the string; the date columns stay, and the
  info text is still restored. A stored `date_creation` on the file's day keeps its time of day.
- `photoinfo` is in `PLUGINS_TO_ACTIVATE` after `provenance`, and the deploy rescans every photo
  after the sync.

## Consequences

- photoinfo cannot run alone. With provenance deactivated afterwards, core's screens still update
  photoinfo's columns, and the file write is reported as failed.
- The deploy's rescan reads every photo on every run, and a photo it cannot read is a report line,
  not an abort: a host without exiftool deploys with empty dates and info texts and a `(warning)`.
- The info text is stored twice in the file, once in the caption and once alone.
- Same drift window as decision 0020: a file changed outside Piwigo is not noticed until a rescan.
- An edit made on the remote changes a file the next deploy overwrites (decision 0026), as for
  provenance.
