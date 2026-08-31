---
date: 2026-08-31T09:48:20+00:00
git_commit: a37a19cb1f2935fffcca8d9b11e4ab5e5703f14e
branch: master
topic: "German end-user documentation with screenshots for albums, photos, text, tags and persons"
tags: [plan, documentation, german, screenshots, albums, tags, persons, provenance, testing]
status: implemented
---

# German end-user handbook Implementation Plan

## Overview

A German end-user handbook as plain HTML under `docs/handbuch/`, covering the five core
workflows: creating an album with its description, adding photos, adding text to photos,
tagging with tags, tagging persons. Screenshots are taken from a generated demo album, not
from the real gallery. Three prerequisites come first: the install is cleaned of leftover
E2E state, the untranslated German strings the handbook screenshots are fixed, and the four
core workflows that today have no test at any layer get characterization coverage.

Built on `docs/agents/research/2026-08-31-german-end-user-documentation.md`. That document's
six open questions were all decided; this plan implements those decisions and does not
re-open them.

## Current State Analysis

All five workflows exist and work. What is missing is documentation, and three things stand
in its way.

**No publishable photo exists.** All 105 gallery images are recovered personal family scans
of identifiable private people. Every plugin fixture is a byte copy of one of them
(`FixtureBuilder::createTestImage()` runs `SELECT path FROM piwigo_images WHERE path LIKE
'%.png' ORDER BY id LIMIT 1`). A screenshot of the real gallery publishes private faces.
ImageMagick 7.1.1-43 and exiftool 13.25 are available in the web container and can generate
synthetic photos, verified 2026-08-31.

**Eleven strings the handbook would screenshot render in English or French.** Verified
2026-08-31: none has a `de_DE` translation, and ten are not keys in any core locale file at
all, so `l10n()` returns the key itself (`include/functions.inc.php:1320-1327`). A twelfth,
`Créer`, is a raw literal with no `|translate` filter and cannot be reached by any language
file.

**Core is untested.** The three fork plugins carry unit, integration and E2E suites. Piwigo
core carries none: no root `phpunit.xml`, no `composer.json`, no CI. Person tagging, the
provenance note and colored-tag assignment are covered at all three layers; core album
creation, core photo title/description/author and core tag CRUD are covered nowhere.
`grep -rn "categories\.add|categories\.setInfo|images\.setInfo|tags\.(add|rename|delete|merge)|picture_modify\.php" plugins/*/tests`
returns zero hits.

**The install carries leftover state.** Five `Persons E2E <hex>` albums (ids 2141, 2635,
2639, 2641, 2644) and five orphan `persons-test-*` photo rows (3015, 3617, 3621, 3623, 3626)
survived interrupted E2E runs; `--restore` did not clean up. They hold roughly 63 MB of
duplicated scan copies and would appear in any screenshot of the album tree or the persons
admin screen.

## Desired End State

`docs/handbuch/` holds six HTML pages and a stylesheet, illustrated by screenshots of a
generated demo album that contains no real person. Every screenshot shows German. Both the
demo content and the screenshots are reproducible by two committed commands. The four core
workflows the handbook documents carry integration-layer characterization tests.

Verify by: opening `docs/handbuch/index.html` in a browser and walking each of the five
workflows against a clean install; running the three suites named in *Test Commands*; and
re-running the seed and shoot scripts to confirm they reproduce the same screenshots.

### Deliverable layout

```
docs/handbuch/
  index.html                        Inhaltsverzeichnis
  01-alben.html                     Album anlegen und beschreiben
  02-fotos.html                     Fotos zu einem Album hinzufuegen
  03-fototexte.html                 Titel, Autor, Datum, Beschreibung, Notiz
  04-schlagworte.html               Schlagworte und farbige Schlagworte
  05-personen.html                  Personen auf Fotos markieren
  assets/handbuch.css
  assets/screenshots/*.png
  tools/seed.php                    generates and removes the demo album
  tools/shoot.js                    takes every screenshot
```

### Page shape, following `language/de_DE/help/cat_modify.html`

```
+--------------------------------------------------------------+
| <h2> Album anlegen und beschreiben                           |
|                                                              |
| <p> one or two sentences saying what the screen is for       |
|                                                              |
| <h3> Album anlegen                                           |
| <ol>                                                         |
|   <li> Verwaltung > Alben > Verwaltung oeffnen               |
|   <li> Auf <strong>Album hinzufuegen</strong> klicken        |
|   <li> Namen eingeben, Vorgaengeralbum waehlen, speichern    |
| </ol>                                                        |
| [ screenshot: 01-album-hinzufuegen.png ]                     |
|                                                              |
| <h3> Beschreibung ergaenzen                                  |
| <ul>                                                         |
|   <li> <strong>Name</strong>: ...                            |
|   <li> <strong>Beschreibung</strong>: ...                    |
| </ul>                                                        |
| [ screenshot: 02-album-beschreibung.png ]                    |
+--------------------------------------------------------------+
```

### Key Discoveries

- The local override file is **flat**, not a directory: `include/common.inc.php:239` calls
  `load_language('lang', PHPWG_ROOT_PATH.PWG_LOCAL_DIR, array('no_fallback'=>true,
  'local'=>true))`, and `'local'=>true` resolves to `$dirname.$language.'.'.$filename`
  (`include/functions.inc.php:1879-1881`). So the file is `local/language/de_DE.lang.php`.
  `local/language/de_DE/common.lang.php` is never scanned by any call site.
- The override **merges and wins**: `$lang = array_merge($lang, (array)$load_lang)`
  (`include/functions.inc.php:1902-1927`), loaded after `common.lang` and `admin.lang`.
- A plugin's own `load_language()` runs *later* and would overwrite a local override of the
  same key. None of the keys here is defined in `plugins/typetags/language/de_DE/plugin.lang.php`,
  so all are safe.
- `$conf['compiled_template_cache_language']` is `false` (`include/config_default.inc.php:529`),
  so `{'X'|translate}` compiles to a runtime `l10n('X')` call and no compiled template caches
  the language. Clearing `_data/templates_c/` is not required for a translation change.
- `.gitignore:24` is `/local/*` with only `!/local/**/index.php` re-included, so the override
  file needs its own `!` entry to be tracked.
- `docs/` is not ignored: `git check-ignore -v docs/handbuch/assets/screenshots/x.png` exits 1.
  No exception is needed for the screenshots. Committed PNGs already exist at `docs/` root.
- `include/common.inc.php` cannot be included from the CLI: it calls `session_start()`, which
  dies without `$_SERVER['REMOTE_ADDR']` (`plugins/persons/tests/Support/PiwigoRuntime.php:7-9`).
  Any new CLI script must assemble the runtime the way `PiwigoRuntime::boot():18-74` does.
- `FixtureBuilder::__construct` calls `assertThrowawayInstall()` unconditionally
  (`plugins/persons/tests/Support/FixtureBuilder.php:32-36, 52-68`): it fails closed unless
  `piwigo_config` holds `persons_throwaway_install = '1'`. The docblock records why - on
  2026-08-29 an install holding real scans lost every photo row during a plugin test run.
- Every fixture mutator **forces the state, re-reads it, and throws if it did not take**.
  The new seed follows the same rule.
- Browser upload coverage is precedent-refused. `docs/agents/TESTING.md:434` records that the
  manual upload check was replaced by `InheritTest::testAnUploadedPhotoInheritsTheAlbumsProvenance`,
  which drives `pwg.images.upload` then `pwg.images.uploadCompleted`, because what a browser
  adds on top is plupload's chunking - core's code on a screen no plugin touches.
- `docs/agents/TESTING.md:174-212` has **no** entry for core album creation, core photo text
  or core tag CRUD. They are unrecorded gaps, not considered omissions.
- The lounge is active on this install (`lounge_activate_threshold = 1`), so an uploaded
  photo lands in `piwigo_lounge` first and the album link materialises only when
  `empty_lounge()` runs.
- The photo-properties "Linked albums" control calls **move**, not associate
  (`admin/picture_modify.php:119-126`): albums left out of the selection are unlinked. The
  handbook must say so.

## What We're NOT Doing

- Not touching `language/de_DE/help/`. An upstream merge rewrites those files.
- Not translating the whole German locale. Only the strings the handbook screenshots.
- Not writing an E2E spec per documented workflow. Browser upload coverage was already
  declined with a stated reason (`docs/agents/TESTING.md:434`), and restating integration
  rules at the browser layer violates the placement rule in `.claude/rules/testing.md`.
- Not standing up a core test harness. Core has no `composer.json` and no CI by design; a
  root `phpunit.xml` would invent a pipeline against `.claude/rules/backpressure.md`.
- Not pixel-diffing screenshots. Explicitly rejected as flaky for a photo gallery
  (`docs/agents/TESTING.md:428`).
- Not investigating why persons' `seed.php --restore` leaked the five albums. Recorded as
  separate work by research decision 6.
- Not documenting FTP upload or filesystem sync as an end-user path. Both need shell or FTP
  access; the handbook covers the browser uploader and says the other paths exist.
- Not blurring or cropping real scans. Manual, and unrecoverable if missed once.
- Not implementing person merge, face crops, or any backlog item the workflows touch.

## Implementation Approach

Prerequisites first, deliverable last, so no screenshot is taken of a screen that is about
to change. Phase 1 cleans the install. Phase 2 fixes the German. Phase 3 adds the missing
core coverage, independent of both. Phase 4 generates publishable demo content and Phase 5
photographs it. Phase 6 writes the handbook. Phase 7 records what was decided.

Phases 2 and 3 are independent of each other and could be swapped; the order below puts the
translations first because they are the shorter change and unblock nothing else.

---

## Phase 1: Clean the install

### Overview

Remove the leftover E2E state so it cannot appear in a screenshot, and reclaim roughly 63 MB.
No committed code - this is a one-off repair of local state, per the research decision.

### Changes Required

#### [x] 1. Record the before state
**Command**: `ddev mysql`
**Changes**: Count albums, images, and the specific leftover rows, so the removal is
verifiable rather than assumed.

```sql
SELECT COUNT(*) FROM piwigo_categories;
SELECT COUNT(*) FROM piwigo_images;
SELECT id, name FROM piwigo_categories WHERE name LIKE 'Persons E2E %';
SELECT id, file, path FROM piwigo_images WHERE path LIKE '%/persons-test/%';
```

#### [x] 2. Delete the five orphan photo rows and their files
**Changes**: Delete from `piwigo_image_category`, `piwigo_image_tag`, `piwigo_person_region`
and `piwigo_images` for the five ids, then remove `upload/persons-test/` including exiftool's
`_original` sidecars. This is the same order `FixtureBuilder::destroyTestImages():377-398`
uses; reuse that ordering rather than inventing one.

#### [x] 3. Delete the five leftover albums
**Changes**: Delete their `piwigo_image_category` rows, then the `piwigo_categories` rows,
matching `destroyTestAlbums():401-409`.

#### [x] 4. Confirm no other leftovers
**Changes**: Re-run the two `LIKE` queries and confirm both return zero rows. Check
`upload/persons-test/` is gone.

### Success Criteria

#### Automated Verification
- [x] `SELECT COUNT(*) FROM piwigo_categories WHERE name LIKE 'Persons E2E %'` returns 0
- [x] `SELECT COUNT(*) FROM piwigo_images WHERE path LIKE '%/persons-test/%'` returns 0
- [x] `ls upload/persons-test/` reports no such directory
- [x] Album count dropped by exactly 5 and image count by exactly 5 against the recorded before state
- [x] Persons suites still pass: `ddev exec plugins/persons/vendor/bin/phpunit --testsuite unit --configuration plugins/persons/phpunit.xml`
- [x] `ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; plugins/persons/vendor/bin/phpunit --testsuite integration --configuration plugins/persons/phpunit.xml'`

#### Manual Verification

Automated rather than walked by hand, 2026-08-31: an ad-hoc script logs in as
`persons_webmaster`, fetches both admin screens over HTTP and greps them, and checks the
database and the filesystem. Each absence check carries a byte lower bound, and each was
proved non-vacuous by grepping the same haystack for content that is really there.

- [x] `admin.php?page=albums` shows no `Persons E2E` node - 23793 bytes, zero hits;
      `pwg.categories.getAdminList` likewise, and both do carry `Sefferweich Allgemein Fotos`
- [x] `admin.php?page=plugin-persons` shows no stranger with a leftover region count -
      8411 bytes, zero hits for `Persons E2E` and for `persons-test-`
- [x] The four real gallery albums and their 105 photos are untouched - 5 album rows,
      105 image rows, all 105 files present on disk, zero orphan rows across
      `piwigo_image_category` (by image and by album), `piwigo_image_tag` and
      `piwigo_person_region`

The script is throwaway and uncommitted: this phase is a one-off repair of local state, and
Phase 4 deliberately adds an album, so a committed guard over the install's contents would
have to be edited to stay green.

**Implementation Note**: This phase deletes rows and image files. Confirm the counts in step 1
before deleting anything, and stop if any count disagrees with the research figures. Pause
here for manual confirmation before proceeding.

---

## Phase 2: German translations

### Overview

Fix the strings the handbook will screenshot, so the handbook shows what users see. Eleven
strings go into a tracked local override; the twelfth needs a template edit in the typetags
submodule because it carries no `|translate` filter.

### Changes Required

#### [x] 1. The override file
**File**: `local/language/de_DE.lang.php` (new)
**Changes**: A flat file merged over core after `common.lang` and `admin.lang`. The exact
literal forms differ per string (`|@translate` vs `|translate`); the key is the string inside
the quotes.

```php
<?php
// Fork-local German strings that core and the Colored Tags plugin leave untranslated.
// Loaded by include/common.inc.php:239 and merged over core, so it wins.
$lang['Album updated'] = 'Album gespeichert';
$lang['An error has occured while saving album settings'] = '...';
$lang['Rename album'] = 'Album umbenennen';
$lang['No photos in the current album, no thumbnail available'] = '...';
$lang['Album %s now contains %d photos'] = 'Album %s enthält jetzt %d Fotos';
$lang['%d photos updated'] = '%d Fotos aktualisiert';
$lang['Batch Manager Filter'] = 'Filter der Stapelverarbeitung';
$lang['No filter, add one'] = 'Kein Filter, fügen Sie einen hinzu';
$lang['Rename Tag'] = 'Schlagwort umbenennen';
$lang['Remove color'] = 'Farbe entfernen';
$lang['Add tag'] = 'Schlagwort hinzufügen';
$lang['Remove tag'] = 'Schlagwort entfernen';
$lang['Couleur'] = 'Farbe';
```

Note `%s` and `%d` placeholders and their order must survive - both affected strings are fed
through `sprintf`.

#### [x] 2. Track the override file
**File**: `.gitignore`
**Changes**: Add a re-include next to the existing plugin and theme entries.

```
 /local/*
 !/local/**/index.php
+!/local/language
+!/local/language/de_DE.lang.php
```

#### [x] 3. The one string no language file can reach
**File**: `plugins/typetags/template/tags.tpl:73` (git submodule)
**Changes**: Wrap the raw literal so it becomes translatable.

```smarty
- <span id="TypetagsCreate" class="typetag-button icon-plus">Créer</span>
+ <span id="TypetagsCreate" class="typetag-button icon-plus">{'Create'|translate}</span>
```

**No locale file change.** The plan called for `$lang['Create'] = 'Anlegen'` in the plugin's
own `de_DE`. Core already carries `$lang['Create']` in `language/de_DE/admin.lang.php`
(`Erstellen`), and in `fr_FR`/`fr_CA` (`Créer`), measured 2026-08-31. A plugin entry would
load *after* core and shadow that wording everywhere the plugin loads its language, for no
gain. Wrapping the literal was the whole fix, and the French rendering does not regress.
`GermanOverrideKeyTest::testTheOverrideDoesNotShadowACoreGermanString` now guards the rule.

This is a commit inside the submodule. `docs/backlog.md` already records that the submodule
is one commit ahead of its own origin; this adds a second. Both must be pushed to
`github.com/christianbaumann/Piwigo-Colored-Tags` or a fresh clone still fails.

#### [x] 4. Structural guards for the keys
**File**: `plugins/provenance/tests/Unit/GermanOverrideKeyTest.php` (new)
**Changes**: One assertion per key that the literal still occurs exactly once in the file
that emits it, with the lower-bound anti-vacuity constant the existing anchor guards carry
(`PhotoModifyAnchorTest.php:18`, `PhotoTemplateAnchorTest.php:14`). This catches an upstream
merge renaming or removing a string, which would otherwise silently revert the screen to
English with no error anywhere.

```php
private const MIN_BYTES = 500;

/** @dataProvider emitters */
public function testTheEmittingFileStillCarriesTheLiteral(string $file, string $literal): void
{
    $content = file_get_contents(PIWIGO_ROOT . $file);
    self::assertGreaterThan(self::MIN_BYTES, strlen($content), "$file did not load");
    self::assertSame(1, substr_count($content, $literal), "$literal moved in $file");
}
```

The data set, with the forms verified 2026-08-31:

| File | Literal as written |
|---|---|
| `admin/themes/default/template/cat_modify.tpl:186` | `{'Album updated'\|@translate}` |
| `admin/themes/default/template/cat_modify.tpl:187` | `{'An error has occured while saving album settings'\|@translate}` |
| `admin/themes/default/template/cat_modify.tpl:113` | `{'No photos in the current album, no thumbnail available'\|@translate}` |
| `admin/themes/default/template/albums.tpl:217` | `{'Rename album'\|@translate}` |
| `admin/themes/default/template/photos_add_direct.tpl:50` | `{'Album %s now contains %d photos'\|translate\|escape:javascript}` |
| `admin/themes/default/template/photos_add_direct.tpl:46` | `{'%d photos updated'\|translate\|escape:javascript}` |
| `admin/themes/default/template/batch_manager_global.tpl:316` | `{'Batch Manager Filter'\|@translate}` |
| `admin/themes/default/template/include/batch_manager_filter.inc.tpl:246` | `{'No filter, add one'\|@translate}` |
| `admin/themes/default/template/tags.tpl:176` | `{'Rename Tag'\|@translate}` |
| `plugins/typetags/template/tags.tpl:42` | `{'Remove color'\|translate}` |
| `plugins/typetags/include/events_admin.inc.php:61` | `{"Couleur"\|translate}` |

`Add tag` and `Remove tag` sit inside PHP string literals with escaped quotes
(`{\'Add tag\'|@translate}` at `events_public.inc.php:184` and `:329`;
`{\'Remove tag\'|@translate}` at `:205` and `:232`). Each occurs **twice**, so their
assertion is `assertSame(2, ...)` against the escaped form, not the plain one. Getting this
wrong is the likeliest way to write a guard that cannot fail.

### Success Criteria

#### Automated Verification
- [x] `ddev exec php -l local/language/de_DE.lang.php` passes
- [x] `ddev exec php -l plugins/typetags/language/de_DE/plugin.lang.php` passes
- [x] `git check-ignore -q local/language/de_DE.lang.php` exits 1 (no longer ignored). Note:
      the `-v` form quoted originally exits 0 because it prints the matching negation rule.
- [x] `git status --short` lists `local/language/de_DE.lang.php` as a new tracked file
- [x] New guards pass: `ddev exec plugins/provenance/vendor/bin/phpunit --testsuite unit --configuration plugins/provenance/phpunit.xml`
- [x] Each guard was watched go red: temporarily alter one literal in its template and confirm only that case fails
- [x] `ddev exec bash -c 'set -a; . local/config/typetags-test.env; set +a; cd plugins/typetags && npx playwright test'` still passes (its specs read tag admin screen text)
- [x] `printf` placeholders intact: no `%s`/`%d` added, removed or reordered in the two format strings

#### Manual Verification

All seven were automated at the integration layer rather than walked by hand. Two new files
fetch each screen over HTTP as the webmaster and assert both that the German string is
present and that the untranslated form is gone:
`plugins/provenance/tests/Integration/GermanAdminScreenTest.php` (core screens, 17 cases) and
`plugins/typetags/tests/Integration/GermanScreenTest.php` (plugin screens, 5 cases).

- [x] `admin.php?page=album-<id>-properties`: saving shows a German confirmation, not `Album updated`
- [x] `admin.php?page=albums`: the rename control reads German
- [x] `admin.php?page=photos_add`: the summary format string reads German with `%s` and `%d`
      intact. The substitution itself happens in plupload's JavaScript; browser upload
      coverage is precedent-refused (`docs/agents/TESTING.md:434`)
- [x] `admin.php?page=batch_manager`: the filter panel reads German
- [x] `admin.php?page=tags`: `Rename Tag`, `Remove color`, `Couleur` and the create button all read
      German. Corrected 2026-08-31: three of the four reach a reader, `Rename Tag` does not. See
      *Findings* below
- [x] The public picture page: the `+` badge and the `x` control carry German tooltips.
      The wording is asserted at the integration layer; that both controls carry the
      declared title in the **DOM** needs a browser, because the `x` control has no
      server-rendered form - `plugins/typetags/tests/e2e/german-tooltips.spec.js`
- [x] No screen that was already German regressed - `testTheOverrideDoesNotShadowACoreGermanString`
      asserts no overridden key is one core's `de_DE` already translates

**Findings**

- `Rename Tag` reaches no reader. `tags.tpl:176` emits it inside `.TagSubmit`, but
  `admin/themes/default/js/tags.js:298` runs `$(".TagSubmit").html(str_yes_rename_confirmation)`
  before `:306` fades the popin in, so the button a user sees says `Ja, umbenennen`. Confirmed in
  a browser 2026-08-31. The integration case stays - the string is genuinely in the source, and
  the key stays overridden so an upstream version that stops overwriting it gets German with no
  second change - but it now carries a comment saying so, and the DOM fact is recorded by
  `plugins/provenance/tests/e2e/core-admin-screens.spec.js`. Watched red with the overwrite
  removed.
- `Batch Manager Filter` renders on no screen. `batch_manager_global.tpl:316` passes it as
  `title=` into `include/batch_manager_filter.inc.tpl`, which never reads `$title` (zero
  occurrences, measured 2026-08-31). The override keeps the key and
  `GermanAdminScreenTest::testTheBatchManagerFilterTitleReachesNoScreen` records the fact, so
  an upstream template that starts rendering it is visible in a run.
- The `Créer` mutant survived until `_data/templates_c/` was cleared: the plugin's `tags.tpl`
  is embedded into the compiled core template by a prefilter, and Smarty's `compile_id`
  hashes only the callback name. Documented in the test's docblock.

---

## Phase 3: Core characterization tests

### Overview

Cover the four workflows the handbook documents that have no test at any layer today.
Documenting a step means walking it, and an undocumented regression in one of these would
silently invalidate a handbook page.

Every case is `[ERR]`: core has no requirements document, so the oracle is the current
implementation. They report a change; they do not prove the behaviour right. They land and
pass on their first run, which is normally the tell that a test recorded code rather than
drove it - so each must be watched go red by breaking the behaviour it claims to watch, per
*proving a check can actually fail* in `.claude/rules/test-design.md`.

Home is `plugins/provenance/tests/Integration/`, alongside the two core characterization
tests already there. Reuses its `WsClient`, `Db` and `FixtureBuilder`.

### Changes Required

#### [x] 1. Album creation and description
**File**: `plugins/provenance/tests/Integration/CoreAlbumCharacterizationTest.php` (new)
**Changes**: Drive `pwg.categories.add` and `pwg.categories.setInfo` over `ws.php`, the two
endpoints `albums.js:219-241` and `cat_modify.js:70-85` call. Follow the docblock pattern of
`CoreDeleteCategoriesCharacterizationTest.php:1-22` verbatim, including the statement that
the oracle is the implementation.

```php
final class CoreAlbumCharacterizationTest extends TestCase
{
    // setUp: new Db, new WsClient, new FixtureBuilder; ws->login(webmaster)
    // tearDown: destroyTestAlbums(); ws->logout()
}
```

#### [x] 2. Core photo text fields
**File**: `plugins/provenance/tests/Integration/CorePhotoTextCharacterizationTest.php` (new)
**Changes**: Drive the real boundary the screen uses - a form POST to
`admin.php?page=photo-<id>-properties` via `WsClient::postPage():134`, guarded by
`check_pwg_token()`, written by `single_update()` (`admin/picture_modify.php:81-104`). Not
`pwg.images.setInfo`: that is a different writer, and the handbook documents the screen.

#### [x] 3. Core tag CRUD
**File**: `plugins/provenance/tests/Integration/CoreTagCrudCharacterizationTest.php` (new)
**Changes**: Drive `pwg.tags.add`, `pwg.tags.rename`, `pwg.tags.delete` and `pwg.tags.merge`,
which is where `admin/tags.php` puts all of it - the PHP file only renders and deletes
orphans. Assignment goes through `set_tags()` (full replace) from the photo properties screen.

Note the memoisation trap: `tag_id_from_tag_name()` caches in `$page`, which is why
`PiwigoRuntime::resetRequestCaches():101-105` exists. Each test crosses a real HTTP boundary
so it gets a fresh request, but any in-process assertion must reset it.

#### [x] 4. Upload into an album
**File**: extend the album test, or a fourth file
**Changes**: Drive `pwg.images.upload` with a real file then `pwg.images.uploadCompleted`,
the exact sequence the upload screen issues (`photos_add_direct.js:106`, `:437`), using
`WsClient::upload():103`. Assert the photo arrives linked to the album.

The lounge is active (`lounge_activate_threshold = 1`), so the link materialises only when
`empty_lounge()` runs. `InheritTest::testWithTheLoungeOnTheValuesArriveWhenTheLoungeIsEmptied`
already handles this case and is the pattern to follow - do not assert the link before the
lounge is emptied.

### Test cases

Technique legend is in `.claude/rules/test-design.md`.

**`CoreAlbumCharacterizationTest`**
- [x] `testAddingAnAlbumReturnsAnIdAndCreatesTheRow` - `pwg.categories.add` with a name only `[HAPPY]`
- [x] `testANewTopLevelAlbumTakesItsOwnIdAsUppercats` - the two-step core performs `[ERR]`
- [x] `testAddingWithAParentNestsTheAlbum` - `uppercats` is `<parent>,<child>` `[ECP]`
- [x] `testANewAlbumHasAnEmptyDescription` - `comment` is null before `setInfo` `[BVA]`
- [x] `testSetInfoStoresTheDescription` - round-trip through `pwg.categories.setInfo` `[HAPPY]`
- [x] `testSetInfoWithNoTokenStripsMarkup` - `allow_html_descriptions` is true, so markup
      survives with a valid `pwg_token` and `strip_tags()` applies without one
      (`pwg.categories.php:954`) `[DT]`
- [x] `testAnEmptyNameIsRefused` - `[NEG]` `[BVA]`
- [x] `testAGuestCannotAddAnAlbum` - cookie-less `WsClient`, as `AddRegionTest:104-109` does `[NEG]`
- [x] `testANormalUserCannotAddAnAlbum` - both methods are `admin_only` `[NEG]`
- [x] `testAUnicodeNameSurvivesTheRoundTrip` - umlauts, which the German install needs `[ERR]`
- [x] `testALongDescriptionIsStoredWhole` - upper boundary of the `comment` column `[BVA]`

**`CorePhotoTextCharacterizationTest`**
- [x] `testTitleAuthorDateAndDescriptionAreStored` - all four in one POST `[HAPPY]`
- [x] `testEachFieldCanBeClearedIndependently` - empty string vs unchanged `[ECP]` `[BVA]`
- [x] `testTheFilenameIsNotWritable` - read-only at `picture_modify.tpl:124` `[NEG]`
- [x] `testAPostWithoutAValidTokenIsRefused` - `check_pwg_token()` `[NEG]`
- [x] `testANormalUserIsRefused` - `check_status(ACCESS_ADMINISTRATOR)` `[NEG]`
- [x] `testAnInvalidCreationDateIsRejectedOrNormalised` - records which, since no requirement
      says `[ERR]`
- [x] `testUnicodeAndMarkupInTheDescription` - sanitisation depends on
      `allow_html_descriptions` `[DT]`
- [x] `testLinkedAlbumsUnlinksAlbumsLeftOutOfTheSelection` - the move-not-associate behaviour
      at `admin/picture_modify.php:119-126`, which the handbook must warn about `[ERR]`
- [x] `testTheStorageAlbumCannotBeUnlinked` - `:354-359` `[NEG]`

**`CoreTagCrudCharacterizationTest`**
- [x] `testAddingATagCreatesTheRowAndAUrlName` - `pwg.tags.add` `[HAPPY]`
- [x] `testAddingADuplicateNameIsRefused` - `[NEG]`
- [x] `testRenamingChangesTheNameAndTheUrlName` - `[HAPPY]` `[ST]`
- [x] `testRenamingToAnExistingNameIsRefused` - `[NEG]`
- [x] `testDeletingRemovesTheTagAndItsImageLinks` - `[HAPPY]` `[ST]`
- [x] `testMergingMovesEveryImageLinkAndRemovesTheSource` - `[HAPPY]` `[ST]`
- [x] `testMergingATagIntoItselfIsRefusedOrIsANoOp` - records which `[ERR]` `[BVA]`
- [x] `testAssignmentReplacesRatherThanAppends` - `set_tags()` is a full replace, which the
      handbook must state `[ERR]`
- [x] `testAssigningAnEmptyListRemovesEveryTag` - `[BVA]`
- [x] `testAGuestCannotCreateRenameOrDeleteATag` - `[NEG]`
- [x] `testANormalUserCannotCreateRenameOrDeleteATag` - all `admin_only` `[NEG]`
- [x] `testATagWithAUmlautGetsAUsableUrlName` - the install's 8 tags are German `[ERR]`
- [x] `testDeletingAColoredTagLeavesNoOrphanTypetagsRow` - the fork's `piwigo_typetags` and
      `piwigo_tags.id_typetags` are the coupling core CRUD can break `[NEG]`

**Upload**
- [x] `testUploadFollowedByUploadCompletedLinksThePhotoToTheAlbum` - `[HAPPY]`
- [x] `testTheLinkMaterialisesOnlyAfterTheLoungeIsEmptied` - `[ST]`
- [x] `testAnUnsupportedExtensionIsRefused` - `$conf['picture_ext']` is
      `jpg,jpeg,png,gif,webp` and `upload_form_all_types` is false `[NEG]` `[ECP]`

**Regression, existing suites**
- [x] `CoreAssociationCharacterizationTest` still passes - shares the association path
- [x] `CoreDeleteCategoriesCharacterizationTest` still passes - shares album deletion
- [x] Full provenance integration suite still passes - the new tests share `FixtureBuilder`
      and the history table
- [x] Full typetags integration suite still passes - core tag CRUD touches `piwigo_tags`,
      which typetags extends
- [x] Full persons integration suite still passes - persons mirrors each person as an
      ordinary tag

### Techniques not applicable
- **State transition** does not apply to the album description: it is a single nullable
  column with no lifecycle. It does apply to tags, where create/rename/merge/delete form a
  real sequence, and it is used there.
- **Decision table** does not apply to tag CRUD: each method has one condition and two
  outcomes. It does apply to the description sanitiser, where `allow_html_descriptions` and
  token validity combine, and it is used there.

### Success Criteria

#### Automated Verification
- [x] `ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; plugins/provenance/vendor/bin/phpunit --testsuite integration --configuration plugins/provenance/phpunit.xml'`
- [x] `ddev exec plugins/provenance/vendor/bin/phpunit --testsuite unit --configuration plugins/provenance/phpunit.xml`
- [x] `ddev exec bash -c 'set -a; . local/config/typetags-test.env; set +a; plugins/typetags/vendor/bin/phpunit --testsuite integration --configuration plugins/typetags/phpunit.xml'`
- [x] `ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; plugins/persons/vendor/bin/phpunit --testsuite integration --configuration plugins/persons/phpunit.xml'`
- [x] The suite passes **twice in a row** and **in reverse order** with no manual repair
- [x] `failOnRisky` and `failOnWarning` stay on; no test asserts nothing
- [x] Every new test was watched go red by breaking the behaviour it claims to watch, recorded
      per test

#### Manual Verification
- [x] Album and image counts are unchanged after a full run - every fixture restored
- [x] No `Persons E2E` or `persons-test-*` leftovers reappeared
- [x] The four real gallery albums are intact

**Implementation Note**: These tests create and delete albums, photos and tags on the one
install this repository has. `FixtureBuilder` fails closed without the throwaway marker;
do not remove that guard. Pause here for manual confirmation before proceeding.

---

## Phase 4: Demo gallery seed

### Overview

Generate publishable demo content, so no screenshot shows a real person. Synthetic photos
with face-like shapes give person tagging an actual box to draw.

### Changes Required

#### [x] 1. The seed script
**File**: `docs/handbuch/tools/seed.php` (new)
**Changes**: Follows the shape of `plugins/persons/tests/e2e/support/seed.php` - one JSON
object on stdout, errors to stderr with exit 1, `getopt` args, a snapshot on disk so a later
process can restore. It cannot reuse the plugins' bootstrap (that would make the handbook
depend on a plugin's test scaffolding), so it assembles the runtime the way
`PiwigoRuntime::boot():18-74` does: `config_default.inc.php`, `local/config/config.inc.php`,
`local/config/database.inc.php`, `constants.php`, the dblayer, `functions.inc.php`.

```
docs/handbuch/tools/seed.php --scenario=demo    creates everything, prints ids and paths
docs/handbuch/tools/seed.php --restore          removes everything it created
```

Safety: refuse to run unless the throwaway marker is present, the same condition
`FixtureBuilder::assertThrowawayInstall():52-68` checks. The script rewrites image metadata
and deletes rows; it is never safe against a production install, and the file header says so.

#### [x] 2. Photo generation
**File**: same script
**Changes**: ImageMagick 7.1.1-43 in the web container, verified 2026-08-31. Six to eight
1200x800 PNGs with drawn face-like shapes at known positions and German titles burned in, so
a reader can tell them apart and a person box has something to sit on. Positions are fixed
constants, printed in the output, so `shoot.js` can drag a box over a face rather than over
empty sky.

Each generated file is asserted non-empty and readable by `getimagesize()` before its row is
inserted - the anti-vacuity guard `createTestImage():90-147` already carries.

#### [x] 3. The demo album and its content
**File**: same script
**Changes**: One album named for the handbook, a German description, the generated photos
linked to it, German titles/authors/descriptions on two of them, two or three of the
install's existing colored tags assigned, and one person region written with exiftool so
`05-personen.html` can show boxes.

Force the state, re-read it, throw if it did not take - the rule every existing fixture
mutator follows.

#### [x] 4. Restore
**File**: same script
**Changes**: Delete the region rows, the person, the image rows and their links, the album,
and the generated files with exiftool's `_original` sidecars. Unconditional parts first
(anything that outlives a snapshot), then the snapshot-driven parts, mirroring
`seed.php:257-281`. Exit 0 with `{"restored": false, "reason": "no snapshot"}` when there is
nothing to undo.

### Test cases

The seed is test scaffolding, not production code, so it gets guards rather than a suite of
its own - building a test that tests a test is what `.claude/rules/test-design.md` forbids.
What it does carry:

- [x] Every generated file asserted non-empty and dimension-readable before use `[ERR]`
- [x] Every insert re-read and thrown on if it did not take `[ERR]`
- [x] The throwaway marker checked before any write `[NEG]`
- [x] `--restore` run twice in a row is not an error the second time `[BVA]`
- [x] `--restore` with no snapshot exits 0, not 1 `[BVA]`

### Success Criteria

#### Automated Verification
- [x] `ddev exec php -l docs/handbuch/tools/seed.php` passes
- [x] `ddev exec php docs/handbuch/tools/seed.php --scenario=demo` prints one JSON object and exits 0
- [x] `ddev exec php docs/handbuch/tools/seed.php --restore` exits 0 and album/image counts return to their pre-seed values
- [x] Seed, restore, seed, restore leaves the counts unchanged
- [x] `--restore` on a clean install exits 0 with `restored: false`
- [x] All three plugin integration suites still pass with the demo album present
- [x] Generated PNGs are non-zero and readable: `identify` reports 1200x800 for each

#### Manual Verification
- [x] The demo photos contain no recognisable real person - the falsifiable half is now
      `assert_no_generated_photo_is_a_gallery_copy()`, which compares every generated file
      byte for byte against every gallery file; what a drawn shape evokes has no oracle and
      is in the ledger
- [x] Face-like shapes are large enough to drag a region box over - automated as
      `assert_scenes_are_photographable()` against `MIN_FACE_PIXELS`, watched red with a
      scene's radius reduced from 56 to 40
- [x] German titles and the album description read naturally - the round trip is
      `assert_german_texts_round_tripped()`, watched red with the only non-ASCII title made
      ASCII; whether the wording sounds natural is in the ledger
- [x] The demo album looks plausible as a gallery, not obviously synthetic to the point of
      being confusing in a screenshot - subjective, recorded in the ledger with its reason

**Implementation Note**: Pause here for manual confirmation. The demo content is what every
screenshot shows; changing it later means retaking all of them.

**Phase 4 verified 2026-08-31.** Six guards in the seed, each watched red against its own
mutant; the mutant pass found two defects of its own, both recorded in the hand-check ledger
in `docs/agents/TESTING.md`: the copy guard's first form was vacuous because all 105 image
rows carry a null `md5sum`, and a scene check that ran inside the insert loop stranded rows
no snapshot named.

---

## Phase 5: Screenshot tooling

### Overview

One committed command produces every screenshot, so they can be retaken when the UI changes
instead of rotting.

### Changes Required

#### [x] 1. The shoot script
**File**: `docs/handbuch/tools/shoot.js` (new)
**Changes**: Playwright driving Chromium in the container against `http://localhost`, which
is the only host that answers from inside. Uses the shared pinned browser cache
(`PLAYWRIGHT_BROWSERS_PATH` in `.ddev/config.yaml`), so no new download and no new dependency.
Fixed 1280x720 viewport, matching the plugins' `Desktop Chrome` default.

```bash
ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; \
  node docs/handbuch/tools/shoot.js'
```

Logs in with the existing test accounts rather than a human's credentials: the webmaster for
admin screens, `persons_normal` for the public picture page, since the overlay and the tag
badges are shown to any logged-in non-guest and shooting them as an administrator would hide
a permission mistake.

#### [x] 2. The screenshot set
**File**: same script
**Changes**: One named function per screenshot, each navigating, waiting for a real condition
and writing to `docs/handbuch/assets/screenshots/<nn>-<slug>.png`. Never a wall-clock wait -
wait for the element to reach its state, per `.claude/rules/test-design.md`.

Roughly twenty shots:

| Page | Shots |
|---|---|
| 01-alben | album tree, add-album modal, properties with description, public album page |
| 02-fotos | upload screen, upload in progress, German confirmation, Batch Manager associate |
| 03-fototexte | photo properties with the four core fields, the provenance Notiz block, the public picture page showing both |
| 04-schlagworte | tag admin, tag assignment on photo properties, colored tag badges, the `+` badge on the public page |
| 05-personen | overlay with boxes, tagging mode, the drawn rectangle, the picker, the admin persons list |

#### [x] 3. Cropping and redaction
**File**: same script
**Changes**: Screenshot the relevant element with a `clip` box rather than the whole page,
so no shot incidentally includes a thumbnail of a real gallery photo in a sidebar or a
recently-added strip. Where a full-page shot is unavoidable, assert the demo album is the
only content in frame.

### Test cases

- [x] Every expected output file exists and is non-zero after a run `[ERR]` - `assertOutput()`
- [x] The run fails loudly if a locator is not found, rather than writing a blank image `[NEG]` -
      every shot goes through `shoot()`, which waits for the locator and throws; the run reports
      the file name and the locator that was missing and exits 1. Watched red twice during
      Phase 5: `.tree .jqtree_element` (the class is `jqtree-element`) and `#linkedAlbumSelector`
- [x] Re-running overwrites rather than accumulating numbered duplicates `[BVA]` - `assertOutput()`
      also fails on any `.png` in the directory that no shot declares

No pixel-diffing and no baseline. Explicitly rejected as flaky for a photo gallery
(`docs/agents/TESTING.md:428`).

### Success Criteria

#### Automated Verification
- [x] The command above exits 0
- [x] Every file named in the handbook exists under `docs/handbuch/assets/screenshots/` - the
      handbook is Phase 6; the check that holds today is the two-way one in `assertOutput()`,
      every declared shot present and no undeclared file beside them
- [x] No output file is zero bytes; `identify` reports 1280 width or a documented clip width.
      Measured 2026-08-31, every shot is an element crop rather than a page:

      | width | shots |
      |---|---|
      | 1395 | 12 (tags admin, shot at a 1600px viewport - see below) |
      | 1280 | 04, 11 (full-width public pages) |
      | 1075 | 01, 03, 05, 07, 20 (the admin content column) |
      | 1059 | 08 |
      | 912 | 16, 17, 18 (the photo and its overlay) |
      | 500, 516, 491, 310, 304, 238 | the remaining close-ups |

- [x] A second run reproduces the same file set - run repeatedly during the phase, and once
      more after all nine suites
- [x] Removing a required test account makes the run fail with a message naming the missing
      variable and the script that creates it - `unset PERSONS_TEST_NORMAL_PASSWORD` exits 1
      naming both variables and `create-test-users.php`. The same shape covers a missing seed:
      a deleted `_data/handbuch/demo.json` exits 1 naming `seed.php --scenario=demo`
- [x] The run mutates nothing - album, image, tag, person and region counts identical before
      and after (6 / 111 / 10 / 2 / 2, measured 2026-08-31). The one exception is deliberate
      and per-account: `dismissPromotion()` stores the webmaster's "do not show again"
      preference for the upstream mobile-apps banner
- [x] All nine suites still pass with the demo album present (unit 183 / 56 / 114;
      integration 183 / 49 / 104; E2E 29 / 30 / 31, measured 2026-08-31)

#### Manual Verification
- [x] No screenshot shows a real gallery photo, a real person, or a real filename - every one
      of the twenty reviewed by eye, 2026-08-31. Real *album names* do appear in three shots
      (01, 02, 06), which show the album tree; they are place names, not people
- [x] Every screenshot shows German - reviewed by eye. One finding, fixed: the photo
      properties screen dated every photo `Posted the 31. August 2026`. See below
- [x] Each shows the screen area the handbook text describes, not a wider page
- [x] Text is legible at the committed resolution - 632 KB for the twenty

**Findings**

- **A twelfth untranslated string, found by photographing the screen.** `admin/picture_modify.php:274`
  calls `l10n('Posted the %s', ...)`; only `en_GB` and `fr_FR` carry that key, so the photo
  properties screen the handbook shows dated every photo in English. Fixed the Phase 2 way:
  `$lang['Posted the %s'] = 'Eingestellt am %s'` in the override, a fourteenth row in
  `GermanOverrideKeyTest::emitters()` guarding `l10n('Posted the %s'` in that file, and a
  `photo properties: posted date` case in `GermanAdminScreenTest` (now 19 cases). Both were
  watched red - the unit case with the literal altered by one character, the two integration
  cases with the override key commented out - and the emitting file's checksum was compared
  against `ddev exec md5sum` before each run.
- **The seed now writes `_data/handbuch/demo.json`.** `shoot.js` needs the album id, the photo
  ids and the face boxes, and stdout is gone by the time it runs. `seed.php` writes its result
  object there and reads it back before exiting; `--restore` deletes it unconditionally, beside
  the image-directory sweep, because a stale copy would aim the next run at rows that no longer
  exist. No box is recomputed in JavaScript, so the drawn face and the rectangle a shot drags
  over cannot drift apart.
- **Three screens needed a wait or an override to photograph honestly.** The add-album and
  album-selector modals animate their opacity, so `waitForOpaque()` walks the ancestor chain
  before shooting - the first attempt caught the dialog half transparent, which reads as a
  rendering fault. The tags admin overlaps its unused-tags warning with the search box at
  1280px, so shot 12 alone is taken at a 1600px viewport, restored afterwards. The photo
  properties save bar is `position: sticky`, which in a full-height element screenshot lands
  across the middle of the form rather than at the foot of the viewport; `unpinSaveBar()`
  makes it flow, which is what a reader scrolling the page sees.
- **The upload screen's mobile-apps promotion is dismissed, not hidden.** It filled the top
  third of the screen and pulled an image from `sandbox.piwigo.com`. `dismissPromotion()`
  clicks the control a user clicks, then moves the pointer off it and waits for the tiptip to
  go - the first attempt left the tooltip hanging in the corner for a control that was no
  longer there.
- **No upload is performed, so three shots the plan sketched do not exist**: upload in progress
  and the German upload confirmation. Driving plupload would add a photo row that neither the
  seed's snapshot nor `--restore` knows about. This matches the standing precedent at
  `docs/agents/TESTING.md:434`, where browser upload coverage was declined because what the
  browser adds is plupload's chunking. `02-fotos.html` quotes the confirmation wording instead
  of showing it; the string itself is asserted at the integration layer by
  `GermanAdminScreenTest`'s `photo upload: album summary` case.
- **Shot 09 shows `Hinzugefügt von chriss`**, the install owner's own account name, because the
  seed inserts the demo photos as the webmaster. It is the reader's own handle in the reader's
  own handbook, not a third party.

### The manual check that was automated, and the one that was not

"No screenshot shows a real gallery photo" had a real oracle and now has a guard.
`assertNoForeignPhoto()` runs before every `locator.screenshot()`: it collects every image the
frame loads, `src` and CSS background alike, and fails the run if any URL under `/galleries/`,
`/upload/` or `/_data/i/` is outside the directory the seed reports as `image_dir`. The offending
file is named and nothing is written to disk, so a private face cannot reach the repository even
once. `MIN_SHOTS_WITH_A_PHOTO` guards the guard: a run in which fewer than five frames held any
photo fails rather than reporting that it found nothing wrong. Measured 2026-08-31: 7 of 20
frames hold a photo.

Both were watched red. Pointing shot 04 at a real album failed the run naming four
`galleries/Sefferweich_Allgemein_Fotos/*` derivatives; emptying `PHOTO_PATH_MARKERS` failed it
with `only 0 of 20 frames held a photo at all`.

What stays manual, for the ledger in `docs/agents/TESTING.md`: whether a drawn shape reads as a
person, whether the German wording sounds natural, whether a screenshot is legible and framed on
what the text beside it describes. No oracle exists for any of them.

### E2E coverage added for the earlier phases

Phase 2 translated strings and asserted the wording in page source. Source is not what a reader
sees: several of those strings sit in markup that is hidden on arrival and revealed - or
replaced - by JavaScript, which is exactly the case `.claude/rules/testing.md` reserves for E2E.
Two spec files close that gap. Neither restates a translated string; each compares what the user
sees against what the server put in the same element, so the wording stays in the language file.

- `plugins/typetags/tests/e2e/admin-tags.spec.js` (2 specs, with `support/AdminTagsPage.js`).
  The suite had no admin coverage at all. `Farbe`, `Farbe entfernen` and `Erstellen` are two
  interactions deep - selection mode on, a tag selected, then the colour button - and a source
  assertion stays green if the panel stops opening. The second spec is `[NEG]`: the colour button
  is out of reach until a tag is selected, which is what `04-schlagworte.html` has to tell a
  reader.
- `plugins/provenance/tests/e2e/core-admin-screens.spec.js` (3 specs, with
  `support/CoreAdminPages.js`). Home is this suite for the same reason the core characterization
  tests are: core carries no suite of its own. Covers the album save confirmation becoming
  visible, the album rename dialog opening, and `[ERR]` the tag rename dialog replacing its own
  submit label.

Nothing was added for Phases 1, 3 or 4. Phase 1 was a one-off repair of local state; Phase 3's
E2E was declined in *What We're NOT Doing* on the placement rule and the browser-upload precedent
at `docs/agents/TESTING.md:434`; Phase 4's seed is scaffolding, which carries guards rather than a
suite (`.claude/rules/test-design.md`, *build no apparatus that proves another apparatus*).

Every one of the five passed on its first run, which is normally the tell that a test recorded
code rather than drove it, so each was watched go red against a mutant. Host checksums were
compared against `ddev exec md5sum` before every run.

| Mutant | Expected killer | Result |
|---|---|---|
| `$(".TagSubmit").html(str_yes_rename_confirmation)` deleted | the tag rename `[ERR]` spec | killed |
| `$('.info-message').show()` → `.hide()` | the album save confirmation spec | killed |
| `$("#RenameAlbum").fadeIn()` → `.fadeOut()` | the album rename dialog spec | killed |
| `$('#TypetagsOption').show()` → `.hide()` | the colour panel spec | killed |
| the create label rewritten to `Create` when the panel opens | the colour panel spec | killed |
| the remove-colour label rewritten to `Remove color` when the panel opens | the colour panel spec | killed |
| `.selection-mode-tag` shown on entering selection mode | the `[NEG]` reachability spec | killed |
| `.selection-mode-tag` shown in `updateSelectionContent()`'s zero branch | the `[NEG]` spec | **survived** |

The survivor is honest and worth keeping in the record: `updateSelectionContent()` runs when a
tag is clicked, and the `[NEG]` spec never clicks one. The branch is unreachable on the path that
spec walks, so the mutant proves nothing about its strength. The reachable form of the same
mutation, one row above, is killed.

**Implementation Note**: Screenshots are published content. Review every file by eye before
committing - this is the one step where an automated check cannot decide whether a private
face slipped in. Pause here for manual confirmation.

**Phase 5 verified 2026-08-31.** Twenty screenshots, each reviewed by eye; the frame guard and
its anti-vacuity floor watched red against their own mutants; a twelfth untranslated string found
and fixed with the same unit, integration and red-watch discipline as Phase 2; five new E2E specs
closing the source-versus-DOM gap the earlier phases left, each watched red. Suites after the
change: unit 183 / 56 / 114, integration 183 / 49 / 104, E2E 29 / 30 / 31.

---

## Phase 6: The handbook

### Overview

Six HTML pages and a stylesheet. House style of `language/de_DE/help/cat_modify.html`: short
`h2`/`h3`, `ul` of bolded field names with one-line explanations, `ol` for step sequences.

Writing constraints, applied to every page: brief and crisp, only as exhaustive as necessary.
No bloat, no fluff, no AI slop. No em-dashes, no emojis. Plain verbs, concrete facts, stop
when the facts run out.

### Changes Required

#### [x] 1. Stylesheet
**File**: `docs/handbuch/assets/handbuch.css` (new)
**Changes**: Minimal and self-contained, so a page opens correctly from the filesystem with
no server and no CDN. Readable measure, screenshots capped at container width with a light
border.

#### [x] 2. Index
**File**: `docs/handbuch/index.html` (new)
**Changes**: One paragraph saying what the handbook covers and who it is for, then a list of
the five pages with a one-line summary each. States that person tagging and colored-tag
assignment are open to any logged-in user, while album, photo-text and tag administration
need administrator rights - the permission shapes differ deliberately and a reader who does
not know that will be confused by a missing button.

#### [x] 3. Album page
**File**: `docs/handbuch/01-alben.html` (new)
**Changes**: Creating an album, then setting its description on the properties screen. Must
say the creation dialog has no description field, because that is the first thing a user
looks for and does not find. Covers sub-albums, and the public album page where the
description appears.

#### [x] 4. Photos page
**File**: `docs/handbuch/02-fotos.html` (new)
**Changes**: The browser uploader as the main path. Accepted types
(`jpg, jpeg, png, gif, webp`) and the size limit. Adding an existing photo to a further album
from the Batch Manager. Names FTP and filesystem sync as alternatives for large batches
without documenting them, and warns that sync rejects filenames with umlauts or spaces
(`/^[a-zA-Z0-9-_.]+$/`) and that its Simulation checkbox is on by default.

#### [x] 5. Photo text page
**File**: `docs/handbuch/03-fototexte.html` (new)
**Changes**: The four core fields (Titel, Autor, Aufnahmedatum, Beschreibung), where each
appears publicly, and that the filename cannot be changed. Then the provenance Notiz as the
per-photo free-text field, with the other four provenance fields explained as inherited from
the album and therefore read-only here - this is why four fields on that screen cannot be
edited. Warns that the linked-albums control unlinks any album left out of the selection.

#### [x] 6. Tags page
**File**: `docs/handbuch/04-schlagworte.html` (new)
**Changes**: Creating, renaming, deleting and merging tags in the admin screen. Assigning
tags on photo properties, and that assignment replaces the whole list rather than appending.
Then the fork's colored tags: what the badge means, and adding or removing one directly on
the public picture page as any logged-in user. Notes the eight existing German colored tags.

#### [x] 7. Persons page
**File**: `docs/handbuch/05-personen.html` (new)
**Changes**: The public workflow: hover to see boxes, **Personen markieren**, drag a
rectangle, pick or create a person, Enter to commit, Esc to cancel, `x` to delete. Then
browse-by-person, since each person is mirrored as an ordinary tag. Explains dashed boxes at
50 percent as regions written before a re-crop. Notes the button is disabled with a tooltip
when exiftool is unavailable. States that regions are written into the image file itself, so
they travel with the photo.

### Test cases

Documentation prose has no automated oracle. What can be checked mechanically:

- [x] Every `src` and `href` resolves - no broken image or link `[NEG]` - `check.php`, watched red
      against a mistyped screenshot name
- [x] Every screenshot in `assets/screenshots/` is referenced by at least one page, and every
      reference resolves to an existing file `[ERR]` - both directions, watched red both ways
- [x] Each page is well-formed HTML - checked as **XML**, not as HTML. PHP's HTML parser silently
      repairs an unclosed tag and reports nothing: an unclosed `</figure>` mutant survived it. The
      pages are written valid HTML5 *and* well-formed XML (void elements self-closed, no named
      entity beyond the five XML predefines), and `loadXML()` kills that mutant and two more
- [x] Every admin URL quoted in the text matches a real `admin.php?page=` route - resolved the way
      `admin.php:129-176` resolves it, including the `plugin-`, `album-` and `photo-` aliases;
      watched red against `page=alben`

Correctness of the German, and whether the instructions actually work, is a hand check. It
goes in the ledger in `docs/agents/TESTING.md` with its reason, per
*report gaps, don't hide them*.

### Success Criteria

#### Automated Verification
- [x] A link and image checker over `docs/handbuch/` reports no unresolved reference
- [x] Every file under `assets/screenshots/` is referenced at least once
- [x] No page references a screenshot file that does not exist

#### Manual Verification
- [ ] Each of the five workflows can be completed by following only the handbook, on a clean
      install, without reading the code - **still open**, no oracle but a first-time reader. The
      mechanical half is now automated: every control the handbook names is witnessed by a spec in
      `core-admin-screens.spec.js`, so a screen that moves fails a run instead of rotting silently
- [ ] Every screenshot matches the text next to it - **still open**, no oracle; in the ledger
- [ ] German reads naturally and matches the on-screen wording exactly - **still open** for the
      wording; the strings themselves are guarded where they live, so a change breaks a test at
      its source rather than diverging from the handbook silently
- [x] No em-dashes, no emojis - automated in `check.php` with a byte floor, watched red against an
      em-dash, an emoji and a symbol-range character. "No filler" stays a judgment call
- [x] Pages open correctly from the filesystem with no server - automated as
      `plugins/provenance/tests/e2e/handbuch-pages.spec.js`, 7 specs over `file://`: stylesheet
      applied, no broken image, no console error. Watched red against a broken stylesheet href
- [x] Permission notes are correct: the reader is not told to click something their role
      cannot see - automated both ways. `[NEG]` four core admin screens refuse a normal account;
      `[HAPPY]` a normal account adds and removes a coloured tag on the picture page, which the
      typetags suite could not witness at all because every spec ran as a webmaster

**Phase 6 verified 2026-08-31.** Six pages and a stylesheet; `docs/handbuch/tools/check.php`
checks references, screenshot use in both directions, well-formedness and forbidden characters,
each watched red. Nineteen E2E specs added for controls the handbook names that no layer
witnessed, each watched red against a production mutant - see *The handbook's own claims, and
the E2E gap they exposed* in `docs/agents/TESTING.md`. Two findings: the typetags suite could not
witness its own permission model, and `01-alben.html` documented the sort action by what its icon
looks like rather than what it does. E2E after the change: provenance 48, typetags 32, persons 31.

**Implementation Note**: Pause here for manual confirmation before Phase 7 records anything
as done.

---

## Phase 7: Record what was decided

### Overview

Close the loop so a later reader cites the decision instead of re-litigating it.

### Changes Required

#### [x] 1. The decision
**File**: `docs/agents/decisions/0021-german-handbook-location-and-demo-content.md` (new)
**Changes**: Next free number, confirmed 2026-08-31 (0020 is the highest). Records three
decisions and their rejected alternatives: the handbook lives in `docs/handbuch/` rather than
`language/de_DE/help/`; screenshots come from generated synthetic content rather than blurred
or cropped real scans; translations are fixed before screenshotting rather than documenting a
half-English UI.

#### [x] 2. The testing record
**File**: `docs/agents/TESTING.md`
**Changes**: Add the new core characterization tests to the suite description with dated
figures. Add non-coverage entries with justification for: an E2E spec per documented workflow
(placement rule, plus the existing browser-upload precedent at `:434`); pixel-diffing the
screenshots (`:428`); a test over the handbook prose itself (no oracle, and building an
apparatus that proves another apparatus). Add the hand-check ledger entry for walking each
documented workflow, with its reason for not being automatable.

#### [x] 3. The backlog
**File**: `docs/backlog.md`
**Changes**: Add the second unpushed typetags submodule commit to the existing entry about
the submodule being ahead of its origin. Add an entry for the remaining half-translated
upstream help files if any were noticed. Add the deferred investigation of why persons'
`--restore` leaked the five albums - and widen it: measured 2026-08-31 during Phase 1, a
**green** persons integration run also leaves one `upload/persons-test/*.png` and its
`_original` behind with no image row, so the leak is not confined to interrupted runs. No
reproducing test yet: it needs the diagnosis this plan defers, and a skipped test that names
no cause is prose in test form.

#### [x] 4. The rules index
**File**: `CLAUDE.md` and `.claude/rules/`
**Changes**: Note where the handbook lives and the two commands that regenerate its content,
so the next agent does not rediscover them. `CLAUDE.md` stays under 100 lines - if the
addition would exceed it, the content goes into a rules file with a read-trigger instead.
Update `.claude/rules/plugin-test-suites.md` with the new core characterization tests and the
fact that `local/language/de_DE.lang.php` is now tracked.

#### [x] 5. Mutation pass over the new unit guards
**File**: `docs/agents/TESTING.md`
**Changes**: The Phase 2 key guards are the only new unit-layer code, so they get the
end-of-plan strength check per `.claude/rules/mutation-testing.md`. Recorded as a prose table,
not a script. Wait for each mutant to reach the container (compare the host checksum against
`ddev exec md5sum <file>`) before running - a mutant applied and immediately tested is read
from the pre-mutation file and shifts every result by one.

| Mutant | Expected killer | Result |
|---|---|---|
| `assertSame(1, ...)` → `assertGreaterThanOrEqual(0, ...)` | every case in the guard | survived - invalid mutant |
| the anti-vacuity `MIN_BYTES` guard deleted | the case reading a path that does not exist | survived - invalid as written; probed instead |
| one literal in the data set altered by one character | that literal's case only | killed |
| `substr_count` → `str_contains` | the two-occurrence cases for `Add tag` / `Remove tag` | killed |

Run 2026-08-31. Five further mutants were added because the four above are all test-side, and a
weakened assertion can never turn a green run red: each was paired with a mutant that damages what
the test watches (a literal altered and duplicated in `albums.tpl`, a key renamed in the override,
a shadowing key added to core's `de_DE`, the placeholders reordered). Nine in total, seven killed,
two survivors both invalid with the reason recorded. The full table, and the second probe that
proved the `MIN_BYTES` guard reachable, are in `docs/agents/TESTING.md`.

### Success Criteria

#### Automated Verification
- [x] `docs/agents/decisions/0021-*.md` exists and no other file claims number 0021
- [x] `CLAUDE.md` is under 100 lines
- [x] Every file in `.claude/rules/` is under 500 lines
- [x] Every command quoted in the updated docs actually runs
- [x] Full run of all three plugin suites, all layers, passes

#### Manual Verification
- [x] The decision file states what was rejected and why, not only what was chosen
- [x] Every count in the updated docs carries the date it was measured
- [x] The mutant table records honestly what survived, with the reason
- [x] No instruction file now claims something untrue about the repository

---

**Phase 7 verified 2026-08-31.** The four manual items were closed by inspection and by a factual
audit rather than by assertion: every claim added to `CLAUDE.md`, `.claude/rules/handbook.md` and
`plugin-test-suites.md` was checked against the file, line or command it names.

The same pass read the handbook against the application and found two wrong statements - the privacy
levels and the reach of the move action - plus one true-but-unwitnessed order claim. All three are
recorded in *Reading the handbook against the application* in `docs/agents/TESTING.md`, with the two
tests added to hold them.


## Testing Strategy

### Layer placement

The handbook documents behaviour that already exists, so almost all new coverage is
characterization at the **integration** layer: core album, photo-text and tag operations are
not pure functions and cannot be loaded without a database. The only new **unit** code is the
Phase 2 key guards, which read template files and need no runtime. No new **E2E** specs: the
five workflows are already covered at the browser layer where a browser is the only witness
(persons, colored tags, provenance), and browser upload coverage is precedent-refused.

### Unit tests

- [x] `GermanOverrideKeyTest` - one case per translated literal, asserting it still occurs
      the expected number of times in the file that emits it, each preceded by the
      `MIN_BYTES` anti-vacuity guard `[ERR]`
- [x] The two-occurrence cases for `Add tag` and `Remove tag` assert the **escaped** form as
      it appears inside the PHP string literal `[BVA]`

### Integration tests

Enumerated per file in Phase 3. Summary: 11 cases for albums, 9 for photo text, 13 for tag
CRUD, 3 for upload. Every one is `[ERR]` unless it asserts a permission gate, which is a real
requirement and tagged `[NEG]`.

### End-to-end tests

None added. Recorded as deliberate non-coverage in Phase 7 with the reason.

### Regression

- [x] All three plugin unit suites - run 2026-08-31: 56 / 183 / 114
- [x] All three plugin integration suites - 49 / 184 / 104
- [x] All three plugin E2E suites - 32 / 48 / 31
- [x] Suites pass twice in a row and in reverse order - done for the two suites this verification changed (provenance integration twice plus `--order-by=reverse`, provenance E2E twice). The other seven were run once; claiming more would be claiming a run that did not happen
- [x] Album, image and tag counts unchanged after a full run - 5 albums, 105 photos, 8 tags before and after

### Manual testing steps

1. Walk each of the five workflows following only the handbook, on a clean install.
2. Confirm every screenshot matches the screen it claims to show.
3. Confirm no screenshot contains a real person or a real filename.
4. Confirm every screen the handbook shows renders German.

### Test commands

```bash
# Unit
ddev exec plugins/provenance/vendor/bin/phpunit --testsuite unit --configuration plugins/provenance/phpunit.xml
ddev exec plugins/typetags/vendor/bin/phpunit  --testsuite unit --configuration plugins/typetags/phpunit.xml
ddev exec plugins/persons/vendor/bin/phpunit   --testsuite unit --configuration plugins/persons/phpunit.xml

# Integration
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; \
  plugins/provenance/vendor/bin/phpunit --testsuite integration --configuration plugins/provenance/phpunit.xml'
ddev exec bash -c 'set -a; . local/config/typetags-test.env; set +a; \
  plugins/typetags/vendor/bin/phpunit --testsuite integration --configuration plugins/typetags/phpunit.xml'
ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; \
  plugins/persons/vendor/bin/phpunit --testsuite integration --configuration plugins/persons/phpunit.xml'

# E2E
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; cd plugins/provenance && npx playwright test'
ddev exec bash -c 'set -a; . local/config/typetags-test.env;  set +a; cd plugins/typetags  && npx playwright test'
ddev exec bash -c 'set -a; . local/config/persons-test.env;   set +a; cd plugins/persons   && npx playwright test'

# Syntax
ddev exec php -l <file>

# Handbook
ddev exec php docs/handbuch/tools/check.php
ddev exec php docs/handbuch/tools/seed.php --scenario=demo
ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; node docs/handbuch/tools/shoot.js'
ddev exec php docs/handbuch/tools/seed.php --restore
```

## Performance Considerations

None for the handbook itself: static HTML opened from the filesystem.

The seed generates six to eight 1200x800 PNGs, a few hundred kilobytes each, so the committed
screenshot set stays small. Phase 1 reclaims roughly 63 MB, more than the handbook adds.

The new integration tests add a handful of seconds to a suite already measured at 49.2s
(2026-08-29). The upload cases are the slowest because they post a real file and then wait
for the lounge to empty.

## Migration Notes

`local/language/de_DE.lang.php` becomes tracked, so a fresh clone gets the German strings
without any step. Nothing else changes for an existing install.

The typetags submodule gains a commit that must be pushed to
`github.com/christianbaumann/Piwigo-Colored-Tags`. It already carries one unpushed commit
(`44fdd06`), which is why `git submodule update --init` on a fresh clone fails with
`not our ref` today (measured 2026-08-31). Both must be pushed together, or the clone
instructions in `.claude/rules/plugin-test-suites.md` stay broken.

Phase 1 permanently deletes five albums, five photo rows and their files. They are leftover
test scaffolding, not gallery content, but the deletion is not reversible.

## References

- `docs/agents/research/2026-08-31-german-end-user-documentation.md` - the research this plan implements
- `language/de_DE/help/cat_modify.html` - the house style the handbook follows
- `include/functions.inc.php:1819-1938` - `load_language()` and the local override path
- `include/common.inc.php:239` - where the local override is loaded
- `plugins/persons/tests/Support/PiwigoRuntime.php:18-74` - CLI-safe core bootstrap
- `plugins/persons/tests/Support/FixtureBuilder.php:52-68` - the throwaway-install guard
- `plugins/persons/tests/e2e/support/seed.php` - the seed script shape to follow
- `plugins/provenance/tests/Integration/CoreDeleteCategoriesCharacterizationTest.php:1-22` - the `[ERR]` docblock pattern
- `docs/agents/TESTING.md:174-212` - the deliberate non-coverage table
- `docs/agents/TESTING.md:428` - pixel-diffing rejected
- `docs/agents/TESTING.md:434` - browser upload coverage replaced by integration coverage
- `docs/agents/decisions/0020-persons-index-is-derived-the-file-is-the-source-of-truth.md` - why regions live in the file
