---
date: 2026-09-01T14:45:50+00:00
git_commit: 5b24a25ff02b1cc647626d32c833160f95ac4f63
branch: master
topic: "Close the typetags.type.add permission hole and correct the eight handbook findings"
tags: [plan, handbuch, typetags, security, provenance, persons, documentation]
status: in-progress
---

# Handbook corrections and the `typetags.type.add` permission fix

## Overview

Two pieces of work, sharing one origin: a walkthrough of `handbuch/` against the live sandbox
produced eight findings ([research](../research/2026-09-01-handbuch-vs-live-deployment-findings.md)),
and resolving that research's last open question uncovered an unauthenticated write endpoint
that is live on a public host.

The security fix comes first and is unrelated to the documentation in everything but
provenance. The rest corrects seven handbook statements, documents one screen that appears
nowhere today, adds two application-level guards, and records one privacy consequence as a
decision.

## Current State Analysis

**`typetags.type.add` is reachable by anonymous callers.** `plugins/typetags/main.inc.php:83-91`
registers it with no options array and no `pwg_token` parameter; its body
(`main.inc.php:141-187`) performs no `is_a_guest()`, no `check_status()` and no token
comparison, unlike `ws_typetags_image_addTag` (`:189-199`) and `ws_typetags_image_removeTag`
(`:240-250`) which do both. Core enforces `admin_only` only when the key is present
(`include/ws_core.inc.php:510-523`), and the residual `ws_isInvokeAllowed` gate
(`include/ws_functions.inc.php:13-29`) is satisfied by the anonymous `guest` user whenever
`$conf['guest_access']` is true (default, `include/config_default.inc.php:628`).

Verified against the live sandbox 2026-09-01, non-destructively — a unique name with an
invalid colour is refused by `check_color()` *before* `single_insert()`, so reachability is
provable without writing a row:

```
anonymous GET  ws.php?method=typetags.type.add&typetag_name=<unique>&typetag_color=ZZZZZZ
  -> {"stat":"fail","err":1003,"message":"Invalid color"}
anonymous GET  ws.php?method=pwg.tags.getAdminList
  -> {"stat":"fail","message":"Access denied"}
```

`err 1003` proves the body executed; the second call proves the gate mechanism works and this
method is the outlier, not a misconfigured install.

Effect of a successful call: one `INSERT` into `piwigo_typetags` (`maintain.class.php:35-42`),
which is the colour palette the admin tags screen offers and `typetags_render()` reads. No
`post_only`, so GET works. No rate limit. The only duplicate check is on `name`
(`main.inc.php:147-156`), so distinct names insert unboundedly against a
`smallint(5) unsigned` id. `typetag_name` has **no length validation** — a name longer than
the `varchar(255)` column throws an uncaught `mysqli_sql_exception` from
`include/dblayer/functions_mysqli.inc.php:132` that renders a full stack trace into the HTTP
response.

**The only legitimate caller is an administrator.** `APICreateColor()` at
`plugins/typetags/template/tags.js:227-247` is the sole caller. `tags.js` is injected by
`typetags_admin_prefilter()` (`include/events_admin.inc.php:52-64`), registered only under
`if (defined('IN_ADMIN'))` (`main.inc.php:56-61`), into the `tags` template — that is
`admin/tags.php`, which opens `check_status(ACCESS_ADMINISTRATOR)` (`admin/tags.php:15`). So
`admin_only => true` matches the partner method `typetags.tags.setType` (`main.inc.php:80`)
and breaks no existing path.

**No test covers this method's permission.** `ColorHelperCallersTest.php:48-62` is its only
caller in the suite and calls it as the webmaster; it cleans up in `tearDown()` by fixture
name (`:26-30`), so it needs no repair and will keep passing under `admin_only`.

**The handbook.** Seven statements are wrong or imprecise, each pinned to a line in the
research. One screen — the album-level provenance section — is documented nowhere: `Physisches
Album` occurs only in `handbuch/03-fototexte.html`, and no album page mentions it. Its
required second step (`Auf N Fotos anwenden`) is therefore also undocumented, which is why
saving appeared to do nothing.

**What guards the handbook today.** `handbuch/tools/check.php` is structural only — references
resolve, screenshots referenced both ways, well-formed XML, `admin.php?page=` routes resolve,
no em-dash or emoji, with anti-vacuity floors. It never compares a quoted label against the
application. `plugins/provenance/tests/e2e/core-admin-screens.spec.js` is the established home
for "the controls the handbook tells a reader to click" and already asserts presence, DOM
order, counts and enabled state (14 executed). Nothing joins handbook text to application
state, and `docs/agents/TESTING.md:221` records that as a deliberate refusal.

## Desired End State

`typetags.type.add` refuses every non-administrator and cannot be made to emit a stack trace.
The handbook's seven wrong statements are correct, the album provenance screen and its
required step are documented, two application behaviours the handbook now describes are
witnessed by specs, and the guest-visible person name is recorded as an accepted decision
rather than living only in a test comment.

Verify by: the typetags integration suite going red on the new `[NEG]` cases before the fix
and green after; `check.php` passing; the provenance E2E suite passing with two new specs; and
a reading of the eight findings against the amended pages.

### The permission change

```
                         before                    after
typetags.tags.setType    admin_only  ..............  admin_only      (unchanged)
typetags.type.add        (no options)  ............  admin_only      (Phase 1)
typetags.image.addTag    guest+token in body  .....  unchanged       (decision 0005)
typetags.image.removeTag guest+token in body  .....  unchanged       (decision 0005)
```

### Key Discoveries

- `include/ws_core.inc.php:510-523` — `isset($method['options']['admin_only']) and ...`, so an
  absent options array disables the check entirely
- `include/ws_functions.inc.php:13-29` + `config_default.inc.php:628` — the residual gate
  admits the anonymous guest
- `admin/tags.php:15` — the only caller's screen is `ACCESS_ADMINISTRATOR`, so the fix is
  non-breaking
- `plugins/typetags/tests/Integration/PluginActivationTest.php:148-165` — the exact `[NEG]`
  shape to copy: `logout()`, call, re-`login()`, assert `stat === 'fail'` **and** assert the
  side effect did not happen
- `plugins/typetags/tests/Support/WsClient.php:18,47` — `call()` and `callGet()`; the hole is
  reachable over GET, so both verbs need a case
- `plugins/typetags/tests/Support/Config.php:57,62` — `normalUsername()` / `normalPassword()`
  exist, so the authenticated-non-admin case is writable
- `themes/default/template/picture.tpl:300-303` — the two injection anchors that fix section
  order; provenance/persons prepend at `{/strip}\n</dl>`, typetags at `{if isset($metadata)}`
- `admin/themes/default/template/batch_manager_global.tpl:325-330` — the
  `$nb_thumbs_set > $nb_thumbs_page` branch behind finding 2

## What We're NOT Doing

- **Not changing the eight-tag list in `04-schlagworte.html`.** Decided: the remote was the
  thing to fix, and its tags have been recreated from local. The handbook documents this
  gallery.
- **Not hiding person names from guests.** Decided: record it as accepted (Phase 5) and correct
  the handbook (Phase 2). Filtering them would mean changing core's related-tags row, which
  `PicturePageSourceTest.php:218-222` records as deliberately avoided.
- **Not making album provenance auto-apply on save.** Decided: documentation only. No decision
  file recording why the copy-down is manual either — that was offered and not taken.
- **Not re-running `shoot.js`.** Phase 2 changes two captions but no screen changed. The
  handbook rule is to commit a re-shoot only when the screen actually changed; a re-shoot
  reseeds the demo album and takes a fresh album id, so 7 of 20 shots would churn for nothing.
- **Not committing a remote tag-seeding script.** The eight tags were recreated by hand and a
  redeploy is a manual step you own. Recorded in `docs/backlog.md` (Phase 5) instead, since
  decision 0023 means a wipe loses them again.
- **Not writing any check that reads handbook prose.** `.claude/rules/test-design.md` names it
  as the apparatus-proving-an-apparatus case and `docs/agents/TESTING.md:221` already records
  the refusal.
- **Not touching finding 8a** (`album-<id>` vs `album-<id>-properties`). Both URLs resolve to
  the same screen (`admin.php:147-155`, `admin/album.php:42`); the handbook is accurate.
- **Not pinning the Herkunft-vs-Personen row order.** Unpinned by design; nothing asserts it
  and nothing is broken.
- **Not adding `pwg.persons.rescan` to the deploy.** Out of scope; stays a backlog item.
- **Not auditing the other three typetags methods.** `image.addTag`/`removeTag` are settled by
  decision 0005; `setType` is already `admin_only`.

## Implementation Approach

Security first, because it is live and publicly reachable. Then documentation, ordered
mechanical-before-substantive so the large new section in Phase 3 lands on already-correct
pages. Guards after the prose they protect, because they assert application behaviour the
prose now describes. Records last.

Phase 1 follows the repo's bug discipline: reproducing test first, watched red, then the fix.
Phases 2 and 3 are documentation-only and are the sole exception to "run the affected suite
after every change" — but `check.php` still runs, because it is mechanical.

---

## Phase 1: Close the `typetags.type.add` hole

### Overview

Add the reproducing `[NEG]` tests, watch them fail, then gate the method `admin_only` and
validate the name length. Two defects, one phase: the missing gate and the uncaught
`mysqli_sql_exception` that leaks a stack trace.

### Changes Required

#### [x] 1. Reproducing tests, written and watched red first
**File**: `plugins/typetags/tests/Integration/TypeAddPermissionTest.php` (new)
**Changes**: Assert `stat === 'fail'`, the `err` code, **and** that the row was not written. A
status assertion alone would pass against a method that refuses and inserts anyway.

Use the **cookie-suppression** form for the anonymous case, not the logout/login dance:
`WsClient::call()` takes a third argument `$useCookies` (`WsClient.php:18`), and
`AddTagTest::testGuestIsRejected` (`:109-120`) passes `false` so the logged-in session is left
intact. That is the cleaner precedent; `PluginActivationTest`'s logout/re-login exists because
that test needs its session back for a later DB assertion.

Expected `err` for the gated cases is **401**, from `PwgError(401, 'Access denied')` at
`include/ws_core.inc.php:513`.

```php
final class TypeAddPermissionTest extends TestCase
{
    private const FIXTURE_PREFIX = '_test_type_add_permission_';

    // setUp: new Db, new WsClient, login as webmaster
    // tearDown: DELETE FROM piwigo_typetags WHERE name LIKE '_test_type_add_permission_%'
    //           (LIKE, not '=': a leaked row from a failing case must not survive)
    //           then logout

    private function countRows(string $name): int
    {
        $escaped = $this->db->escape($name);
        return (int)$this->db->scalar(
            "SELECT COUNT(*) FROM piwigo_typetags WHERE name = '$escaped'"
        );
    }
}
```

**The authenticated-non-admin case has no precedent in this suite.** `Config::normalUsername()`
/ `normalPassword()` exist (`Config.php:57,62`) but nothing at the PHPUnit layer uses them —
`typetags_normal` is currently only an E2E account. Each `WsClient` owns its own cookie jar
(`WsClient.php:10`), so build a **second** client rather than re-logging the first:

```php
$normal = new WsClient();
$normal->login(Config::normalUsername(), Config::normalPassword());
$res = $normal->call('typetags.type.add', array(...));
$normal->logout();
```

If `local/config/typetags-test.env` lacks the normal-account variables, `Config::required()`
throws naming the variable and `create-test-users.php`; run that script first.

#### [x] 2. Gate the method
**File**: `plugins/typetags/main.inc.php:83-91`
**Changes**: Add the options array, matching `setType` at `:80`.

```php
   $service->addMethod(
     'typetags.type.add',
     'ws_typetags_type_add',
     array(
       'typetag_name' => array(),
       'typetag_color' => array('info' => 'In format RRVVBB (Example : FF0000 for red)')
       ),
-    'Create a tag color'
-    );
+    'Create a tag color',
+    null,
+    array('admin_only'=>true)
+  );
```

Note the fourth argument is the description and the fifth is `$include_file`; `setType` passes
`null` there. Getting the argument positions wrong silently produces a method with no options
again, which is the exact defect being fixed — so the `[NEG]` tests are the check, not a
reading of the diff.

#### [x] 3. Validate the name length
**File**: `plugins/typetags/main.inc.php`, in `ws_typetags_type_add()` before the duplicate
`SELECT` at `:147`
**Changes**: Refuse an over-long name with a `PwgError` rather than letting `single_insert()`
throw. The bound is the column width from `maintain.class.php:35-42`, declared once as a
constant rather than typed twice.

```php
// plugins/typetags/include/functions.inc.php
define('TYPETAGS_NAME_MAX_LENGTH', 255); // piwigo_typetags.name is varchar(255)

// main.inc.php, in ws_typetags_type_add()
if (mb_strlen($name) > TYPETAGS_NAME_MAX_LENGTH)
{
  return new PwgError(WS_ERR_INVALID_PARAM, l10n('Invalid tag name'));
}
```

`mb_strlen`, not `strlen`: the install is German and a name of 255 umlauts is 255 characters
but more than 255 bytes. Whether the column counts characters or bytes decides the correct
bound — verify against the live schema before fixing the constant, and record which it is.

### Test cases

Technique legend is in `.claude/rules/test-design.md`. `WsClient::call()` posts and
`callGet()` uses GET; the endpoint has no `post_only`, so both verbs are part of the
equivalence class of "callers".

**`TypeAddPermissionTest`**
- [x] `testAnAnonymousCallerIsRefused` — `call(..., false)` to suppress cookies; `stat === 'fail'`,
      `err === 401`, zero rows `[NEG]`
- [x] `testAnAnonymousGetIsRefused` — the hole was GET-reachable, so the verb is its own
      equivalence class. **`callGet()` takes no `$useCookies` argument** (`WsClient.php:47`),
      so suppression is unavailable here: use a **fresh `WsClient` that never logs in**, whose
      cookie jar is empty by construction `[NEG]` `[ECP]`
- [x] `testAnAuthenticatedNonAdminIsRefused` — second `WsClient` as `typetags_normal`;
      `err === 401`, zero rows `[NEG]` `[ECP]`
- [x] `testTheWebmasterCanStillCreateAColour` — the `[HAPPY]` case that proves the fix did not
      break the only real caller, and stops the three `[NEG]` cases passing vacuously against a
      method that refuses everyone
- [x] `testAnOverLongNameIsRefusedWithoutAStackTrace` — name at the bound + 1; asserts `stat === 'fail'`,
      zero rows, and that the body contains neither `mysqli_sql_exception` nor `Stack trace` `[BVA]` `[NEG]`
- [x] `testANameAtTheBoundIsAccepted` — exactly the bound; `[BVA]` `[HAPPY]`, the other half of the boundary
- [x] `testAnInvalidColourIsStillRefusedForAnAdmin` — `ZZZZZZ` → err 1003; guards that the new
      length check did not displace `check_color()` `[NEG]`

Anti-vacuity: each `[NEG]` case asserts the row count is zero **and** the `[HAPPY]` case proves
a row can be created at all, so a method that refuses unconditionally fails the suite.

### Techniques not applicable
- **State transition**: not applicable — `type.add` is a single insert with no lifecycle.
- **Decision table**: not applicable — the gate has one condition (is the caller an admin) and
  two outcomes. The name/colour validations are independent single conditions, covered by BVA.

### Success Criteria

#### Automated Verification
- [x] Every new `[NEG]` case was watched **red before** the fix, for the right reason
      (`stat === 'ok'` and a row present), and green after
- [x] `ddev exec php -l plugins/typetags/main.inc.php` passes
- [x] `ddev exec php -l plugins/typetags/include/functions.inc.php` passes
- [x] Typetags integration suite passes: `ddev exec bash -c 'set -a; . local/config/typetags-test.env; set +a; plugins/typetags/vendor/bin/phpunit --testsuite integration --configuration plugins/typetags/phpunit.xml'`
- [x] `ColorHelperCallersTest::testTypeAddReturnsContrastColour` still passes — it calls the
      method as the webmaster and is the regression canary for the fix being too strict
- [x] Typetags unit suite passes
- [x] Typetags E2E passes, in particular `admin-tags.spec.js`, which drives the colour panel
      that calls this method: `ddev exec bash -c 'set -a; . local/config/typetags-test.env; set +a; cd plugins/typetags && npx playwright test'`
- [x] Suite passes twice in a row and in reverse order; `piwigo_typetags` row count identical
      before and after a full run
- [x] `ddev mysql -e "SELECT COUNT(*) FROM piwigo_typetags"` returns 8 after the run

#### Manual Verification
- [x] ~~The admin tags screen can still create a colour end to end~~ — **automated**, no longer
      manual. Successor: `admin-tags.spec.js` › *creating a colour on the tag administration
      screen* (2 specs), which walks the documented path (selection mode on, a tag selected,
      **Farbe**, **Eine neue Farbe hinzufügen**, **Erstellen**), asserts the method answered
      `ok` to the browser's own request, that the swatch joins the palette, and that it
      survives a reload. Watched red 2026-09-01 against a `post_only=>true` mutant on
      `type.add` — the failure mode a browser is the only witness for, since `tags.js:228-247`
      sends GET and carries no token. The four pre-existing specs in the file stayed green
- [ ] Re-run the non-destructive reachability probe against the sandbox after redeploy and
      confirm it now answers `401 Access denied` rather than `1003 Invalid color`
      — **stays manual**: it needs a redeploy, which is operator-owned. Its local equivalent is
      `TypeAddPermissionTest::testAnAnonymousGetIsRefused`, the same anonymous GET, asserting
      401 where the probe got 1003

**Implementation Note**: The fix is live-affecting only after a redeploy, which is yours to
run. The local suite is the gate here. Pause for manual confirmation before Phase 2.

---

## Phase 2: Handbook corrections

### Overview

Seven statements, each pinned. Documentation only — no suite runs except `check.php`.

Writing constraints carried from the handbook plan: brief and crisp, no bloat, no em-dashes,
no emoji, plain verbs. `check.php` enforces the last two mechanically.

### Changes Required

#### [ ] 1. Finding 2 — Batch Manager selection labels
**File**: `handbuch/02-fotos.html:112-116`
**Changes**: The current text names only **Alles**. Say that the labels depend on whether the
set fits one page, per `batch_manager_global.tpl:325-330`.

```
Unter Auswahl die gewünschten Fotos anklicken. Passt das Fotoset auf eine Seite, wählt
Alles alle Fotos des Sets. Bei mehreren Seiten stehen dort stattdessen Die ganze Seite
und Das ganze Set. Nichts hebt die Auswahl auf, Invertieren kehrt sie um.
```

#### [ ] 2. Finding 3 — the 24-hour rule
**File**: `handbuch/04-schlagworte.html:104-110`
**Changes**: The section implies the notice is immediate. Add the condition from
`admin/include/functions.php:438-439`. The rest of the section is accurate and stays.

```
Trägt kein Foto ein Schlagwort mehr, meldet die Seite oben ... Die Meldung erscheint
erst, wenn das Schlagwort seit mehr als einem Tag unbenutzt und unverändert ist. Ein
gerade angelegtes Schlagwort taucht dort also noch nicht auf.
```

#### [ ] 3. Finding 5 — the title is prefilled
**File**: `handbuch/03-fototexte.html:28-31`
**Changes**: "Bleibt sie leer, zeigt Piwigo den Dateinamen an" describes a state no ingest path
produces (`functions_upload.inc.php:366-369`, `site_update.php:538`).

```
Titel: die Überschrift des Fotos. Beim Hochladen trägt Piwigo den Dateinamen ohne
Endung ein und ersetzt Unterstriche durch Leerzeichen, aus zz_test_a.jpg wird also
zz test a. Wird das Feld von Hand geleert, zeigt Piwigo wieder den Dateinamen an.
```

#### [ ] 4. Finding 7a — colored and colorless tags share one line
**File**: `handbuch/04-schlagworte.html:185` (figcaption) and `:205-209` (body)
**Changes**: Both claim colorless tags stand as text below the badges. They do not
(`picture.tpl:214`, one comma-separated `<dd>`).

```
:185  Vergebene Schlagworte. Farbige tragen ein x zum Entfernen.
:205  Beides wirkt sofort, ohne Speichern. Vergebene Schlagworte stehen in einer Zeile,
      durch Kommas getrennt; farbige als Plakette, farblose als schlichter Text.
      Schlagworte ohne Farbe lassen sich hier nicht ändern und werden in der Verwaltung
      gepflegt.
```

#### [ ] 5. Finding 7b — where the `+` badges sit
**File**: `handbuch/04-schlagworte.html:176-181`
**Changes**: "darunter" reads as directly under the Schlagworte section. The block is injected
at `{if isset($metadata)}` (`events_public.inc.php:5`), i.e. after `</dl>` — below every row
including Herkunft.

```
Auf der Seite eines Fotos steht rechts der Abschnitt Schlagworte mit den bereits
vergebenen. Die noch nicht vergebenen farbigen Schlagworte stehen am Fuß der rechten
Spalte, unterhalb aller Angaben und damit auch unterhalb von Herkunft, jeweils mit
einem + davor.
```

#### [ ] 6. Finding 8b — the heading over-promises adjacency
**File**: `handbuch/03-fototexte.html:25`
**Changes**: `picture_modify.tpl` puts Verknüpfte Alben, Album-Vorschaubild and Schlagworte
between Aufnahmedatum and Beschreibung. Retitle so the heading claims no adjacency; the
existing "Die weiteren Felder derselben Seite" section already covers the rest.

```
<h3>Die vier Textfelder</h3>  ->  <h3>Titel, Autor, Aufnahmedatum und Beschreibung</h3>
```

Add one sentence after the list: that the three fields below sit between Aufnahmedatum and
Beschreibung on screen.

#### [ ] 7. Finding 8c — the merge hint is above, not beside
**File**: `handbuch/04-schlagworte.html:84-88`
**Changes**: `tags.tpl:105` is a `<p>` above the `<select>` at `:107`.

```
Der Hinweis Die anderen Schlagworte werden entfernt steht darüber.
```

#### [ ] 8. Finding 6 — what a guest actually sees
**File**: `handbuch/05-personen.html:13-18`
**Changes**: "Nicht angemeldete Besucher sehen von alldem nichts" is true of the boxes and the
Personen row, false of the name. Correct it here, and add a note where the tag mirror is
already explained (`:110-126`).

```
:17   Nicht angemeldete Besucher sehen weder die Rahmen noch die Zeile Personen.
:126  Weil jede markierte Person zugleich ein gewöhnliches Schlagwort ist, erscheint ihr
      Name auch für nicht angemeldete Besucher in der Zeile Schlagworte und auf der
      öffentlichen Schlagwortseite. Verborgen bleibt für sie nur, wo im Bild die Person
      markiert ist.
```

### Test cases

Documentation prose has no automated oracle, and a checker that reads prose is forbidden. What
runs:

- [ ] `ddev exec php handbuch/tools/check.php` — references resolve, screenshots referenced
      both ways, well-formed XML, routes resolve, no em-dash or emoji `[NEG]`
- [ ] Every edited page is still well-formed **XML**, which is what check 5 enforces — void
      elements self-closed, no named entity beyond the five XML predefines. An unclosed tag
      survives an HTML parser and is the mutant that motivated `loadXML()`
- [ ] No screenshot reference added or removed, so the both-ways screenshot check still passes

### Success Criteria

#### Automated Verification
- [ ] `ddev exec php handbuch/tools/check.php` exits 0 and reports 6 pages, 20 screenshots all
      referenced, and a byte count at or above its floor
- [ ] `ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; cd plugins/provenance && npx playwright test handbuch-pages.spec.js'` passes — the pages still
      open over `file://` with the stylesheet applied and no console error
- [ ] `git diff --stat handbuch/` touches only the files named above, and no `.png`

#### Manual Verification
- [ ] Each of the eight corrected statements now matches what the screen does, read against the
      research finding it closes
- [ ] The German reads naturally and matches the on-screen wording — no automated oracle
      (`docs/agents/TESTING.md:789`)
- [ ] Screenshots 14 and 15 still show what their amended captions describe. If either no
      longer does, that is a re-shoot, which this plan otherwise excludes

**Implementation Note**: Documentation only. Pause for manual confirmation before Phase 3.

---

## Phase 3: Document the album provenance screen and its required step

### Overview

The substantive gap. The album-level provenance section is documented nowhere, and its second
step is required for anything to appear publicly. A reader following the handbook today fills
in four fields, saves, sees nothing, and has no way to find out why.

### Changes Required

#### [ ] 1. A new section on the album page
**File**: `handbuch/01-alben.html`, after "Beschreibung ergänzen"
**Changes**: Document the section as it is: the `Herkunft` button opening a modal with four
fields, `Einstellungen sichern` storing them on the album, and the two buttons that carry them
further. Names and behaviour from `album_provenance.tpl:21-69` and `ws_functions.inc.php:94-176`.

```
<h3>Herkunft des Albums</h3>
  Die Schaltfläche Herkunft öffnet einen Dialog mit vier Feldern: Physisches Album,
  Eigentümer, Gescannt am und Notiz. Sie beschreiben, woher die Vorlagen des Albums
  stammen.

  Einstellungen sichern speichert sie am Album. Die Meldung lautet danach
  Herkunft gespeichert.

  Wichtig: Damit stehen die Werte noch an keinem Foto. Erst Auf N Fotos anwenden
  überträgt sie auf die Fotos des Albums; N ist deren Anzahl. Ohne diesen Schritt
  bleibt die Zeile Herkunft auf der Fotoseite leer.

  In N Dateien schreiben schreibt die Werte zusätzlich in die Bilddateien selbst.
  Diese Schaltfläche fehlt, wenn der Server keine Metadaten schreiben kann.
```

Note the modal's fourth field is labelled **Notiz**, while the photo screen shows the same
value as **Albumnotiz** — the album's note lands in the photos' `provenance_album_note` column
(`provenance_copy_down_map()`, `functions.inc.php:177-185`). Say so, or a reader matching the
two screens will think one is missing.

#### [ ] 2. Point the photo page at it
**File**: `handbuch/03-fototexte.html:105-117`
**Changes**: The four read-only fields are explained as album-maintained but the mechanism is
not named. Add the required step and a cross-reference.

```
Sie gelten für das ganze Album und werden dort gepflegt, in den Eigenschaften des
Albums (siehe Herkunft des Albums). Sie erscheinen hier erst, wenn sie im Album mit
Auf N Fotos anwenden auf die Fotos übertragen wurden. Ein Foto, das später zum Album
hinzukommt, übernimmt sie von selbst.
```

The last sentence is the inheritance path through the two fork-local triggers
(`events_inherit.inc.php`, `main.inc.php:50-53`), default mode `keep`.

#### [ ] 3. Cross-links both ways
**File**: `handbuch/01-alben.html`, `handbuch/03-fototexte.html`
**Changes**: A link from the new album section to `03-fototexte.html` for the per-photo Notiz,
and back. `check.php` verifies both resolve.

### Test cases

- [ ] `ddev exec php handbuch/tools/check.php` — the new section adds internal `href`s; both
      directions must resolve `[NEG]`
- [ ] The new section quotes no `admin.php?page=` route that check 7 cannot resolve. If one is
      quoted it must be a real route — `album-<nummer>` resolves via the alias, a made-up one
      fails the check `[NEG]`

### Success Criteria

#### Automated Verification
- [ ] `ddev exec php handbuch/tools/check.php` exits 0, with a reference count at or above its
      previous value
- [ ] `handbuch-pages.spec.js` still passes over `file://`
- [ ] Every German label quoted in the new section resolves in
      `plugins/provenance/language/de_DE/plugin.lang.php` or a core `de_DE` file — checked by
      grep, not asserted by a test, because no test reads handbook prose

#### Manual Verification
- [ ] Walk it: enter the four fields on a real album, save, confirm the photo page shows
      nothing; press **Auf N Fotos anwenden**, confirm the Herkunft row appears. This is the
      finding, and it is the one step whose absence made the screen look broken
- [ ] The section describes the buttons by what they do, not by what their icons look like —
      the mistake `docs/agents/TESTING.md:605` records from the last pass
- [ ] No screenshot is added; the section is text-only, so `shoot.js` stays untouched

**Implementation Note**: This is the phase a reader most depends on. Pause for manual
confirmation before Phase 4.

---

## Phase 4: Guards for the two behaviours the handbook now describes

### Overview

Findings 2 and 7 were wrong for a year because nothing witnessed them. Both are application
facts a spec can assert. Home is `plugins/provenance/tests/e2e/core-admin-screens.spec.js`
for the core screen and the typetags E2E suite for the picture page, matching where each
already lives.

These are `[ERR]` characterization tests: the oracle is the current implementation, so they
report a change rather than proving correctness. They will pass on their first run, which is
normally the tell that a test recorded code rather than drove it — so each must be watched red
against a mutant, per *proving a check can actually fail*.

### Changes Required

#### [ ] 1. The Batch Manager label pair
**File**: `plugins/provenance/tests/e2e/core-admin-screens.spec.js`
**Changes**: Two specs in the existing "controls the handbook tells a reader to click"
describe. The install has 105 photos and the default page size is 20
(`config_default.inc.php:1019-1021`), so an unfiltered Batch Manager is already the
multi-page case — no fixture needed, but the precondition must be **asserted, not assumed**.

```js
// [ERR] a set larger than one page offers the two-label form
//   force: no filter, default page size; assert nb_thumbs_set > nb_thumbs_page holds
//   by asserting the pagination control is present, then assert both links
test('a multi-page set offers Die ganze Seite and Das ganze Set', ...)

// [ERR] display=all collapses it to the single label
test('display=all offers Alles instead', ...)
```

The second spec is what makes the pair meaningful: asserting only the multi-page form would
stay green if the `{else}` branch were deleted.

#### [ ] 2. The picture page section order
**File**: `plugins/typetags/tests/e2e/` — a spec beside the existing picture-page specs
**Changes**: Assert the DOM order that finding 7b turns on: `#Tags` inside `dl#standard`,
`#typetags-unassigned` after that `</dl>`, and — when provenance renders a row — `#Provenance`
before `#typetags-unassigned`.

```js
// [ERR] the + block sits below the whole info list, not under the Tags row
//   compareDocumentPosition, not a coordinate comparison: a y-offset assertion
//   would be a machine-speed proxy and is forbidden by test-design.md
```

Assert with `compareDocumentPosition` / `DOCUMENT_POSITION_FOLLOWING`, never pixel offsets.
Do **not** assert Herkunft vs Personen order — unpinned by design.

#### [ ] 3. Anti-vacuity for both
**Changes**: Each spec asserts its precondition first — that the set really spans pages, that
the photo really carries at least one colored tag and at least one unassigned one. A spec that
finds no badges must fail, not pass.

### Test cases

- [ ] `a multi-page set offers Die ganze Seite and Das ganze Set` `[ERR]` `[BVA]` — the
      `nb_thumbs_set > nb_thumbs_page` side of the boundary
- [ ] `display=all offers Alles instead` `[ERR]` `[BVA]` — the `else` side; together they are
      the boundary pair
- [ ] `the unassigned block follows the info list` `[ERR]` — the anchor fact behind finding 7b
- [ ] `assigned colored and colorless tags share one row` `[ERR]` — the fact behind finding 7a;
      needs a photo carrying one of each, forced by the spec's fixture
- [ ] Precondition assertions on all four `[NEG]` — a run that found nothing to look at fails

### Regression — affected existing tests
- [ ] `core-admin-screens.spec.js` existing 14 executed specs still pass — the file gains
      specs, and its `[NEG]` normal-account loop covers `batch_manager` already
- [ ] `plugins/typetags/tests/Integration/PicturePageSourceTest.php` still passes — it asserts
      `#typetags-unassigned` presence/absence in page source and is the lower layer of the same
      fact
- [ ] Full typetags E2E and provenance E2E suites pass

### Mutants

Per `.claude/rules/mutation-testing.md` this is an end-of-plan strength check over the new
specs, recorded as prose. Note the rule's scope: mutation reasoning is unit-only, and these are
E2E — so this table is the narrower *watched-red* discipline, not a mutation audit, and it is
recorded as such.

| Mutant | Expected killer | Result |
|---|---|---|
| `{if $nb_thumbs_set > $nb_thumbs_page}` → `{if false}` | the multi-page spec | to be filled |
| the `{else}` branch deleted | the `display=all` spec | to be filled |
| `TYPETAGS_TPL_INJECT_POINT` changed to the `</dl>` anchor | the section-order spec | to be filled |
| `typetags_render()` returning the plain name for colored tags | the shared-row spec | to be filled |

Wait for each mutant to reach the container before running — compare the host checksum against
`ddev exec md5sum <file>`, per the DDEV/Mutagen shift the rules file records. Clear
`_data/templates_c/` after any prefilter edit, or the compiled template hides the mutant.

### Success Criteria

#### Automated Verification
- [ ] `ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; cd plugins/provenance && npx playwright test'` passes
- [ ] `ddev exec bash -c 'set -a; . local/config/typetags-test.env; set +a; cd plugins/typetags && npx playwright test'` passes
- [ ] Every new spec watched red against its mutant in the table, each result recorded honestly
      including any survivor and why
- [ ] Suites pass twice in a row; `retries: 0` and `workers: 1` unchanged
- [ ] Album, image, tag and typetag counts identical before and after a full run

#### Manual Verification
- [ ] Each new spec asserts an application fact, not a handbook string — no spec reads
      `handbuch/`
- [ ] The specs fail for the right reason when watched red, not merely fail

**Implementation Note**: Pause for manual confirmation before Phase 5.

---

## Phase 5: Record what was decided

### Overview

Close the loop so the next reader cites the decision rather than rediscovering it.

### Changes Required

#### [ ] 1. The privacy decision
**File**: `docs/agents/decisions/0031-person-names-are-visible-to-guests-as-ordinary-tags.md` (new)
**Changes**: Next free number, confirmed 2026-09-01 (0030 is the highest). Records that the tag
mirror deliberately exposes a person's *name* to anonymous visitors while the region overlay
and the Personen row stay behind a login, that this was implemented and tested deliberately but
recorded only in a test comment, and that hiding it is rejected for now because it would mean
changing core's related-tags row rather than the plugin's own.

Must state what was rejected and why, not only what was chosen — the house shape of every
decision file here. Cite `PicturePageSourceTest.php:218-222`, decision 0019 (which names both
halves without joining them), and 0020.

#### [ ] 2. The testing record
**File**: `docs/agents/TESTING.md`
**Changes**: Add the new specs with dated counts. Add the Phase 1 `[NEG]` cases and the fact
that the method had **no** permission coverage before. Add a hand-check ledger row for the
Phase 3 walkthrough, which has no oracle but a reader. Add a *Reading the handbook against the
application* entry for this pass, matching the 2026-08-31 section, listing the eight findings
and which are now witnessed by a spec and which stay manual.

#### [ ] 3. The backlog
**File**: `docs/backlog.md`
**Changes**: Add:
- the remote's eight colored tags are recreated by hand after any wipe, with no committed
  script — decision 0023 means a wipe loses them
- `pwg.persons.rescan` is not called by the deploy and is not listed as a manual step in
  `tools/deploy/README.md`
- the Herkunft-vs-Personen row order is unpinned (`get_db_plugins()` has no `ORDER BY`)

#### [ ] 4. The research document
**File**: `docs/agents/research/2026-09-01-handbuch-vs-live-deployment-findings.md`
**Changes**: Its summary says "No functional defect was found", true of the eight findings and
false of the ninth found while resolving its own last open question. Amend the open-questions
entry for `typetags.type.add` to record what the investigation found and point at this plan.
Do not rewrite the summary's finding-by-finding verdicts, which stand.

### Success Criteria

#### Automated Verification
- [ ] `docs/agents/decisions/0031-*.md` exists and no other file claims 0031
- [ ] `CLAUDE.md` is under 100 lines; every file in `.claude/rules/` is under 500
- [ ] Every command quoted in the updated docs actually runs
- [ ] Full run of all three plugin suites, all layers, passes
- [ ] `ddev exec php handbuch/tools/check.php` exits 0

#### Manual Verification
- [ ] The decision file states what was rejected and why
- [ ] Every count added carries the date it was measured
- [ ] No instruction file now claims something untrue — in particular, nothing still implies
      `typetags.type.add` is ungated or that guests see nothing of a person

---

## Testing Strategy

### Layer placement

Phase 1 is **integration**: the defect is a web-service permission gate, which needs `ws.php`,
a session and a database. It cannot be a unit test — there is no pure function to load, and
`.claude/rules/testing.md` says a "unit" test that needs the framework booted is not one.

Phase 4 is **E2E**: both facts are about rendered layout and a conditional template branch a
browser is the only witness for. The lower half of finding 7 is already covered at integration
by `PicturePageSourceTest`, so the E2E spec adds the DOM-order fact and does not restate it.

No new **unit** tests: nothing in this plan adds a pure function. The name-length constant is
a definition, not logic.

### Unit tests

None added, with the reason above. `GermanOverrideKeyTest` (45 tests / 204 assertions,
measured 2026-09-01) is unaffected — no translated literal moves in this plan.

### Integration tests

Phase 1's seven cases, enumerated above. All `[NEG]` but two, and the two `[HAPPY]` ones exist
to stop the `[NEG]` ones passing vacuously.

### End-to-end tests

Phase 4's four specs plus their precondition assertions, enumerated above.

### Regression

- [ ] All three plugin unit suites
- [ ] All three plugin integration suites — `ColorHelperCallersTest` is the one that would
      notice a too-strict Phase 1 fix
- [ ] All three plugin E2E suites — `admin-tags.spec.js` drives the colour panel that calls the
      gated method
- [ ] `ddev exec php handbuch/tools/check.php`
- [ ] `bash tools/test-hooks.sh` — the commit gate's self-test, since Phase 1 adds a test file
- [ ] Album, image, tag and typetag counts unchanged after a full run (5 / 105 / 8 / 8,
      measured 2026-09-01)

### Manual testing steps

1. Create a colour on the admin tags screen as the webmaster; confirm it still works.
2. Re-run the non-destructive reachability probe against the sandbox after redeploy; confirm
   `401` rather than `1003`.
3. Walk the album provenance sequence: fill, save, confirm nothing on the photo page, apply,
   confirm the Herkunft row.
4. Read the eight corrected statements against the screens they describe.

### Test commands

```bash
# Unit
ddev exec plugins/typetags/vendor/bin/phpunit  --testsuite unit --configuration plugins/typetags/phpunit.xml
ddev exec plugins/provenance/vendor/bin/phpunit --testsuite unit --configuration plugins/provenance/phpunit.xml
ddev exec plugins/persons/vendor/bin/phpunit   --testsuite unit --configuration plugins/persons/phpunit.xml

# Integration
ddev exec bash -c 'set -a; . local/config/typetags-test.env; set +a; \
  plugins/typetags/vendor/bin/phpunit --testsuite integration --configuration plugins/typetags/phpunit.xml'
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; \
  plugins/provenance/vendor/bin/phpunit --testsuite integration --configuration plugins/provenance/phpunit.xml'
ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; \
  plugins/persons/vendor/bin/phpunit --testsuite integration --configuration plugins/persons/phpunit.xml'

# E2E
ddev exec bash -c 'set -a; . local/config/typetags-test.env;  set +a; cd plugins/typetags  && npx playwright test'
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; cd plugins/provenance && npx playwright test'
ddev exec bash -c 'set -a; . local/config/persons-test.env;   set +a; cd plugins/persons   && npx playwright test'

# Handbook and gate
ddev exec php handbuch/tools/check.php
bash tools/test-hooks.sh
ddev exec php -l <file>
```

## Performance Considerations

None. Phase 1 adds one `mb_strlen` call and one options-array lookup per call to a method the
UI invokes on an explicit click. Phases 2 and 3 are static HTML. Phase 4 adds four E2E specs,
which is the slowest layer but a handful of seconds against suites already measured in tens.

## Migration Notes

The Phase 1 fix takes effect on the remote only after a redeploy, which is a manual step. Until
then the sandbox stays reachable — the reason Phase 1 is first.

No schema change, no data migration. The eight colored tags recreated on the remote on
2026-09-01 are unaffected by the gate, which restricts *creating* colours, not reading or
rendering them.

## References

- [The eight live-deployment findings, classified](../research/2026-09-01-handbuch-vs-live-deployment-findings.md) — the research this plan implements
- [decision 0005](../decisions/0005-tag-assignment-permission-model.md) — why `image.addTag`/`removeTag` deliberately carry no `admin_only`, and why that does not extend to `type.add`
- [decision 0019](../decisions/0019-person-region-permission-model.md) — the persons gate
- [decision 0020](../decisions/0020-persons-index-is-derived-the-file-is-the-source-of-truth.md) — regions live in the file
- [decision 0023](../decisions/0023-no-database-transfer-to-the-remote.md) — why the remote has no tags
- [decision 0025](../decisions/0025-handbuch-moves-into-the-application-tree.md) — the handbook ships to the remote
- `docs/agents/plans/2026-08-31-german-end-user-documentation.md:996-1002` — the open verification items this walkthrough closed
- `docs/agents/TESTING.md:219-221` — the three deliberate non-coverage rows about the handbook
- `plugins/typetags/tests/Integration/PluginActivationTest.php:148-165` — the `[NEG]` shape Phase 1 copies
- `include/ws_core.inc.php:510-523` — the `admin_only` enforcement point
