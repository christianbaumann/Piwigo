# Piwigo Dev Environment

Read when a CSS/JS/template change does not show up, when touching `_data/`, or when adding a
plugin or theme that has to be tracked by git.

## The Apache container

The remote (all-inkl.com) runs Apache with `.htaccess`; DDEV's web container runs nginx. A second
DDEV service, `apache`, serves the **same checkout and database** with `php:8.4-apache`,
`mod_rewrite` and `AllowOverride All`, so an `.htaccess` rule is tested locally next to its nginx
counterpart (`handbuch/.htaccess` and `.ddev/nginx/handbuch.conf`, decision 0063):

- defined by `.ddev/docker-compose.apache.yaml`, built from `.ddev/apache-build/Dockerfile`; it
  starts with `ddev start` / `ddev restart` and shows in `ddev describe`
- from the host: https://piwigo.ddev.site:8443 (http on 8480); from inside the web container, where
  the suites run: `http://apache`
- the project is bind-mounted, not Mutagen-synced, so it sees a host edit before the web
  container does, and it inherits macOS's **case-insensitive** file system: `/handbuch/Index.html`
  answers 200 there and 404 on nginx and on the remote. A case result from this container proves
  nothing about the remote
- provenance's `HandbookAccessTest::testTheApacheContainerAnswers` fails naming the service when it
  is down, so the Apache cases never pass by being skipped

Known difference: all-inkl runs PHP through FPM/CGI behind Apache, the container through
`mod_php`. Rewrite and access rules behave the same; PHP-handler directives (`php_value`,
`php_flag`) in an `.htaccess` do not, and need a hand check on the remote.

The first `ddev restart` after adding the service failed once with a container-name conflict on
`ddev-piwigo-apache` (2026-10-12); a second `ddev restart` came up clean.

`.ddev/nginx/*.conf` is included into DDEV's generated server block; after editing one,
`ddev exec nginx -s reload` applies it without a restart.

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
- `_data/provenance/args/<operation id>/` — the exiftool argfiles of one write-back operation, or
  of one `plugins/photoinfo` save, plus the value files a multi-line caption or info text travels
  in (`<image id>-caption.txt`, `-caption-iptc.txt`, `-info.txt`; decision 0038), or the
  `rescan.xml` one photo's `pwg.photoinfo.rescan` read lands in (`exec()` would drop each line's
  trailing whitespace) (`provenance_operation_dir()`), removed whole in a `finally`, so a crashed run leaves at most
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

## `_data/photoedit/` — the photo edit working area

`plugins/photoedit` keeps three directories, defined once in
`plugins/photoedit/include/pipeline.inc.php`:

- `_data/photoedit/locks/` — one `<sha1(image path)>.lock` per photo, held across one edit.
- `_data/photoedit/work/<operation id>/` — the turned temp file, built here and renamed over the
  image only once its metadata is copied back; removed in a `finally`. A directory left behind
  means the PHP process died mid-edit (the original is untouched then). Safe to delete when
  nothing is writing.
- `_data/photoedit/originals/<image id>-<timestamp>.<ext>` — the bytes of the file before each
  edit. **Not** scratch: it is the only local backup of an edited photo outside `galleries/`, and
  there is no undo button. Restoring is copying one back by hand.

All three are covered by the root `.gitignore`'s `_data` entry and never reach the remote.

## Git-ignored working state

`.gitignore` excludes `plugins/*`, `themes/*`, `local/*`, `_data`, `upload`, `galleries/*`, then re-includes the tracked ones with `!` (`themes/default`, `themes/modus`, `themes/standard_pages`, `plugins/typetags`, `plugins/provenance`, `plugins/persons`, `plugins/photoedit`, `plugins/photoinfo`). A newly tracked theme or plugin needs its own `!` entry or it stays invisible to git.

`local/config/config.inc.php` and `local/config/database.inc.php` are git-ignored and hold the install's overrides and DB credentials.
A fresh clone must add `$conf['guest_access'] = false;` to `config.inc.php` by hand: guests are
locked out ([decision 0055](../../docs/agents/decisions/0055-guests-are-locked-out.md)), the deploy
writes the line into the remote's generated config, and without it the local gallery pages and
API stay open to guests. Provenance's `GuestLockoutTest` fails naming the setting when it is missing.

`.rodney/` (Chrome profile) and `.agent-tests/` (browser verification output) are ignored as local agent working state.

`.ddev/` carries its own DDEV-generated `.ddev/.gitignore` that excludes everything generated, so only `.ddev/config.yaml` is tracked. Don't add blanket `.ddev` rules to the root `.gitignore` — that file manages itself.
