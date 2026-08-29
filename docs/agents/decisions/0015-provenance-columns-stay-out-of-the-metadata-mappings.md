# 0015 — Provenance columns stay out of `use_iptc_mapping` and `use_exif_mapping`

Date: 2026-08-29
Status: accepted
Implements decision C3 of the provenance plan

## Context

The plugin writes provenance text *into* image files (Phase 6) and Piwigo separately reads
metadata *out of* image files during a synchronisation. Those two directions meet at one pair of
configuration arrays:

```php
// include/config_default.inc.php:368,404
$conf['use_iptc_mapping'] = array('keywords' => '2#025', ..., 'comment' => '2#120');
$conf['use_exif_mapping'] = array('date_creation' => 'DateTimeOriginal');
```

Both are keyed by **`piwigo_images` column name**, and
`get_sync_metadata_attributes()` (`admin/include/functions_metadata.php:125-150`) builds the list
of columns a sync overwrites out of `array_keys()` of exactly those two arrays. A column that is
not a key in either is a column no synchronisation writes.

The tempting shortcut was to add the provenance fields as mappings — say
`'provenance_owner' => '2#080'` — and let core's existing sync path populate them for free.

## Decision

**No provenance column is ever added as a key in either mapping array**, and no existing mapping
(`name`, `comment`, `author`, `date_creation`, `keywords`) is repointed at a slot the writer uses.
The plugin owns both directions itself: it writes with exiftool and reads back only in its own
tests.

The reason is a revert loop. The write-back puts the composed caption into
`IPTC:Caption-Abstract` (2#120) among five caption slots. `comment => '2#120'` already maps that
same slot onto `images.comment`. Had provenance been mapped the same way, the cycle would be:

1. an administrator edits provenance on the album and applies it — the database is authoritative;
2. the write-back stamps the composed caption into the file;
3. any later `sync_metadata()` — a filesystem sync, a "synchronise metadata" batch action — reads
   the file back and overwrites the database columns from it.

Step 3 makes the *file* authoritative, which inverts the model the whole feature is built on
(the four album-sourced columns are album-authoritative, decision C3). Worse, it is silent and
lossy: the caption is a flattened `label: value | label: value` string, so a round trip through
it cannot reconstruct the five separate columns, and the IPTC copy is byte-capped at
`PROVENANCE_IPTC_MAX_BYTES` while the database column is not — a sync after a truncation would
write the truncated text back over the full value.

## Consequences

- `sync_metadata()` cannot revert an album-sourced value, because it never names those columns.
  This is a property of the *absence* of a configuration entry, so there is nothing to assert at
  the unit layer; what the suites do assert is the positive half — that apply and inheritance are
  the only writers.
- The custom `XMP-pwgprov:*` namespace exists partly for this reason: it keeps the machine-readable
  copy of each field in a slot no core mapping points at, so re-reading it later is a deliberate
  feature rather than an accident of configuration.
- File-vs-database divergence after a third-party edit is therefore undetected. That is decision
  4a (no divergence detection in v1) and is carried in `docs/backlog.md`, with
  `images.date_metadata_update` recorded there as the candidate signal.
- Anyone who later wants the sync to feed provenance must design the reverse mapping deliberately
  — parsing the XMP namespace, not the flattened caption — and must decide which side wins.
