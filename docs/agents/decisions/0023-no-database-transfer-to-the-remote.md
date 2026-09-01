# 0023 — No database transfer to the remote, in either direction

Date: 2026-08-31
Status: accepted
Implements decision 9 of the FTP deployment plan
([`docs/agents/plans/2026-08-31-ftp-deployment-and-remote-install.md`](../plans/2026-08-31-ftp-deployment-and-remote-install.md))

## Context

The obvious shape for "deploy my gallery" is files plus a database dump: `mysqldump` locally,
upload, import remotely. It is what most PHP deployments do, and it is what makes a remote
instance a copy of the local one rather than a fresh install of the same code.

`tools/deploy` does none of it. It has no dump step, no import step, and no local→remote or
remote→local sync of any table. The remote schema is created by `install.php` and filled by
two operations the tool drives over HTTP: the `site_update` scan, which walks the uploaded
`galleries/` tree and creates albums and photos from it, and — for face regions —
`pwg.persons.rescan`, which reads them back out of the image files.

## Decision

**No database transfer of any kind.** Content on the remote is *re-derived from the uploaded
files*, never copied from the local database.

Three reasons, in order of weight:

1. **Most of the content is already derivable.** Albums and photos come from the directory
   tree the deploy uploads. Person regions come from MWG data inside the image files
   themselves — the persons tables are a derived index and the file is the source of truth
   ([decision 0020](0020-persons-index-is-derived-the-file-is-the-source-of-truth.md)), so a
   rescan on the remote reconstructs them from bytes that were uploaded anyway.
2. **The host offers no path for it that is worth building.** No SSH, so no `mysql <` over a
   shell; an import would mean either a PHP endpoint that executes uploaded SQL — which is a
   remote code execution primitive sitting on a public host — or phpMyAdmin by hand, which is
   not automation.
3. **A dump would carry the local install's identity**: its user rows and password hashes, its
   session table, its `piwigo_config` including the local `assume_https` and exiftool paths,
   and its `_data/` derivative bookkeeping. Every one of those is wrong on the remote and would
   have to be filtered back out.

## Consequences

- **The provenance columns do not reach the remote, at all.** `plugins/provenance` keeps its
  values in database columns and treats the image file as an *export* target
  ([decision 0015](0015-provenance-columns-stay-out-of-the-metadata-mappings.md)), which is
  the opposite shape from persons. So unless a photo's provenance was written back into the
  file *before* it was uploaded, the remote gallery has no provenance for it and no way to
  obtain one. This is accepted only because the remote is a sandbox
  ([decision 0021](0021-remote-instance-is-a-sandbox.md)); it would be data loss on a
  production instance and is the first thing a production posture has to answer.
- The remote's webmaster account is the one in the credential file, not the local one. There
  is no shared identity between the two installs and no user data crosses.
- The remote's photo ids, album ids and tag ids are its own. Nothing may assume an id means
  the same thing on both installs — a link into the local gallery does not transfer.
- Re-deploying after a wipe is cheap precisely because of this: files plus one scan, with no
  dump to keep in sync.

## What would reverse this

Content that exists only on the remote and cannot be re-derived from an uploaded file — a
photo added through the remote admin UI, a tag or an album created there, a provenance value
edited there. At that point the remote holds unique state, the direction that matters is
remote→local, and this decision needs a successor covering *export*, not import. That is also
the trigger for [decision 0021](0021-remote-instance-is-a-sandbox.md) to be revisited, and it
is not a coincidence: they are the same fact seen from two sides.
