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


## Git-ignored working state

`.gitignore` excludes `plugins/*`, `themes/*`, `local/*`, `_data`, `upload`, `galleries/*`, then re-includes the tracked ones with `!` (`themes/default`, `themes/modus`, `themes/standard_pages`, `plugins/typetags`, `plugins/provenance`, `plugins/persons`). A newly tracked theme or plugin needs its own `!` entry or it stays invisible to git.

`local/config/config.inc.php` and `local/config/database.inc.php` are git-ignored and hold the install's overrides and DB credentials.

`.rodney/` (Chrome profile) and `.agent-tests/` (browser verification output) are ignored as local agent working state.

`.ddev/` carries its own DDEV-generated `.ddev/.gitignore` that excludes everything generated, so only `.ddev/config.yaml` is tracked. Don't add blanket `.ddev` rules to the root `.gitignore` — that file manages itself.
