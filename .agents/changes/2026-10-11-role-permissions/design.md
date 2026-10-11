---
datetime: 2026-10-11T20:27:29+02:00
author: Christian Baumann
tags: [permissions, roles, guests, typetags, provenance, persons, photoinfo, photoedit, handbook]
---

# Role permissions: implementation design

This design covers how to build the target access levels from
[`docs/agents/research/2026-10-11-role-permissions.md`](../../../docs/agents/research/2026-10-11-role-permissions.md)
(decisions 1-11 and the matrix there). It also covers the three open findings at the end of that
note. The research note has the *what*. This document has the *how* and the decisions that change
the note.

Roles: Guest, Normal (includes core `generic`), Admin (administrator, not webmaster), Webmaster.

## Facts found during design

These three facts change the plan the research note implies:

1. **`guest_access = false` does not close the API.** `ws.php:13` runs `check_status(ACCESS_FREE)`.
   Guests can still call public methods (`pwg.categories.getImages`, `pwg.tags.getList`,
   `pwg.persons.getList`, ...).
2. **`check_lounge()` runs before `$user` is loaded.** It is called at `include/common.inc.php:202`,
   and `include/user.inc.php` is included after it. A provenance handler that the lounge emptying
   fires cannot call `is_admin()`. The lounge is active on this install (`lounge_active = true`,
   `lounge_activate_threshold = 1`), so every upload waits in the lounge.
3. **Persons regions are a derived index.** `piwigo_person_region` is rebuilt from the files
   ([0020](../../../docs/agents/decisions/0020-persons-index-is-derived-the-file-is-the-source-of-truth.md)).
   The deploy rebuilds it on the remote, and `IndexRebuildTest` rebuilds it locally. A DB-only
   column does not survive a full rebuild.

Also: none of the `see` items in research decision 4 is a screen of its own. Each one is injected
into a core admin screen:

```text
item                          injected into (core)                 hook
typetags.groups               admin.php?page=tags                  typetags_admin
core.merge-buttons            admin.php?page=tags (core tpl)       -
typetags.admin-chips          page=photo, page=batch_manager       typetags_admin_photo
provenance.album/apply/wb     page=album&tab=properties            provenance_admin_album
provenance.photo-note         page=photo&tab=properties            provenance_admin_photo
provenance.move-prompt        Batch Manager move panel             provenance_batch_move_panel
photoinfo.core-screens        page=photo + Batch Manager           (core forms)
provenance.history            (inside the album/photo screens)
```

So a literal "read-only copy" means rebuilding parts of four core admin screens outside `admin.php`.

## Access path after the change

This shows where each kind of request is stopped or limited:

```text
request
├── index.php / picture.php / ...   guest  -> login page (guest_access = false)
│                                   normal -> gallery + new read-only pages (R6)
├── ws.php                          guest  -> refused by ws_invoke_allowed, except
│                                             pwg.session.login / getStatus, pwg.getVersion
│                                   others -> method's own gate (admin_only, is_webmaster,
│                                             album visibility per 0044)
├── handbuch.php?f=<file>           guest  -> login page; others -> file (whitelisted types)
├── /handbuch/*  (static)           rewritten to handbuch.php; direct access blocked
├── admin.php                       unchanged: administrator and up (admin.php:27)
└── galleries/, upload/, _data/i/   unchanged: static, reachable by URL (accepted gap)
```

## Matrix changes against the research note

Decision "Read-only pages" below changes these rows for Normal. All other rows stay as the
research note sets them.

| ID | Research target (N) | This design (N) |
|---|---|---|
| core.merge-buttons | see | none |
| typetags.admin-chips | see | none |
| typetags.groups | see | see, on the tag groups page |
| provenance.album | see | see, on the provenance page |
| provenance.apply | see (disabled button) | none |
| provenance.writeback | see (disabled button) | none |
| provenance.photo-note | see | see, on the provenance page |
| provenance.move-prompt | see | none |
| provenance.history | see | see, on the provenance page |
| photoinfo.core-screens | see | none (Normal edits on the picture page, research decision 9) |

## Key Decisions

### Guests are locked out with core's switch

- **Decision:** Set `$conf['guest_access'] = false`. The deploy writes it into the remote config it
  generates (`tools/deploy/pwgdeploy/bootstrap.py`). Locally it goes into the git-ignored
  `local/config/config.inc.php` and is documented. The new decision supersedes
  [0031](../../../docs/agents/decisions/0031-person-names-are-visible-to-guests-as-ordinary-tags.md).
- **Reason:** Core already sends guests to the login page when the switch is off
  (`include/functions_user.inc.php:1484`). No fork code is needed for the pages.
- **Trade-offs:** The local value is not tracked, so a fresh clone runs with guests allowed until
  someone sets it. The tests that assume guest access (0031 cases, guest E2E) change in the same
  slice.

### The API refuses guests through one handler

- **Decision:** One `ws_invoke_allowed` handler in provenance refuses every method to a guest
  except `pwg.session.login`, `pwg.session.getStatus` and `pwg.getVersion`. The deploy preflight
  uses `pwg.getVersion` before it logs in. No core change.
- **Reason:** Fact 1. The pages are closed but the API is not. Provenance is the plugin the others
  already require or sit beside.
- **Trade-offs:** Rejected: a gate in each plugin method, because core's own methods would stay
  open. The handler applies only while provenance is active.

### Static image files stay reachable by URL

- **Decision:** `galleries/`, `upload/` and `_data/i/` stay as they are. Record this as an accepted
  gap.
- **Reason:** Upstream Piwigo serves them statically too. Closing them needs web-server deny rules
  plus PHP proxying, which also touches `i.php`.
- **Trade-offs:** A guest who has a file URL can load the image. `galleries/` paths can be guessed.

### The handbook is served through a login-checking entry

- **Decision:** Add a root `handbuch.php?f=<file>`. It checks for a login, serves only whitelisted
  types (`html`, `css`, `png`) from `handbuch/`, and guards against path traversal. A rewrite sends
  `/handbuch/*` to it, so the pages' relative links keep working. nginx (`.ddev`) and `.htaccess`
  (remote) block direct access to the static files.
- **Reason:** `guest_access` does not reach static HTML. One entry keeps the pages unchanged, so
  `handbuch/tools/check.php` and `shoot.js` keep working.
- **Trade-offs:** Rejected: converting every page to PHP, which changes the handbook tooling. The
  deny rule exists twice (nginx and `.htaccess`) because local and remote run different servers.

### A lounge emptying from a page view inherits in mode keep

- **Decision:** When the inheritance payload arrives before `$user` is loaded, it came from
  `check_lounge()`. Provenance then runs in mode `keep`, ignores request parameters and writes no
  history row.
- **Reason:** Fact 2. `is_admin()` is not available at that point. Mode `keep` still gives freshly
  uploaded photos the album values, because they carry none yet. Only replace/clear and the history
  row go away.
- **Trade-offs:** An admin's page view that empties the lounge is also treated this way. The normal
  admin path, `pwg.images.uploadCompleted`, runs with `$user` loaded and is unchanged.

### Normal users get read-only data pages, not copies of admin screens

- **Decision:** Two public sections, registered through `loc_end_section_init`:
  - **provenance**: `index.php?/provenance/album/<id>` and `?/provenance/photo/<id>`. They show the
    four fields, the photo note and the history.
  - **tag groups**: `index.php?/taggroups`. It shows name, colour, stripes, emoji and member tags.

  They are linked from the album page, the picture page (Herkunft row) and the tags page. They
  respect `forbidden_categories`. Every logged-in user can open them. Controls that exist only on an
  admin form become `none` for Normal (see *Matrix changes*). A new decision supersedes research
  decision 4.
- **Reason:** The `see` items are injections into core admin screens. Copies of those screens
  would follow core's templates through every upstream merge. A Normal user needs the data, and a
  disabled button shows nothing.
- **Trade-offs:** Rejected: re-rendered core admin templates with inputs disabled (large copies of
  core templates). Rejected: letting Normal users into `admin.php` with a fork-local core change and
  a read-only flag (every core admin handler assumes an administrator). Rejected: data inline on the
  album and picture pages (longer pages).

### Apache rules are tested against a local Apache container

- **Decision:** Add a second DDEV service, `apache` (`php:8.4-apache`, `mod_rewrite`,
  `AllowOverride All`), serving the same checkout and database on its own port. The handbook's
  `.htaccess` cases run against it as well as against nginx.
- **Reason:** The remote is all-inkl.com shared hosting and answers `Server: Apache` (checked
  2026-10-11). Locally DDEV runs nginx, so without this an `.htaccess` rule is tested only by hand
  after a deploy.
- **Trade-offs:** all-inkl runs PHP through FPM/CGI, the container through `mod_php`. Rewrite and
  access rules behave the same; one hand check on the remote after the first deploy covers the
  rest. Rejected: switching DDEV to `apache-fpm`, which would stop testing the nginx rules.

### Regions record who drew them in the index, carried like the id

- **Decision:** Add a `drawn_by` column to `piwigo_person_region`. A reindex carries it across with
  the id ([0033](../../../docs/agents/decisions/0033-region-ids-survive-a-reindex.md)). An empty
  value means "drawn by nobody", so only Admin and Webmaster can delete that region. Normal users
  may delete regions where `drawn_by` is their own id. Refines
  [0019](../../../docs/agents/decisions/0019-person-region-permission-model.md) and 0020.
- **Reason:** Fact 3. The MWG region structure has no field for an author. Carrying the value like
  the id keeps 0020's rule: no file content comes from the old index.
- **Trade-offs:** A full rebuild empties every `drawn_by`. On the remote every region is therefore
  "drawn by nobody" after a deploy. This fails closed: Normal users lose a right, they do not gain
  one. Rejected: a custom parallel list in the file's XMP.

### The persons admin screen becomes view-only for admins

- **Decision:** Admins can still open the screen. Rename, delete and rescan are hidden for them,
  and the ws methods behind them check `is_webmaster()`.
- **Reason:** Research decision 8. The deploy's rescan logs in as the install's webmaster, so it is
  not affected.
- **Trade-offs:** None found.

### Normal users edit date and info text, limited by album visibility

- **Decision:** `pwg.photoinfo.setDate` and `pwg.photoinfo.setInfo` lose `admin_only` and get the
  album visibility check from
  [0044](../../../docs/agents/decisions/0044-picture-page-tag-methods-respect-album-permissions.md).
- **Reason:** Research decision 9. Without the visibility check, a Normal user could write to a
  photo in an album they cannot see.
- **Trade-offs:** None found.

### Turn and crop open to every logged-in user who can see the photo

- **Decision:** Replace `is_webmaster()` with "logged in and not a guest" plus the 0044 visibility
  check, in `plugins/photoedit/include/events_public.inc.php` and
  `plugins/photoedit/include/ws_functions.inc.php`. Drop `admin_only` from the method registration
  (`plugins/photoedit/main.inc.php:80`). The warnings, the re-encode note and the disabled reason
  follow the same gate. Supersedes part of
  [0034](../../../docs/agents/decisions/0034-photo-edits-change-the-file.md). Photoedit stays
  inactive on the remote.
- **Reason:** Research decision 10.
- **Trade-offs:** `photoedit_admin` and `photoedit_normal` change from negative cases to positive
  cases. A guest case replaces them as the negative case.

### The two visibility findings are fixed

- **Decision:**
  - `pwg.persons.getList` without `image_id` lists only persons on photos the caller can see
    (`plugins/persons/include/ws_functions.inc.php:188-195`).
  - The admin tagging screen (`plugins/persons/admin/photo.php`) applies the same visibility check
    as the write methods. It does not show regions it cannot save.
- **Reason:** With guests locked out, both still leak to logged-in users: names of people on photos
  the caller cannot see, and a screen that shows regions and then answers 404 on save.
- **Trade-offs:** None found.

### `pwg.provenance.writeBack` keeps no webmaster check

- **Decision:** No change. Record it as a decision not to fix.
- **Reason:** The matrix gives Admin `edit` on `provenance.writeback`.
- **Trade-offs:** An admin can rewrite original files. This is intentional.

### Test accounts reuse the existing roles, plus one for persons

- **Decision:** Use the existing `<plugin>_normal` and `<plugin>_admin` accounts. Add
  `persons_admin` (administrator, not webmaster) to `plugins/persons/tests/Support/TestUsers.php`
  and `create-test-users.php` for the drawn-by and admin-screen cases.
- **Reason:** An admin gate is proven only when a non-webmaster administrator fails to pass it
  ([testing.md](../../../.claude/rules/testing.md), *Test accounts*).
- **Trade-offs:** None found.

## Slices

Each slice brings its own tests and its own decision file. They are listed in implementation order.

| # | Slice | Decision files |
|---|---|---|
| 1 | Guest lockout: `guest_access`, deploy config, ws guest handler, static-file gap recorded | guest lockout (supersedes 0031), static files accepted |
| 2 | Persons admin screen view-only for admins | persons admin screen |
| 3 | Date/info editing for Normal | photoinfo edit for Normal |
| 4 | Turn/crop for logged-in users | photoedit gate (supersedes part of 0034) |
| 5 | Lounge emptying in mode keep | lounge inheritance |
| 6 | Handbook behind login (needs the local Apache container, task 11) | handbook entry |
| 7 | `drawn_by` and own-region delete | drawn by (refines 0019, 0020) |
| 8 | `getList` and admin tagging visibility fixes, writeBack no-fix recorded | visibility fixes, writeBack |
| 9 | Provenance and tag-groups read-only pages | read-only pages (supersedes research decision 4) |

Slices 1-4 change gates only. Slices 5-8 add logic. Slice 9 adds new pages. The order puts the
smallest independent changes first.

## Related documents

- [Research: role permissions](../../../docs/agents/research/2026-10-11-role-permissions.md): matrix and the 11 decisions
- Decisions replaced or refined: [0019](../../../docs/agents/decisions/0019-person-region-permission-model.md), [0020](../../../docs/agents/decisions/0020-persons-index-is-derived-the-file-is-the-source-of-truth.md), [0031](../../../docs/agents/decisions/0031-person-names-are-visible-to-guests-as-ordinary-tags.md), [0033](../../../docs/agents/decisions/0033-region-ids-survive-a-reindex.md), [0034](../../../docs/agents/decisions/0034-photo-edits-change-the-file.md), [0044](../../../docs/agents/decisions/0044-picture-page-tag-methods-respect-album-permissions.md)
- [Deployment rules](../../../.claude/rules/deployment.md): generated remote config, preflight `pwg.getVersion`
- [Handbook rules](../../../.claude/rules/handbook.md): `check.php`, `shoot.js`
- [Plugin test suites](../../../.claude/rules/plugin-test-suites.md): accounts and commands
