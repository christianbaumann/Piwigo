# Piwigo Dev Environment

Read when a CSS/JS/template change does not show up, when touching `_data/`, or when adding a
plugin or theme that has to be tracked by git.

## Caches

Smarty compiles templates into `_data/templates_c/` and concatenates CSS/JS into `_data/combined/`.
`$conf['template_compile_check']` defaults to true, so `.tpl` edits normally recompile on their own — but stale combined assets are the usual cause of "my CSS/JS change didn't show up":

```bash
rm -rf _data/templates_c/* _data/combined/*
```

## `_data/provenance/` — the write-back working area

The provenance plugin keeps its own scratch space under `_data/`, defined once in
`plugins/provenance/include/functions.inc.php` and never spelled out anywhere else:

- `_data/provenance/locks/` — one `<sha1(image path)>.lock` file per image guarded against a
  concurrent exiftool write (`provenance_lock_path()`). A **separate** file, never the image
  itself: exiftool replaces the image by rename, so a lock held on the old inode would exclude
  nothing from the second writer onwards.
- `_data/provenance/args/<operation id>/` — the exiftool argfiles of one write-back operation
  (`provenance_operation_dir()`), removed whole in a `finally`, so a crashed run leaves at most
  one directory behind instead of orphan files nobody can attribute.

Both are created on demand and are safe to delete when nothing is writing. They are covered by
the root `.gitignore`'s `_data` entry, so nothing there is ever committed. Note that the
`_original` sidecars exiftool leaves next to a written image are **not** here — they sit beside
the image in `upload/` or `galleries/` and are the only copy of the pre-write bytes.


## `_data/persons/` — the region write-back working area

The persons plugin keeps the same shape of scratch space, defined once in
`plugins/persons/include/functions.inc.php`:

- `_data/persons/locks/` — one `<sha1(image path)>.lock` file per image (`persons_lock_path()`).
  A **separate** file for the same reason as provenance's: exiftool replaces the image by rename.
  Unlike provenance's, this lock is held across the whole read-merge-write in
  `persons_apply_change()`, not just the exiftool invocation — regions live only in the file, so
  two writers that each read it before either wrote would both produce a complete, valid region
  list with the other's face missing. Measured 2026-08-30 with the lock narrowed to the write:
  eight concurrent writers all reported success and the file came back holding one face.
  The lock is also **re-entrant within one process** (`persons_lock_acquire()` /
  `persons_lock_release()` count the depth): a reindex that corrects a physically rotated file
  writes it, and that reindex can already be running under `persons_apply_change()`'s lock.
  `flock()` attaches to the open file description, so a nested `fopen()` would have spun out the
  30 s timeout and reported a failure the caller could not act on.
- `_data/persons/args/<operation id>/` — the `-json=` payload of one write
  (`persons_operation_dir()`), removed whole in a `finally`.

Both are created on demand and are safe to delete when nothing is writing, and the root
`.gitignore`'s `_data` entry covers them. The `_original` sidecars are again **not** here — they
sit beside the image and are the only copy of the pre-write bytes.

`persons_merge_regions()` asks exiftool to delete a tag by handing it an empty JSON array.
Measured 2026-08-30 against exiftool 13.25: `[]` deletes, `""` writes an empty structure, and
`null` writes a literal null into the name list.

## Git-ignored working state

`.gitignore` excludes `plugins/*`, `themes/*`, `local/*`, `_data`, `upload`, `galleries/*`, then re-includes the tracked ones with `!` (`themes/default`, `themes/modus`, `themes/standard_pages`, `plugins/typetags`, `plugins/provenance`, `plugins/persons`). A newly tracked theme or plugin needs its own `!` entry or it stays invisible to git.

`local/config/config.inc.php` and `local/config/database.inc.php` are git-ignored and hold the install's overrides and DB credentials.

`.rodney/` (Chrome profile) and `.agent-tests/` (browser verification output) are ignored as local agent working state.

`.ddev/` carries its own DDEV-generated `.ddev/.gitignore` that excludes everything generated, so only `.ddev/config.yaml` is tracked. Don't add blanket `.ddev` rules to the root `.gitignore` — that file manages itself.
