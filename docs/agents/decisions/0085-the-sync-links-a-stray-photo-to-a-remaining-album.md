# 0085 — The sync links a photo that a removed folder would leave in no album

Date: 2026-10-18
Status: settled

Task 51 of `.agents/changes/2026-10-15-exploratory-testing-answers`; follows decision 0083 (an album delete is refused for the same case) and design "A photo's last album cannot be removed".

## Decision

- When the filesystem sync removes the albums of vanished folders, every photo that is stored outside them and whose only links are inside them is linked, before `delete_categories()` runs, to the parent of the highest removed album it was in (`id_uppercat`). When that album is a top-level one, the target is a private album `Ohne Album` at the root (`l10n('Without album')`), created on demand and found again by name, root position and `private` status. Private with no grants: administrators only.
- A photo with any other album keeps it and gets no new link. A photo stored in a removed folder is still deleted with it (core's `no_delete` mode), and counted.
- A simulation reports the number ("Fotos, die einem verbleibenden Album zugeordnet wurden") and links nothing, creates no album.
- The code is `relink_photos_of_albums_to_be_removed()` and `get_unsorted_album_id()` in `admin/include/functions.php`, called from `admin/site_update.php`.

## Consequences

- Rejected: refusing the sync like decision 0083 refuses an album delete. The sync has no per-album prompt and a vanished folder is a fact on disk; refusing would block the whole run.
- Linking goes through `associate_images_to_categories()`, so provenance's inherit hook fires for the target album.
- Tests cannot make a real sync remove a top-level folder (a sync limited to an album never removes that album). `testAPhotoOfARemovedTopLevelAlbumMovesToAPrivateRootAlbum` sets a sub-folder's `id_uppercat` to NULL; the code derives the target from `id_uppercat`, the highest album from `uppercats`.
- Not covered: a photo linked into two removed albums with different highest albums gets the target of the first link; two nested removed levels are not tested.
- The album is found again by its name in the current language, so a sync run by an administrator working in another language creates a second private album under that language's name. Accepted: the install runs in German.
