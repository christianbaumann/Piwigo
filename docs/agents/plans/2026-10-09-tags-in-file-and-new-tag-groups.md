---
date: 2026-10-09T05:27:14+00:00
git_commit: 51581b04c8224dda119d84b1ab589ac2c221d461
branch: master
topic: "Tags in the image file, and four new tag groups"
tags: [plan, photoinfo, typetags, persons, deploy, tags, keywords]
status: approved
---

# Tags in the Image File, and Four New Tag Groups: Implementation Plan

## Overview

A photo's Piwigo tags are written into its image file as keywords whenever they change. The
remote, which never receives a database (decision 0023), rebuilds them from the files with a
merge-only rescan, the same model photoinfo already uses for the date and info text
(decision 0043). Four new colour groups are added: `Kategorie ?` and `Name ?` (red/white
striped), `Ausstellung` (🖼️) and `Freitext` (✍️). A tag typed in on the spot while tagging lands
in `Freitext`. A committed seed file creates every group and tag locally and on the remote, which
also closes the backlog item "the remote's eight colored tags have no committed seeding script".

The decisions behind it were taken in the 2026-10-08/09 brainstorm (answers recorded inline
below as **Q n**). The look was chosen from drafts at
https://claude.ai/artifact/85qGtTggSq35HHR1P1ufjm (S5, A1, F5, C5).

## Current State Analysis

- **Nothing writes keywords.** The file carries provenance's caption, photoinfo's date and info
  text (`XMP-pwginfo:Info`, `DateEDTF`) and persons' MWG regions plus
  `XMP-iptcExt:PersonInImage`. No `XMP-dc:Subject`, `IPTC:Keywords` or
  `XMP-lr:HierarchicalSubject` anywhere.
- **Core announces no tag change.** `set_tags_of`, `add_tags`, `create_tag`,
  `tag_id_from_tag_name`, `pwg.tags.rename` and `pwg.tags.duplicate` fire no event
  (`admin/include/functions.php:1613,1709,1779,2378`; `include/ws_functions/pwg.tags.php:285,356`).
  `delete_tags` fires after the rows are gone and carries no image ids (`functions.php:1696`);
  `merge_tags` fires before the source links are deleted (`pwg.tags.php:511`).
  `pwg.images.setInfo` fires nothing; photoinfo already re-registers it
  (`plugins/photoinfo/include/events_core_edit.inc.php:88-140`, decision 0039).
- **Plugins write `image_tag` with raw SQL and fire nothing either**:
  `typetags.image.addTag`/`removeTag` (`plugins/typetags/main.inc.php:200,251`) and
  `persons_sync_image_tags()` (`plugins/persons/include/index.inc.php:369-424`).
- **Tag creation on the fly** goes through `get_tag_ids()` → `tag_id_from_tag_name()` from the
  admin tag fields (`data-create="true"` in `picture_modify.tpl:202`,
  `batch_manager_global.tpl:474`, `batch_manager_unit.tpl:221`). No event carries the new id.
- **Typetags** (`plugins/typetags`, submodule, 5 commits ahead of its origin as of 2026-10-09):
  table `piwigo_typetags(id, name, color)` in `utf8mb3`, `tags.id_typetags`, no versioning
  (`update()` re-runs `install()`, `maintain.class.php:20-49`). The colour is applied in nine
  places (see Phase 2). The picture-page JS reads a colour back out of a badge's `style` with a
  regex (`include/events_public.inc.php:302-308`). No ws method lists the groups.
- **Locks**: photoinfo writes through `provenance_exiftool_run()`
  (`plugins/provenance/include/exiftool.inc.php:179`), under provenance's lock only, which is not
  re-entrant. Persons' lock is a different file and re-entrant (`persons_lock_acquire()`).
  Only photoedit takes both, through `photoedit_begin`/`photoedit_end`.
- **Character set**: Piwigo connects with `DB_CHARSET 'utf8'` and both `piwigo_tags` and
  `piwigo_typetags` are `utf8mb3_general_ci` (measured 2026-10-09). 🖼️ (U+1F5BC) needs four bytes
  and cannot be stored as text; ✍️ (U+270D) could.
- **Remote**: FTP and HTTP only, no PHP CLI. Seeding has to go through ws.php. The deploy runs
  `pwg.photoinfo.rescan` after the sync (`tools/deploy/pwgdeploy/bootstrap.py:388`); typetags is
  activated but no tag or group is created.
- **Core's keyword import** (`$conf['use_iptc']`, `functions_metadata.php:300-341`) is off here;
  if it were on, it would replace all of a photo's tags with its IPTC keywords.
- **Tests**: no keyword test anywhere. `CoreTagCrudCharacterizationTest` pins tag CRUD and
  `set_tags` replace. The pre-commit gate runs the typetags, provenance and persons unit suites,
  not photoinfo's (`.githooks/lib.sh`).

## Desired End State

1. Every change to a photo's tags, through any screen or ws method listed in Phase 4, rewrites
   the photo's `XMP-dc:Subject`, `IPTC:Keywords` and `XMP-lr:HierarchicalSubject`
   (`Group|Tag`), and sets `XMP-pwginfo:TagsWritten`. `Ausstellung` and any tag with `?` in its
   name are never written (**Q5, Q12**).
2. A tag created while tagging that has no group and is not a person tag is in `Freitext` before
   the file is written (**Q3**).
3. `pwg.photoinfo.rescan` adds the tags a marked file names (creating missing tags, and groups
   from `HierarchicalSubject`), never removes one, skips files without the marker, and reports
   per photo the tags in the database the file does not name. `pwg.photoinfo.pruneTags` removes
   those; the deploy calls it only with `--prune-tags` (**Q8, 9b, 13a, 2a**).
4. Typetags groups have a `striped` flag and an `emoji`; badges render S5 (striped tab at the
   left edge) and the emoji in front of the name, everywhere a badge is drawn except the public
   tags page's text-only modes.
5. `tools/deploy/tag-groups.json` describes all 12 groups and their tags; the deploy applies it
   before the sync, and `pwg-deploy --seed-tags-only` applies it to any install, idempotently.
6. No one-time "write all" runs. Existing tags reach a file when that photo's tags are next
   edited (**14b**).

Verify: the four suites named per phase are green twice and in reverse order; on the local
install, tag a photo in the photo properties screen and read the file back with
`exiftool -XMP-dc:Subject -XMP-lr:HierarchicalSubject -IPTC:Keywords -XMP-pwginfo:TagsWritten`.

### UI Mockups

Picture page, Schlagworte row (S5 + A1 + F5 on C5, as chosen):

```
Schlagworte  [ Personen ] [ Feste, Bräuche, Jahreskreis ] [▧▧| Name ? ] [ 🖼️ Ausstellung ] [ ✍️ Kirmes 1958 ]
                yellow        olive                        red/white tab,   neutral grey      neutral grey
                                                           red border
```

Typetags plugin page, add/edit group form (new fields marked `+`):

```
 Name     [ Name ?                 ]
 Colour   [ #d00000 ]  (colour wheel)
+Striped  [x] red/white tab at the left edge
+Emoji    [ 🖼️ ]   paste an emoji or type code points, e.g. 1F5BC FE0F; empty for none
          [ Save ]
```

Deploy report (new lines marked `+`):

```
 plugins  typetags, provenance, persons, photoinfo active
+tags     12 groups, 11 tags checked: 4 groups added, 3 tags added, 0 changed
 sync     105 added, 0 deleted
 rescan   105 of 105 photos read
+         tags: 14 added from 9 files; 3 photos have tags their file does not name (prune with --prune-tags)
```

### Key Discoveries

- photoinfo's core-screen hooks are the pattern to extend: snapshot on
  `picture_modify_before_update`, act on `loc_end_picture_modify`
  (`events_core_edit.inc.php:24-50`), `element_set_global_action` (`:61`), and the
  `pwg.images.setInfo` wrapper (`:88-140`). `loc_end_picture_modify` fires on every page load, so
  the snapshot global is the "a save happened" signal.
- `loc_begin_element_set_global` (`batch_manager_global.php:33`) fires before the Batch Manager
  runs its action, so it can take the "before" snapshot for new-tag detection.
- `element_set_global_action('del_tags', …)` follows an inline `DELETE` (`batch_manager_global.php:140-146`);
  `add_tags` follows `add_tags()` (`:126`). Both carry `$collection`.
- `ws_invoke_allowed` (`include/ws_core.inc.php:591`) runs before any ws method with its params,
  usable to read affected photos before `pwg.tags.delete`; re-registering the method (as for
  setInfo) is simpler and is what this plan uses.
- exiftool replaces a list tag when the first `-TAG=value` of the run is applied and appends the
  rest; `-TAG=` alone deletes it. IPTC `Keywords` is limited to 64 bytes per entry and is
  truncated by exiftool; XMP is not. So the rescan reads XMP only.
- photoedit copies every tag of the old file into the new one (`-tagsFromFile … -all:all`,
  `plugins/photoedit/include/pipeline.inc.php:426-432`), so keywords survive an edit; a crop that
  cuts a face away changes the photo's person tags in `photoedit_end`, via
  `persons_photoedit_move_regions()`, which does **not** go through `persons_apply_change()`.
- The persons tag mirror is recognisable only through `piwigo_persons.tag_id` (decision 0031).
- `persons_reindex_image_locked()` → `persons_sync_image_tags()` also runs under
  `pwg.persons.rescan`, which the remote runs after every deploy. A keyword write fired from
  there would write files on the remote and turn every rescan into a gallery-wide write, so the
  persons event must not fire from the reindex path.

## What We're NOT Doing

- **No one-time "write all tags" run** (Q14b). The rescan's marker check (Q2a) keeps unwritten
  files from being read as "no tags".
- **No hook on uploads** (`pwg.images.add`/`addSimple`/`uploadAsync` set tags after
  `loc_end_add_uploaded_file`), nor on core's metadata sync with `use_iptc` on, nor on
  `site_update`'s metadata step. Upload forms here set no tags, and `use_iptc` is off. Recorded
  as a decision.
- **No removal by the rescan itself**; removal is `pwg.photoinfo.pruneTags`, called by the
  deploy only with `--prune-tags`. No admin button (Q8a).
- **No colour in the file.** Colours, the striped flag and emoji come from the seed file.
- **The public tags page's letter/cloud/cumulus modes** keep colouring text only; stripes and
  emoji are not shown there.
- **Provenance's own write-back keeps taking only provenance's lock.** Only photoinfo's writes
  gain the persons lock.
- **No push of the typetags submodule** by the agent (Q9b): the owner pushes.
- **No change to core.** All hooks are plugin-side, plus one new event in `plugins/persons` and
  one in `plugins/typetags`, both fork-owned.

## Implementation Approach

Characterize the core tag paths first (the "cover the ground" rule in `testing.md`), then build
bottom-up: typetags data and rendering, then seeding so the groups exist everywhere, then the
pure keyword layer and the write hooks, then Freitext on top of those hooks, then the read side
and the deploy. Each phase is test-first and commits on its own; the submodule commits of
Phase 2 are made inside `plugins/typetags` and recorded in the superproject.

Single sources of truth:

| Fact | Lives in | Read by |
|---|---|---|
| Local-only rule (`Ausstellung`, names with `?`) | `PHOTOINFO_LOCAL_ONLY_TAGS` + `photoinfo_tag_is_local_only()` in `plugins/photoinfo/include/functions.inc.php` | writer, rescan, prune, tests |
| Freitext group name | `PHOTOINFO_FREITEXT_GROUP` in the same file | Freitext assignment; a deploy test asserts `tag-groups.json` has a group of that name |
| Group colours, striped, emoji, tag→group | `tools/deploy/tag-groups.json` | `seed_tags`, tests |
| Hierarchy separator `|` | `PHOTOINFO_HIERARCHY_SEPARATOR` | writer and parser |
| Rescan/prune chunk | `PHOTOINFO_RESCAN_MAX_CHUNK` (existing) | deploy via `php_value()` |

---

## Phase 1: Characterize the core tag paths as they are

### Overview

Pin today's behaviour of every core path Phase 4 hooks, before any hook exists. All cases are
`[ERR]` (oracle = current implementation), each watched going red once by breaking what it pins.

### Changes Required

#### [x] 1. New characterization test
**File**: `plugins/provenance/tests/Integration/CoreTagAssignmentCharacterizationTest.php`
**Changes**: over ws.php and the admin forms, like `CoreTagCrudCharacterizationTest`
(reuse its `linkTag` helper shape; teardown removes the tags it created).

- [x] `testBatchManagerAddTagsAppendsAndKeepsExisting` — `add_tags` on two photos, one already
  tagged: both carry the new tag, the old one stays `[ERR]`
- [x] `testBatchManagerAddTagsCreatesATypedName` — a non-`~~id~~` value creates the tag `[ERR]`
- [x] `testBatchManagerDelTagsRemovesOnlyTheSelectedTag` `[ERR]`
- [x] `testSetInfoTagListCreatesANewTag` — `tag_list[]=NewName` creates and links it `[ERR]`
- [x] `testSetInfoTagIdsAppendModeKeepsExisting` (`multiple_value_mode=append`) `[ERR]`
- [x] `testDuplicateCopiesEveryImageLink` — `pwg.tags.duplicate` `[ERR]`
- [x] `testPhotoPropertiesTypedNameCreatesTheTag` — `tags[]=NewName` on the form `[ERR]`
- [x] `testATagCreatedOnTheFlyHasNoGroup` — `id_typetags` is `NULL` `[ERR]`

### Success Criteria

#### Automated Verification:
- [x] Green twice and in reverse order:
  `ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; plugins/provenance/vendor/bin/phpunit --testsuite integration --configuration plugins/provenance/phpunit.xml --filter CoreTagAssignment'`
- [x] Each case watched red once (break the pinned fact in the test's expectation, run, revert);
  results listed in `docs/agents/TESTING.md` beside the existing core characterization rows

#### Manual Verification:
- [x] None

**Implementation Note**: commit on its own before Phase 2.

---

## Phase 2: Typetags — striped flag and emoji per group

### Overview

Two columns, two form fields, one pure style function used by every renderer, a list method and
an update method for the seed. All commits inside the submodule.

### Changes Required

#### [ ] 1. Schema
**File**: `plugins/typetags/maintain.class.php`
**Changes**: in `install()`, guarded by `SHOW COLUMNS … LIKE` like `id_typetags`:
`striped TINYINT(1) NOT NULL DEFAULT 0`, `emoji VARCHAR(64) NOT NULL DEFAULT ''`.
`uninstall()` already drops the table.

#### [ ] 2. Pure functions
**File**: `plugins/typetags/include/functions.inc.php`

```php
define('TYPETAGS_STRIPE_COLOR', '#fff');
define('TYPETAGS_STRIPED_TEXT', '#000');
define('TYPETAGS_EMOJI_MAX_CODEPOINTS', 8);

/** Code points ("1F5BC FE0F") from pasted emoji or typed hex; '' for empty; false when invalid. */
function typetags_emoji_codepoints($input) {}
/** "&#x1F5BC;&#xFE0F;" from stored code points; '' for ''. */
function typetags_emoji_html($codepoints) {}
/** "\1F5BC\FE0F" for a CSS content: value; '' for ''. */
function typetags_emoji_css($codepoints) {}
/** The inline style of one badge: S5 when striped, today's style otherwise. */
function typetags_badge_style($color, $striped) {}
```

S5: `background: repeating-linear-gradient(45deg, C 0 6px, #fff 6px 12px) left/18px 100% no-repeat, #fff;
color:#000; padding:2px 8px 2px 24px; border:1px solid C; border-radius:12px; display:inline-block;`
Non-striped returns exactly today's string (`events_public.inc.php:76-77`), so nothing else
moves.

#### [ ] 3. Renderers (every place a colour is applied)
**Files**: `include/events_public.inc.php`, `include/events_admin.inc.php`, `admin.php`,
`template/admin.tpl`, `template/tags.tpl`, `template/tags.js`, `main.inc.php`

- [ ] (a) `typetags_render()` (`render_tag_name`): style from `typetags_badge_style()`, name
  prefixed by `typetags_emoji_html()`; cache gains `striped`, `emoji`
- [ ] (b) picture-page `+` badges: server side as (a); each badge carries `data-tag-style` and
  `data-tag-emoji`
- [ ] (c, d) badge JS after add/remove: build from `data-tag-style`/`data-tag-emoji` instead of
  the `background-color` regex (`events_public.inc.php:231, 302-328`)
- [ ] (g) admin photo/Batch Manager chips: the `TYPETAGS_CSS` rule gets the S5 background and a
  `::before { content: "<emoji>" }`
- [ ] (h) plugin page list and edit preview
- [ ] (i) admin tags page swatches and popin (`tags.tpl`, `tags.js` `.css('background', …)`)
- [ ] Every SELECT that reads `color` also reads `striped, emoji`
  (`events_public.inc.php:26-29, 40-47, 151-156, 388-396`; `events_admin.inc.php:21, 30-38, 80-88`;
  `admin.php:164, 186-190`)
- [ ] Not changed: (e, f) public tags page text-only and cumulus modes

#### [ ] 4. Admin form
**Files**: `admin.php`, `template/admin.tpl`, `language/{de_DE,en_UK}/plugin.lang.php`
**Changes**: `Striped`/`Gestreift` checkbox and `Emoji` text field in add and edit forms; the
value passes through `typetags_emoji_codepoints()`; an invalid value is refused with
`Invalid emoji`/`Ungültiges Emoji`, like an invalid colour.

#### [ ] 5. Web-service methods
**File**: `plugins/typetags/main.inc.php`

- [ ] `typetags.type.list` — admin_only; returns `[{id, name, color, striped, emoji}]`
- [ ] `typetags.type.update` — admin_only, post_only, pwg_token; `typetag_id`, optional
  `typetag_color`, `striped`, `emoji`; refuses an unknown id and invalid values
- [ ] `typetags.type.add` — accepts optional `striped`, `emoji`; returns them
- [ ] An event `trigger_notify('typetags_tags_regrouped', $tag_ids)` fired after
  `typetags.tags.setType`, a group edit (all its tags) and a group delete (all its former tags),
  so photoinfo can rewrite the `HierarchicalSubject` of affected photos

### Success Criteria

#### Automated Verification:
- [ ] `ddev exec plugins/typetags/vendor/bin/phpunit --testsuite unit --configuration plugins/typetags/phpunit.xml`
- [ ] `ddev exec bash -c 'set -a; . local/config/typetags-test.env; set +a; plugins/typetags/vendor/bin/phpunit --testsuite integration --configuration plugins/typetags/phpunit.xml'`
- [ ] `ddev exec bash -c 'set -a; . local/config/typetags-test.env; set +a; cd plugins/typetags && npx playwright test'`
- [ ] `rm -rf _data/templates_c/*` before the integration and E2E runs (prefilter code changed)
- [ ] `ddev exec php -l` on every changed PHP file

#### Manual Verification:
- [ ] The S5 badge and emoji look as in the drafts on the picture page, the photo properties
  chips and the admin tags page (hand-check ledger entry: legibility has no oracle)
- [ ] Owner pushes `plugins/typetags` to `Piwigo-Colored-Tags` (Q9b)

**Implementation Note**: pause for the owner's look before Phase 3.

---

## Phase 3: Seeding the groups and tags

### Overview

One data file, one deploy step, idempotent, applied over ws.php locally and on the remote.

### Changes Required

#### [ ] 1. Data file
**File**: `tools/deploy/tag-groups.json`

```json
{
  "groups": [
    {"name": "Personen", "color": "#FFFFB6"},
    {"name": "Feste, Bräuche, Jahreskreis", "color": "#D8D900"},
    {"name": "Arbeiten", "color": "#FFCA4F"},
    {"name": "Gewerbe", "color": "#BE6CB7"},
    {"name": "Vereine, Gruppierungen", "color": "#007DAD"},
    {"name": "Friedhof, Kirche, Kapelle", "color": "#77A600"},
    {"name": "Stationen durch das Leben", "color": "#FF759A"},
    {"name": "Häuser, Ortsansichten", "color": "#938953"},
    {"name": "Kategorie ?", "color": "#d00000", "striped": true},
    {"name": "Name ?", "color": "#d00000", "striped": true},
    {"name": "Ausstellung", "color": "#e4e6e3", "emoji": "1F5BC FE0F"},
    {"name": "Freitext", "color": "#e4e6e3", "emoji": "270D FE0F"}
  ],
  "tags": [
    {"name": "Personen", "group": "Personen"},
    "... one row per existing category tag, each in its same-named group ...",
    {"name": "Kategorie ?", "group": "Kategorie ?"},
    {"name": "Name ?", "group": "Name ?"},
    {"name": "Ausstellung", "group": "Ausstellung"}
  ]
}
```

`tools/` is never published (decision 0022); the deploy reads the file locally.

#### [ ] 2. Seed step
**File**: `tools/deploy/pwgdeploy/seed.py` (new), `bootstrap.py`, `cli.py`

```python
@dataclass(frozen=True)
class SeedResult:
    groups_added: int; groups_changed: int; tags_added: int; tags_regrouped: int

def load_tag_groups(path: Path) -> TagGroups: ...       # validates: unique names, colour, emoji, every tag's group exists
def plan_seed(wanted: TagGroups, groups: list, tags: list) -> SeedPlan: ...   # pure: what to add/update
def seed_tags(client, base_url: str, token: str, wanted: TagGroups) -> SeedResult: ...
```

- [ ] Reads `typetags.type.list` and `pwg.tags.getAdminList`, matches by name, then
  `typetags.type.add`/`type.update`, `pwg.tags.add`, `typetags.tags.setType`; never deletes
- [ ] Re-reads both lists afterwards and raises if anything differs from the file
- [ ] `bootstrap.run()`: after `activate_plugins`, before `sync`; report line `tags …`
- [ ] `cli.py`: `--seed-tags-only` runs login + seed only (no upload, no FTP connection), so the
  same command seeds the local install from a credential file whose `site.url` is
  `http://piwigo.ddev.site` (README documents it; plain http avoids Python not trusting mkcert)

### Success Criteria

#### Automated Verification:
- [ ] `cd tools/deploy && uv run pytest`
- [ ] Local seed applied: `uv run pwg-deploy --seed-tags-only deploy.ddev.json`, run twice; second
  run reports 0 added, 0 changed
- [ ] `ddev mysql -e "select name, color, striped, emoji from piwigo_typetags"` shows 12 rows

#### Manual Verification:
- [ ] None beyond the above

---

## Phase 4: photoinfo writes the tags into the file

### Overview

A pure layer that turns a photo's tag rows into argfile lines, a writer under both locks, and a
hook on every path that changes tags.

### Changes Required

#### [ ] 1. Pure layer
**File**: `plugins/photoinfo/include/functions.inc.php`, `exiftool/pwginfo.config`

```php
define('PHOTOINFO_SUBJECT_TAG', 'XMP-dc:Subject');
define('PHOTOINFO_KEYWORDS_TAG', 'IPTC:Keywords');
define('PHOTOINFO_HIERARCHY_TAG', 'XMP-lr:HierarchicalSubject');
define('PHOTOINFO_TAGS_MARKER_TAG', 'XMP-pwginfo:TagsWritten');
define('PHOTOINFO_HIERARCHY_SEPARATOR', '|');
define('PHOTOINFO_LOCAL_ONLY_TAGS', array('Ausstellung'));
define('PHOTOINFO_FREITEXT_GROUP', 'Freitext');

function photoinfo_tag_is_local_only($name) {}         // in PHOTOINFO_LOCAL_ONLY_TAGS, or contains '?'
/** @param array $tags rows: name, group (or null) @return array name list, hierarchy list; sorted, deduplicated, local-only dropped */
function photoinfo_file_keywords($tags) {}
/** Argfile lines: charset, each list replaced (empty list → "-TAG="), marker "-XMP-pwginfo:TagsWritten=1". */
function photoinfo_build_tags_argfile($keywords) {}
```

- A group name containing `|` contributes no hierarchy entry for its tags (flat only).
- `pwginfo.config` declares `TagsWritten`; the existing config/URI unit test is extended.

#### [ ] 2. Writer under both locks
**File**: `plugins/photoinfo/include/writer.inc.php`

```php
function photoinfo_image_tags($image_id) {}          // name + group name, one query
function photoinfo_write_tags($image_ids) {}         // returns array(id => array('ok','message'))
function photoinfo_with_persons_lock($db_path, $fn) {}  // persons_lock_acquire() when persons is active
```

- [ ] `photoinfo_write_tags()` builds the argfile and calls `provenance_exiftool_run()` inside
  `photoinfo_with_persons_lock()` (persons lock first, re-entrant, then provenance's)
- [ ] `photoinfo_write_file()` (date and info) runs inside the same wrapper (Q7a)
- [ ] Skips a photo whose provenance lock is held by photoedit in this request
  (`provenance_photoedit_held_locks()`); `photoedit_end` handles it (below)

#### [ ] 3. Hooks
**Files**: `plugins/photoinfo/main.inc.php`, new `plugins/photoinfo/include/events_tags.inc.php`

| Path | Hook | Photos to write |
|---|---|---|
| Photo properties save | existing snapshot on `picture_modify_before_update` gains the tag ids; `loc_end_picture_modify` compares | that photo, if its tag ids changed |
| Batch Manager `add_tags`/`del_tags` | `element_set_global_action` | `$collection` |
| `pwg.images.setInfo` (Batch Manager unit) | existing wrapper | that photo, if its tag ids changed |
| `typetags.image.addTag`/`removeTag` | re-registered at `ws_add_methods` NEUTRAL+10 around typetags' callback | that photo |
| `pwg.tags.rename`, `pwg.tags.duplicate` | re-registered | every photo with the tag (after) |
| `pwg.tags.merge`, `pwg.tags.delete` | re-registered | every photo with any of the tags, read before the call |
| `typetags_tags_regrouped` (Phase 2) | event | every photo with those tags |
| persons region add/remove, rename, delete | new `persons_tags_changed($image_ids)` (below) | those photos |
| photoedit save | `photoedit_end` at NEUTRAL+10 (after provenance and persons release), when `$ok` | that photo |

- [ ] Failures are reported like date writes: `photoinfo_report_core_edit()` on admin pages;
  the ws wrappers add `tags_written`/`tags_message` to the answer
- [ ] The re-registering of ws methods goes through one helper,
  `photoinfo_wrap_method($service, $method, $callback)`, shared with the setInfo wrapper

#### [ ] 4. Persons event
**File**: `plugins/persons/include/index.inc.php`
**Changes**: `persons_sync_image_tags()` returns whether it changed anything, and
`persons_reindex_image_locked()` passes it through. `persons_apply_change()` fires
`trigger_notify('persons_tags_changed', array($image_id))` when it did; `persons_rename_person()`
and `persons_delete_person()` fire it once with every affected photo. Rescans and reindexes
fire nothing (see Key Discoveries).

#### [ ] 5. Gate
**File**: `.githooks/lib.sh`, `tools/test-hooks.sh`
**Changes**: add photoinfo's unit suite to `UNIT_SUITES` (Boy Scout: the plugin now carries
more pure logic than the gated ones); the self-test's runner check covers it.

### Success Criteria

#### Automated Verification:
- [ ] `ddev exec plugins/photoinfo/vendor/bin/phpunit --testsuite unit --configuration plugins/photoinfo/phpunit.xml`
- [ ] `ddev exec bash -c 'set -a; . local/config/photoinfo-test.env; set +a; plugins/photoinfo/vendor/bin/phpunit --testsuite integration --configuration plugins/photoinfo/phpunit.xml'`
- [ ] Persons unit + integration suites (regression: event added, return value changed)
- [ ] photoedit integration suite (regression: `photoedit_end` ordering, `ExclusionTest`)
- [ ] `bash tools/test-hooks.sh`

#### Manual Verification:
- [ ] Tag a photo in the photo properties screen; `exiftool` shows the keywords and marker

---

## Phase 5: Freitext

### Overview

On the same hooks as Phase 4, a tag that is new after the save, has no group and is not a
person tag is put into `Freitext`, before the file is written.

### Changes Required

#### [ ] 1. Detection
**File**: `plugins/photoinfo/include/events_tags.inc.php`

```php
function photoinfo_max_tag_id() {}                          // snapshot before a save
/** Pure: ids above $max_before with no group and not a person tag. */
function photoinfo_new_freitext_tags($rows, $max_before, $person_tag_ids) {}
function photoinfo_assign_freitext($tag_ids) {}              // UPDATE tags SET id_typetags = <Freitext id>; no-op if the group is missing
```

- [ ] Snapshots: `picture_modify_before_update`, `loc_begin_element_set_global`, the setInfo wrapper
- [ ] Runs before `photoinfo_write_tags()` in the same handler
- [ ] `pwg.tags.add` and `pwg.tags.duplicate` (tags admin page) do **not** assign Freitext: a
  tag created on the admin tags page is deliberate and gets its group there

### Success Criteria

#### Automated Verification:
- [ ] photoinfo unit and integration suites as in Phase 4
- [ ] photoinfo E2E: `ddev exec bash -c 'set -a; . local/config/photoinfo-test.env; set +a; cd plugins/photoinfo && npx playwright test'`

#### Manual Verification:
- [ ] Type a new name into the photo properties tag field; the badge shows ✍️ on grey

---

## Phase 6: Rescan, prune, deploy

### Overview

The read side: merge-only rescan with a per-photo report, a separate prune method, and the
deploy calling both.

### Changes Required

#### [ ] 1. Reading
**Files**: `plugins/photoinfo/include/functions.inc.php`, `rescan.inc.php`

- [ ] `photoinfo_read_file_tags()` also asks for `-XMP-dc:Subject -XMP-lr:HierarchicalSubject -XMP-pwginfo:TagsWritten`
- [ ] `photoinfo_parse_rescan_xml()` returns `subject` (list), `hierarchy` (list), `tags_marker` (bool)
- [ ] Pure `photoinfo_rescan_tags($file_tags, $db_tags)` → `array('link' => [name => group|null], 'not_in_file' => [names])`;
  local-only names are neither linked nor reported; nothing when the marker is absent

#### [ ] 2. Applying
**File**: `plugins/photoinfo/include/rescan.inc.php`

- [ ] For each `link`: `tag_id_from_tag_name()`; when the tag has no group and a group is named,
  set it (create the group with typetags' default colour if missing); insert the link
- [ ] No keyword write, no Freitext assignment, no persons event from here
- [ ] Answer: existing `scanned`, `failed` plus `tags_added` (int) and `not_in_file` (`{id: [names]}`, `[]` when empty)

#### [ ] 3. Prune
**Files**: `main.inc.php`, `ws_functions.inc.php`

- [ ] `pwg.photoinfo.pruneTags`: admin_only, post_only, pwg_token, `image_ids` (≤ `PHOTOINFO_RESCAN_MAX_CHUNK`);
  re-reads each file, removes the `not_in_file` tags of marked files; answers `removed` and `failed`

#### [ ] 4. Deploy
**Files**: `tools/deploy/pwgdeploy/bootstrap.py`, `cli.py`, `tests/fakes.py`, `README.md`

- [ ] `parse_rescan` reads the two new fields (`RescanResult.tags_added`, `.not_in_file`)
- [ ] `--prune-tags`: after the rescan, `pwg.photoinfo.pruneTags` over the photos with
  `not_in_file` entries, in chunks
- [ ] Report lines as in the mockup; a prune never fails the deploy (like the rescan)
- [ ] `FakeGallery` answers the new fields, `pruneTags`, `typetags.type.list/add/update`,
  `pwg.tags.getAdminList/add`, `typetags.tags.setType`

### Success Criteria

#### Automated Verification:
- [ ] photoinfo unit and integration suites
- [ ] `cd tools/deploy && uv run pytest`

#### Manual Verification:
- [ ] Next real deploy: report lines read as the mockup; spot-check one photo's tags on the remote

---

## Phase 7: Records

### Changes Required

- [ ] Decisions (`docs/agents/decisions/`):
  - `0044-a-photos-tags-travel-in-its-file.md` — fields, marker, local-only rule, merge-only rescan, prune by flag
  - `0045-new-tags-typed-while-tagging-go-to-freitext.md`
  - `0046-typetags-emoji-are-stored-as-code-points.md` — utf8mb3, no charset migration
  - `0047-tag-writes-take-the-persons-lock-first.md` — and provenance's write-back does not
  - `0048-uploads-and-iptc-import-do-not-write-tags.md`
- [ ] `CLAUDE.md`: photoinfo bullet mentions tags; persons bullet mentions its one new event
  (still no core change); typetags bullet mentions the striped/emoji columns
- [ ] `.claude/rules/plugin-test-suites.md`: new FixtureBuilder helpers and seed scenarios
- [ ] `.claude/rules/deployment.md`: the seed step, `--seed-tags-only`, `--prune-tags`
- [ ] `docs/agents/TESTING.md`: Phase 1 red-runs, photoinfo keyword mutant table, hand-check
  rows (badge legibility; other tools reading the keywords), non-coverage rows (uploads,
  `use_iptc`, public tags page modes)
- [ ] `docs/backlog.md`: close "save labels/tags in the image meta data" and "eight colored
  tags have no seeding script"; update the typetags "ahead of origin" count
- [ ] `handbuch/`: the tags page explains `Kategorie ?`, `Name ?`, `Ausstellung` (stays local)
  and Freitext; re-shoot only the shots whose screen changed (`.claude/rules/handbook.md`),
  then `ddev exec php handbuch/tools/check.php`
- [ ] Mutation table for `photoinfo_file_keywords()`, `photoinfo_build_tags_argfile()`,
  `photoinfo_tag_is_local_only()`, `photoinfo_rescan_tags()`, `photoinfo_new_freitext_tags()`,
  `typetags_badge_style()`, `typetags_emoji_codepoints()` (`.claude/rules/mutation-testing.md`)

---

## Testing Strategy

Technique tags per `.claude/rules/test-design.md`. Fixtures copy gallery images
(`FixtureBuilder::createTestImage()`), never a real scan.

### Unit Tests

**typetags** (`plugins/typetags/tests/Unit/`)
- [ ] `BadgeStyleTest::testANonStripedGroupKeepsTodaysStyle` — byte-equal to the old string `[HAPPY]`
- [ ] `BadgeStyleTest::testAStripedGroupGetsTheTabAndBorderInItsColour` `[HAPPY]`
- [ ] `BadgeStyleTest::testStripedTextIsAlwaysBlack` — for a dark and a light colour `[ECP]`
- [ ] `EmojiCodepointsTest` — pasted 🖼️ → `1F5BC FE0F`; typed `1f5bc fe0f` normalised; `U+1F5BC` accepted `[ECP]`; empty → `''` `[BVA]`; `110000` → false, `10FFFF` → valid `[BVA]`; 8 code points valid, 9 → false `[BVA]`; letters/garbage → false `[NEG]`; ZWJ sequence kept intact `[ERR]`
- [ ] `EmojiRenderTest` — html and css forms of one and two code points; `''` renders nothing `[HAPPY]`/`[BVA]`

**photoinfo** (`plugins/photoinfo/tests/Unit/`)
- [ ] `TagKeywordsTest::testGroupedTagsGetAHierarchyEntry` `[HAPPY]`
- [ ] `…::testAnUngroupedTagIsFlatOnly` (person names) `[ECP]`
- [ ] `…::testAusstellungIsNeverWritten` `[NEG]`
- [ ] `…::testANameWithAQuestionMarkIsNeverWritten` — `Name ?`, `Kategorie ?`, `Wer?` `[ECP]`
- [ ] `…::testAGroupNameWithThePipeGivesNoHierarchyEntry` `[ERR]`
- [ ] `…::testOutputIsSortedAndDeduplicated` `[ECP]`
- [ ] `…::testNoTagsGivesEmptyLists` `[BVA]`
- [ ] `BuildTagsArgfileTest::testAnEmptyListDeletesEachField` — `-XMP-dc:Subject=` etc. `[BVA]`
- [ ] `…::testEveryKeywordIsOneLinePerField` `[HAPPY]`
- [ ] `…::testTheMarkerIsAlwaysSet` — also for an empty list `[DT]`
- [ ] `…::testACommaInANameStaysOneKeyword` — `Feste, Bräuche, Jahreskreis` `[ERR]`
- [ ] `…::testTheConfigDeclaresTheMarker` (extends the existing URI test) `[HAPPY]`
- [ ] `RescanParseTest` additions — lists of 0, 1, n entries `[BVA]`; marker absent/present `[ECP]`; another namespace's `Subject` ignored `[NEG]`
- [ ] `RescanTagsTest` (decision table: marker × in file × in DB × local-only × has group) `[DT]`; no marker → nothing at all `[NEG]`; hierarchy entry without matching Subject still links `[ERR]`; `Group|Tag|More` splits at the first separator `[BVA]`
- [ ] `FreitextTest` — id == max not new, max+1 new `[BVA]`; grouped new tag skipped `[ECP]`; person tag skipped `[NEG]`; no new tags → empty `[BVA]`
- [ ] `LocalOnlyRuleTest` reads `tag-groups.json` and asserts every group whose tags are local-only is named in `PHOTOINFO_LOCAL_ONLY_TAGS` or has `?` (no transcribed copy) `[ECP]`

**deploy** (`tools/deploy/tests/`)
- [ ] `test_seed.py` — load: duplicate group `[NEG]`, tag in unknown group `[NEG]`, bad colour `[NEG]`, bad emoji `[NEG]`; plan: empty install adds all `[HAPPY]`, seeded install plans nothing `[ST]`, changed colour updates `[ECP]`, a group that exists only remotely is left alone `[NEG]`; verify step raises on a mismatch `[NEG]`
- [ ] `test_seed.py::test_the_freitext_group_exists_in_the_file` — reads `PHOTOINFO_FREITEXT_GROUP` with `php_value`-style regex `[HAPPY]`
- [ ] `test_bootstrap.py` — seed runs after activation and before the sync `[ST]`; a run without typetags skips the seed `[NEG]`; `parse_rescan` new fields, `[]` for empty `not_in_file` `[BVA]`, unknown shape raises `[NEG]`; `--prune-tags` absent → no prune call `[DT]`, present → only photos with `not_in_file` `[DT]`; prune failure does not fail the run `[NEG]`
- [ ] `test_cli.py` — `--seed-tags-only` opens no FTP connection and uploads nothing `[NEG]`

#### Regression — affected existing tests
- [ ] typetags: all unit, integration (`PicturePageSourceTest`, `PluginActivationTest`, `MalformedColorRenderingTest`) and E2E (`rendering.spec.js`, `assign.spec.js`, `remove.spec.js`)
- [ ] photoinfo: `BuildArgfileTest`, `CoreEditTest`, `RescanTest`, `SyncMetadataTest`
- [ ] persons: `ReindexTest`, `WriteRegionsTest`, `AddRegionTest`, `DeleteRegionTest`, `PersonAdminApiTest`, `IndexRebuildTest`
- [ ] photoedit: `ApplyRegionsTest`, `ExclusionTest`, `ApplyTurnTest`
- [ ] provenance: `CoreTagCrudCharacterizationTest`, Phase 1's new test

### Integration Tests

**typetags** (`tests/Integration/`)
- [ ] `GroupStyleTest::testAStripedGroupRendersTheTabOnThePicturePage` `[HAPPY]`
- [ ] `…::testAnEmojiRendersBeforeTheName` `[HAPPY]`
- [ ] `TypeListUpdateTest` — list as admin `[HAPPY]`; normal user and guest refused `[NEG]`; update unknown id `[NEG]`; update without token `[NEG]`; GET refused `[NEG]`; invalid emoji `[NEG]`
- [ ] `SchemaUpgradeTest` — `install()` twice keeps rows and adds the columns once `[ST]`

**photoinfo** (`tests/Integration/`)
- [ ] `TagWriteTest` — one row per hooked path in the Phase 4 table: the file's Subject/Hierarchy/Keywords/marker match the DB afterwards `[HAPPY]`
- [ ] `…::testRemovingTheLastTagLeavesTheMarkerAndNoKeywords` `[BVA]`
- [ ] `…::testAusstellungStaysOutOfTheFile` `[NEG]`
- [ ] `…::testAnIptcKeywordOver64BytesDoesNotFailTheWrite` (XMP keeps it whole) `[BVA]`
- [ ] `…::testUmlautsSurviveInIptcAndXmp` `[ERR]`
- [ ] `…::testRenameRewritesEveryPhotoWithTheTag` `[HAPPY]`
- [ ] `…::testDeleteRewritesThePhotosThatHadTheTag` `[HAPPY]`
- [ ] `…::testRegroupingRewritesTheHierarchy` `[HAPPY]`
- [ ] `…::testAReadOnlyFileReportsTheFailureAndKeepsTheDatabase` `[NEG]`
- [ ] `…::testAPersonsRescanWritesNoFile` — mtime unchanged `[NEG]`
- [ ] `…::testAPersonsRegionAddWritesTheName` (persons active) `[HAPPY]`
- [ ] `…::testAPhotoeditCropThatCutsAFaceRewritesTheKeywords` (persons, photoedit active) `[ST]`
- [ ] `…::testATagWriteWaitsForAHeldPersonsLock` — child process holds the persons lock, write waits (ExclusionTest shape) `[ERR]`
- [ ] `FreitextAssignTest` — photo properties, Batch Manager, setInfo `tag_list`: new tag in Freitext and in the file as `Freitext|Name` `[HAPPY]`; `pwg.tags.add` leaves it ungrouped `[NEG]`; Freitext group missing → ungrouped, write still succeeds `[NEG]`
- [ ] `RescanTest` additions — adds tags of a marked file `[HAPPY]`; unmarked file adds nothing `[NEG]`; never removes `[ST]`; creates a missing tag and group `[HAPPY]`; reports `not_in_file`, excluding local-only `[DT]`; rescan writes no file (mtime) `[NEG]`
- [ ] `PruneTagsTest` — removes only `not_in_file` of a marked file `[HAPPY]`; unmarked file untouched `[NEG]`; local-only never removed `[NEG]`; chunk 10 ok, 11 refused `[BVA]`; auth/token/GET refused `[NEG]`

### End-to-End Tests

- [ ] typetags `rendering.spec.js::a striped group paints the tab and border at real size` — computed `background-image` and `border-color` `[HAPPY]`
- [ ] typetags `assign.spec.js::a striped badge keeps its stripes after add and remove` (JS rebuild path) `[ST]`
- [ ] photoinfo `tags-freitext.spec.js::a name typed in the photo properties tag field shows the ✍️ badge on the picture page` `[HAPPY]`

### Manual Testing Steps
1. Look at S5 and the emoji on the picture page in modus, light and dark (legibility; ledger).
2. Open one written file in digiKam or Lightroom and check the keywords and hierarchy (ledger: no local oracle for third-party readers).

### Test Commands

```bash
# Unit
ddev exec plugins/typetags/vendor/bin/phpunit --testsuite unit --configuration plugins/typetags/phpunit.xml
ddev exec plugins/photoinfo/vendor/bin/phpunit --testsuite unit --configuration plugins/photoinfo/phpunit.xml
ddev exec plugins/persons/vendor/bin/phpunit --testsuite unit --configuration plugins/persons/phpunit.xml
cd tools/deploy && uv run pytest

# Integration
ddev exec bash -c 'set -a; . local/config/typetags-test.env; set +a; plugins/typetags/vendor/bin/phpunit --testsuite integration --configuration plugins/typetags/phpunit.xml'
ddev exec bash -c 'set -a; . local/config/photoinfo-test.env; set +a; plugins/photoinfo/vendor/bin/phpunit --testsuite integration --configuration plugins/photoinfo/phpunit.xml'
ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; plugins/persons/vendor/bin/phpunit --testsuite integration --configuration plugins/persons/phpunit.xml'
ddev exec bash -c 'set -a; . local/config/photoedit-test.env; set +a; plugins/photoedit/vendor/bin/phpunit --testsuite integration --configuration plugins/photoedit/phpunit.xml'
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; plugins/provenance/vendor/bin/phpunit --testsuite integration --configuration plugins/provenance/phpunit.xml'

# E2E
ddev exec bash -c 'set -a; . local/config/typetags-test.env; set +a; cd plugins/typetags && npx playwright test'
ddev exec bash -c 'set -a; . local/config/photoinfo-test.env; set +a; cd plugins/photoinfo && npx playwright test'

# Gate
bash tools/test-hooks.sh
```

## Performance Considerations

One exiftool run per affected photo (~0.2-0.4 s). A rename of a tag on all 105 photos, or a
Batch Manager action over the whole gallery, takes up to ~40 s in one request; the date path
already accepts the same cost (`photoinfo_element_set_global_action`). Rows are stored before
the first file is written, so a request that runs out of time leaves the database correct and
only some files behind, which the next edit of those photos repairs. The deploy's rescan reads
three more tags in the same exiftool call it already makes.

## Migration Notes

- Typetags columns are added by `install()` on the next activation/update; existing groups get
  `striped = 0`, `emoji = ''`.
- Local: run `--seed-tags-only` once after Phase 3. Remote: the next deploy seeds.
- No file is written until a photo's tags change. Rolling back: deactivate photoinfo's hooks by
  reverting; keywords already in files are harmless to Piwigo while `use_iptc` stays off.

## References

- Brainstorm drafts: https://claude.ai/artifact/85qGtTggSq35HHR1P1ufjm
- Decisions 0020, 0023, 0026, 0031, 0034, 0036, 0038-0043
- Pattern: `plugins/photoinfo/include/events_core_edit.inc.php:88-140`
- Lock pattern: `plugins/provenance/include/events_photoedit.inc.php`, `plugins/persons/include/events_photoedit.inc.php`
- Deploy rescan: `tools/deploy/pwgdeploy/bootstrap.py:388-470`
