# 0087 — photoedit is active on the remote

Date: 2026-10-19
Status: accepted
Supersedes: the "photoedit stays inactive on the remote" part of [decision 0059](0059-photo-edits-are-open-to-every-logged-in-account-that-sees-the-photo.md) and the same rule in `.claude/rules/deployment.md` (*Photo edits travel as files*)

## Context

The deploy published `plugins/photoedit` but left it out of `PLUGINS_TO_ACTIVATE`
(`tools/deploy/pwgdeploy/bootstrap.py`), so turning and cropping was possible only on the local
install. The reason given: an edit made on the remote changes a file that is not in git, and a
later deploy can overwrite it with the working copy's version (decision 0026). The owner wants
photos turned and cropped on the remote as well.

## Decision

The deploy activates `photoedit` like the other fork-local plugins.

## Consequences

- Every logged-in account that sees a photo can turn and crop it on the remote
  ([decision 0059](0059-photo-edits-are-open-to-every-logged-in-account-that-sees-the-photo.md)).
- The deploy never reads the remote back; it uploads a published path when the local file differs
  from what the manifest recorded. A remote edit of a photo under `galleries/` therefore survives
  until that photo's local file changes, or until the manifest is deleted and everything is sent
  again; then the local version replaces it without a warning. To keep a remote edit, copy the
  file back into the working copy and commit it.
- A photo uploaded through the remote's own screens (`upload/`) is not published by the deploy, so
  an edit to it has no local counterpart to collide with.
- The backups under `_data/photoedit/originals/` exist on the remote too, and only there:
  `_data` is never uploaded or pruned.
- The plugin's `?v=` URLs apply on the remote only to edits made there: the version is kept in the
  `photoedit_versions` config row, and no database reaches the remote (decision 0023). A photo
  edited locally and deployed still gets no versioned URL there.
- The overwrite risk is not new: persons and photoinfo already write the remote's `galleries/`
  files.
