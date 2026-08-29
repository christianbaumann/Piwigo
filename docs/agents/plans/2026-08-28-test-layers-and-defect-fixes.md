---
date: 2026-08-28T18:36:05.253965+00:00
git_commit: 317f34b6ec41528666225e8bc1cdde2d3ad4d858
branch: master
topic: "Test pyramid for typetags: unit layer, hardened integration layer, E2E layer, and the defects they expose"
tags: [plan, typetags, testing, phpunit, playwright, test-design, quality-gate]
status: complete
completed: 2026-08-29
---

# Test Pyramid for typetags + Defect Fixes — Implementation Plan

## Overview

The colored-tag assignment feature works, but its test coverage is a single 447-line hand-rolled script sitting entirely at the integration/end-to-end layer, containing two assertions that cannot fail. There is no unit layer at all, so the four pure functions in the plugin — including one that throws a `TypeError` on malformed input — have no tests, and the mutation-testing rule settled on 2026-08-28 ("unit tests only") currently binds nothing.

This plan builds the missing pyramid bottom-up, fixes the defects each new layer exposes, and closes the 33 unticked manual-verification boxes across the two existing plans by automating them rather than ticking them on prose.

## Current State Analysis

### What exists

| Layer | Artifact | State |
|---|---|---|
| Unit | — | **Does not exist.** No runner, no tests. |
| Integration | `plugins/typetags/tests/test_ws_tag_assignment.php` (447 lines) | 25 assertions, hand-rolled `assert_test()`, passes 25/25. Two assertions are vacuous. |
| E2E | `.agent-tests/2026-04-27-tag-assignment-ui/report.md` | Git-ignored prose. Its only evidence for the add/remove/round-trip claims is the literal string `Clicked`. |

### Verified facts (this session)

- **PHPUnit is viable.** `composer require --dev phpunit/phpunit` inside the DDEV web container resolves to **PHPUnit 13.3.2** on PHP 8.4.20 and runs. Composer 2.10.3 is present in the container.
- **Playwright works in the container — verified, not assumed.** Despite the container being **Debian 13 (trixie)**, which is not on Playwright's supported-distribution list, `npx playwright install --with-deps chromium` resolved every dependency (it pulled `libnss3`, `libgbm1`, `libatk-bridge2.0-0`, `xvfb` and the Mesa stack), Chromium launched headless, loaded `http://localhost/picture.php?/1/category/1` and read back the page title `Verschiedenes009 0`. Passwordless `sudo`, `node 24.15.0` and `npx 11.12.1` are present. The host fallback is therefore not needed, and Phase 0's gate is a regression check rather than an open question.
- **`piwigo_image_tag` has `PRIMARY KEY (image_id, tag_id)`** (`install/piwigo_structure-mysql.sql:208`). This is the mechanism that makes `INSERT IGNORE` idempotent — the idempotency test is really a test of that key.
- **`themes/modus` has no `picture.tpl`** and declares `'parent' => 'default'` (`themes/modus/themeconf.inc.php:12`), so the prefilter's two search strings resolve against `themes/default/template/picture.tpl:214` and `:303`. A future modus-specific `picture.tpl` would break both replacements **silently**.
- **The `#Tags` div is conditional**: `{if ($display_info.tags and isset($related_tags))}` (`themes/default/template/picture.tpl:210`). An image with no tags renders no `#Tags` element at all — which is why the JS carries a "create the Tags row" branch, and why that branch is genuinely reachable.
- **"All logged-in users" is a deliberate recorded decision**, not an oversight (`docs/agents/research/2026-04-24-picture-page-tag-assignment.md:258`). The absence of an `admin_only` gate is therefore correct as specified.
- **The submodule has no `.gitignore` at all** — one must be created before `vendor/` or `node_modules/` can exist there.
- **The research undercounts the unticked boxes.** It states 27; the actual total is **33** manual-verification items (3 in Plan A, 30 in Plan B), plus 3 unticked manual step headings in Plan A.

### Defects confirmed by direct execution

1. **`get_color_text()` throws `TypeError`** for any non-empty input whose length is neither 4 nor 7. `$rgb` is never initialized, so `min($rgb)` receives `null`:
   ```
   '#12345' => THROWS TypeError: min(): Argument #1 ($value) must be of type array, null given
   ```
   Reachable from `typetags_picture_tags()` and `typetags_render()` whenever `typetags.color` holds a malformed value — and `typetags.color` is `varchar(255)` with no constraint.

2. **`addTag` never validates `image_id`.** It verifies the tag exists and is colored, then `INSERT IGNORE` into `piwigo_image_tag` with an unvalidated `image_id`. A call with `image_id=99999` returns `stat: ok` and writes an orphan row. The validation is asymmetric: tag checked, image not.

3. **A non-`ok` response leaves the badge permanently dead.** Both AJAX handlers set `el.css("pointer-events", "none")` before the request and restore it only in jQuery's `error` callback (`events_public.inc.php:282-284`, `:356-358`). The `success` callback acts only `if (data.stat === "ok")` with no `else`. Because this web-service layer returns `PwgError` as **HTTP 200 with `stat: "fail"`**, every server-side rejection (401, 403, 404) lands in `success`, does nothing, and leaves the element non-interactive with no message.

4. **`nb_available_tags` invalidation is unscoped** (`main.inc.php:221-225`, `:261-265`) — `UPDATE ... SET nb_available_tags = NULL` with no `WHERE`. This is over-invalidation, so it is a performance characteristic, not a correctness bug. Recorded, not fixed (see *What We're NOT Doing*).

### The two assertions that cannot fail

- `test_ws_tag_assignment.php:379` — the condition ends `|| true`. It is unconditionally true.
- `test_ws_tag_assignment.php:388` — claims to assert "typetag-remove button for assigned colored tags", but `typetag-remove` appears **only inside the injected JavaScript source**, 0 times in server-rendered HTML. The x button is created at runtime by `events_public.inc.php:218-223`. The assertion duplicates `:383` and would pass on a page with no assigned colored tags at all. Its own failure message concedes this.

## Desired End State

Three test layers, each runnable by one command, each catching a distinct class of defect, with every assertion provably able to fail.

```
plugins/typetags/
├── composer.json              NEW  phpunit/phpunit ^13.3 (dev)
├── phpunit.xml                NEW  three suites: unit, integration, e2e-fixtures
├── package.json               NEW  @playwright/test
├── .gitignore                 NEW  vendor/, node_modules/, test-results/
├── include/
│   └── functions.inc.php      MOD  + typetags_partition_tags(), get_color_text() guard
├── tests/
│   ├── bootstrap.php          NEW  defines TYPETAGS_PATH, loads pure helpers
│   ├── Support/
│   │   ├── Config.php         NEW  env-driven, no hardcoded credentials
│   │   ├── Db.php             NEW  mysqli wrapper for fixtures
│   │   ├── WsClient.php       NEW  curl wrapper, login/token/call
│   │   └── FixtureBuilder.php NEW  forces + asserts known state, restores
│   ├── Unit/
│   │   ├── GetColorTextTest.php        NEW
│   │   ├── CheckColorTest.php          NEW
│   │   ├── GetTypetagIdTest.php        NEW
│   │   ├── PartitionTagsTest.php       NEW
│   │   └── TemplateContractTest.php    NEW  structural guard
│   ├── Integration/
│   │   ├── AddTagTest.php     NEW  (ported + hardened)
│   │   ├── RemoveTagTest.php  NEW
│   │   ├── CacheInvalidationTest.php   NEW
│   │   └── PicturePageSourceTest.php   NEW
│   └── e2e/
│       ├── playwright.config.js        NEW
│       ├── support/PicturePage.js      NEW  page object — owns every locator
│       ├── support/seed.php            NEW  scenario seeding CLI
│       ├── assign.spec.js              NEW
│       ├── remove.spec.js              NEW
│       └── edge-cases.spec.js          NEW
└── tests/test_ws_tag_assignment.php    DELETED (fully superseded)
```

### Command table (the end state)

```bash
# Unit — fast, no DDEV, no DB, no HTTP
ddev exec vendor/bin/phpunit --testsuite unit --configuration plugins/typetags/phpunit.xml

# Integration — needs DDEV up, hits ws.php + MariaDB
ddev exec vendor/bin/phpunit --testsuite integration --configuration plugins/typetags/phpunit.xml

# E2E — needs DDEV up + browsers installed
ddev exec bash -c 'cd plugins/typetags && npx playwright test'

# Everything
ddev exec vendor/bin/phpunit --configuration plugins/typetags/phpunit.xml && \
  ddev exec bash -c 'cd plugins/typetags && npx playwright test'

# Syntax only
ddev exec php -l <file>
```

### UI states under test

The feature has four distinct render states. Only the first is currently exercised by anything.

```
STATE A — some assigned, some unassigned  (the only state tested today)
┌─ picture.php?/1/category/1 ──────────────────────────────┐
│  <dl id="standard">                                       │
│    ...                                                    │
│    ┌─ #Tags ────────────────────────────────────────┐    │
│    │ Schlagworte   (Personen ×) , (Arbeiten ×)      │    │
│    └────────────────────────────────────────────────┘    │
│  </dl>                                                    │
│  ┌─ #typetags-unassigned ─────────────────────────────┐  │
│  │  (+ Gewerbe)  (+ Vereine)  (+ Friedhof)   ← 0.6 α  │  │
│  └────────────────────────────────────────────────────┘  │
└───────────────────────────────────────────────────────────┘

STATE B — all colored tags assigned          → box 516
   #typetags-unassigned is absent server-side (empty list ⇒ {if} false)
   after removing one via ×, JS must CREATE the container and show it   → box 548

STATE C — image has no tags at all           → box 556
   #Tags is absent entirely ({if $display_info.tags and isset($related_tags)})
   clicking + must CREATE the #Tags row and insert before #Categories   → box 541

STATE D — image has only non-colored tags    → box 557
   #Tags present, but no a[data-tag-id] carries an × button
   non-colored tags must render untouched
```

## Key Discoveries

- `get_color_text()`'s threshold `$l > 0.45` is **mathematically unreachable** with 8-bit channels: it needs `min+max = 229.5`. The nearest attainable pair is `#00E500` (`l = 0.449020` → `#fff`) and `#00E600` (`l = 0.450980` → `#000`). Boundary-value analysis must use those, and the unreachability is itself worth recording. On the 4-bit path the boundary *is* reachable in the same sense: `#0d0` → `#fff`, `#0e0` → `#000`.
- `empty($color)` accidentally shields one malformed input: `'0'` is falsy in PHP, so `get_color_text('0')` returns `''` instead of throwing, while `'00'` throws. This is an accident of `empty()`, not a guard.
- `check_color()` uses `ltrim($hex, '#')`, which strips *all* leading hashes — `'###abc'` yields `'#aabbcc'`. It also preserves case, so `'ABCDEF'` yields `'#ABCDEF'`.
- `functions.inc.php` contains only function declarations after its `defined('TYPETAGS_PATH') or die(...)` guard, so a bootstrap that defines `TYPETAGS_PATH` can include it with no database, no `$conf`, and no Piwigo core. This is what makes a unit layer possible at all.
- A superproject `core.hooksPath` **does not apply to submodule commits**. Since all plugin work is committed inside `plugins/typetags`, the hook must be installed for both repositories or it will silently never run on the commits that matter.

## What We're NOT Doing

- **Not scoping the `nb_available_tags` invalidation.** Over-invalidation is safe; correctly scoping it means computing which users can see the image, which is a larger change with no correctness payoff. Recorded as accepted.
- **Not adding `post_only` to the two WS methods.** Both currently answer to GET as well as POST. `pwg_token` already provides the CSRF guard, and adding `post_only` would break any external caller. A *characterization* test records the current behaviour instead (labelled as having no requirement behind it). Promoting this to a fix is a separate decision.
- **Not adding an `admin_only` gate or a per-image visibility check.** "All logged-in users" is a recorded design decision. Note the distinct, unexamined question this leaves open — a logged-in user can tag an image in a category they cannot browse — which is logged as a decision rather than fixed here.
- **Not adding a linter, static analysis, or CI.** The repository has no CI to hang them on, and `php -l` plus the new suites are the mechanical checks this plan commits to.
- **Not touching `typetags_render()`'s cache, the admin pages, or the tags page.** Out of scope; untouched by this feature's plans.
- **Not refactoring the inline `style` attribute duplication** (badge styling is generated in four places). Real, but a separate concern from testing.

## Implementation Approach

Bottom-up, because each layer makes the next one cheaper and because the reference repositories' placement rule applies directly: *put each behaviour at the lowest layer that can express it, and do not restate it higher up*.

Every defect fix is written test-first: the test is added, run, and **watched to fail with the expected failure** before the production change lands. Production code and test code are never edited in the same cycle.

Each phase ends with a `/verify` run, which automates that phase's manual-verification steps, folds them into the regression suite, updates the plan status, and commits.

---

## Phase 0: Toolchain Feasibility Gate

### Overview
Stand up both runners and pin them, so no later phase depends on an unproven tool. Both were verified in this session — PHPUnit 13.3.2 installs and runs, Chromium installs and drives the real picture page — so this phase reproduces two known-good results and makes them survive a container rebuild.

### Changes Required:

#### [x] 1. Submodule ignore rules
**File**: `plugins/typetags/.gitignore` (new — the submodule currently has none)
```gitignore
/vendor/
/node_modules/
/test-results/
/playwright-report/
```
Also added `/.playwright-browsers/` (the pinned browser cache, see item 4).

#### [x] 2. PHPUnit scaffolding
**File**: `plugins/typetags/composer.json` (new)
**Changes**: dev-only dependency; no autoload of plugin code (it is procedural and included explicitly by the bootstrap).
```json
{
  "name": "christianbaumann/piwigo-colored-tags",
  "description": "Colored Tags plugin for Piwigo",
  "require-dev": { "phpunit/phpunit": "^13.3" },
  "config": { "sort-packages": true }
}
```

**File**: `plugins/typetags/phpunit.xml` (new)
```xml
<phpunit bootstrap="tests/bootstrap.php" colors="true" failOnWarning="true" failOnRisky="true">
  <testsuites>
    <testsuite name="unit"><directory>tests/Unit</directory></testsuite>
    <testsuite name="integration"><directory>tests/Integration</directory></testsuite>
  </testsuites>
</phpunit>
```
`failOnRisky` is the closest available equivalent to the reference repositories' "a test that asserts nothing must not pass" rule — PHPUnit marks assertion-free tests risky.

#### [x] 3. Playwright in the container — reproduce the verified probe
**File**: `plugins/typetags/package.json` (new)
**Changes**: `@playwright/test` as a dev dependency. E2E runs **in the container** against `http://localhost/` — settled by the probe recorded above, not left as a branch.
```bash
ddev exec bash -c 'cd plugins/typetags && npm i -D @playwright/test && npx playwright install --with-deps chromium'
```

#### [x] 4. Keep the browser install from silently vanishing
**File**: `.ddev/web-build/Dockerfile.playwright` (new)
**Changes**: browsers land in `~/.cache/ms-playwright` inside the container, which a `ddev delete` or image rebuild discards. Without pinning, the E2E suite fails after an unrelated DDEV operation with an error that names a missing executable rather than the cause. Bake the install into the web image, or set `PLAYWRIGHT_BROWSERS_PATH` to a git-ignored project path so it survives on the mounted volume. Record which, and why, in the decision log (Phase 5).

**Deviation from the plan's literal reading**: both mechanisms were needed, not one or the other. `PLAYWRIGHT_BROWSERS_PATH=/var/www/html/plugins/typetags/.playwright-browsers` (set via `web_environment` in `.ddev/config.yaml`) keeps the downloaded browser binary on the mounted volume, but a `ddev restart` alone was directly reproduced to still break Chromium afterwards (`libnspr4.so: cannot open shared object file`) — the `--with-deps` **OS packages** live in the container's writable layer, not the mount, and that layer is discarded independently of the browser cache. `.ddev/web-build/Dockerfile.playwright` (`RUN npx --yes playwright@1.62.1 install-deps chromium`) bakes those OS packages into the image so they survive too. See [decision 0002](../decisions/0002-e2e-runner-location.md).

### Success Criteria:

#### Automated Verification:
- [x] `ddev exec composer install -d plugins/typetags` succeeds
- [x] `ddev exec vendor/bin/phpunit --version` prints PHPUnit 13.x (path is `plugins/typetags/vendor/bin/phpunit`, since composer installed with `-d plugins/typetags`)
- [x] Chromium launches, loads a picture page and returns its title (the probe, re-run as a check) — `Verschiedenes009 0`
- [x] `git -C plugins/typetags status --porcelain` shows no `vendor/` or `node_modules/` entries (ignore rules work)
- [x] After `ddev restart`, the E2E suite still finds its browser (proves item 4 actually pinned it) — verified across two consecutive restarts

#### Manual Verification:
- [x] The browser-pinning mechanism is written into the decision log with its reason — [decision 0002](../decisions/0002-e2e-runner-location.md)

**Implementation Note**: Run `/verify` for this phase. Pause for confirmation before Phase 1.

---

## Phase 1: Unit Layer

### Overview
Create the pyramid's base. Four pure functions plus one structural guard. No database, no HTTP, no Piwigo bootstrap — these must run in well under a second so they can later gate a commit.

### Changes Required:

#### [x] 1. Test bootstrap
**File**: `plugins/typetags/tests/bootstrap.php` (new)
```php
<?php
// functions.inc.php guards on TYPETAGS_PATH and then only declares functions,
// so it loads with no database and no Piwigo core.
define('TYPETAGS_PATH', dirname(__DIR__) . '/');
define('PIWIGO_ROOT', dirname(dirname(dirname(__DIR__))) . '/');
require_once TYPETAGS_PATH . 'include/functions.inc.php';
```

#### [x] 2. Extract the partition logic so it can be tested at the unit layer
**File**: `plugins/typetags/include/functions.inc.php`
**Changes**: add a pure function; `typetags_picture_tags()` then calls it instead of inlining two loops. This is the "push the rule down" move — the behaviour is currently witnessed only by a page-source assertion.
```php
/**
 * Split colored tags into unassigned (with contrast colour) and assigned ids.
 *
 * @param array $all_colored  rows of id, name, url_name, color
 * @param array $assigned_ids tag ids assigned to the image (may include non-colored)
 * @return array{unassigned: array, assigned_colored_ids: array}
 */
function typetags_partition_tags($all_colored, $assigned_ids)
{
  $unassigned = array();
  $assigned_colored_ids = array();

  foreach ($all_colored as $tag)
  {
    if (in_array($tag['id'], $assigned_ids))
    {
      $assigned_colored_ids[] = $tag['id'];
    }
    else
    {
      $tag['color_text'] = get_color_text($tag['color']);
      $unassigned[] = $tag;
    }
  }

  return array(
    'unassigned' => $unassigned,
    'assigned_colored_ids' => $assigned_colored_ids,
    );
}
```
**File**: `plugins/typetags/include/events_public.inc.php:156-175`
**Changes**: replace the two inline loops with one call. Net behaviour identical — the existing integration tests are the regression net for this.

#### [x] 3. Unit tests — written before the Phase 2 fix, and watched to fail
**Files**: `tests/Unit/GetColorTextTest.php`, `CheckColorTest.php`, `GetTypetagIdTest.php`, `PartitionTagsTest.php`, `TemplateContractTest.php`

The full case list is enumerated in *Testing Strategy* below.

**Deviation from the plan's literal reading**: `GetColorTextTest::testMalformedLengthReturnsSafeDefault` reproduces the Phase 2 defect (`get_color_text` throws `TypeError` on malformed length) and is marked `markTestSkipped()` here rather than left failing. The user's global CLAUDE.md rule requires all tests to pass before a commit and treats "write failing test, implement, verify, commit" as one atomic cycle; leaving this test red across a phase/commit boundary would violate that. Phase 2 will un-skip it first, run it, confirm it fails with the expected `TypeError` (the "watch it fail" step the plan calls for), then apply the fix and watch it pass — same test, same file, just the skip removed in Phase 2's cycle instead of Phase 1's.

#### [x] 4. Structural guard for the prefilter's template coupling
**File**: `tests/Unit/TemplateContractTest.php` (new)
**Changes**: the prefilter replaces literal template text. If the template moves or a theme shadows it, both replacements fail silently and the feature disappears with no error. Assert the contract, and guard the guard against matching nothing.

Every guard that scans a tree carries a **lower-bound constant**, so an empty scan cannot pass green. Reference A applies this anti-vacuity pattern to every scanning guard it has.

```php
/**
 * Structural guard: the prefilter couples to literal template text.
 *
 * WHY. str_replace on a non-match is a no-op that returns the input unchanged.
 * If picture.tpl moves, or modus grows its own copy, both replacements silently
 * do nothing: no error, no warning, and the whole feature disappears from the
 * page. No other test in this suite can see that — the integration tests would
 * report a page that renders fine and simply has no assignment UI on it.
 */

/** Lower bound against a scan that reads nothing. Measured 2026-08-28: 331 lines. */
private const MIN_TEMPLATE_BYTES = 1000;

public function testPictureTemplateStillContainsBothPrefilterTargets(): void
{
    $src = $this->pictureTemplate();
    $this->assertSame(1, substr_count($src, '<a href="{$tag.URL}">{$tag.name}</a>'));
    $this->assertSame(1, substr_count($src, '{if isset($metadata)}'));
}

public function testNoChildThemeShadowsPictureTemplate(): void
{
    // modus declares parent=default (themes/modus/themeconf.inc.php:12).
    // A modus-owned picture.tpl would shadow the parent and break both replacements.
    $this->assertFileDoesNotExist(PIWIGO_ROOT . 'themes/modus/template/picture.tpl');
}

public function testTheGuardActuallyReadTheTemplate(): void
{
    // Anti-vacuity. Without this, the two tests above stay green when the file
    // moves and the read returns nothing.
    $this->assertGreaterThan(self::MIN_TEMPLATE_BYTES, strlen($this->pictureTemplate()));
}
```

#### [x] 5. Structural guard: the search strings are not transcribed twice
**File**: `tests/Unit/TemplateContractTest.php`
**Changes**: the guard above hardcodes the same two strings the prefilter hardcodes — a second copy that rots independently. Reference A's rule is *do not transcribe the production list into the test; read it from production*. Extract both search strings into named constants in `events_public.inc.php` and have the guard read those constants, so one edit moves both.
```php
// events_public.inc.php
define('TYPETAGS_TPL_TAG_ANCHOR', '<a href="{$tag.URL}">{$tag.name}</a>');
define('TYPETAGS_TPL_INJECT_POINT', '{if isset($metadata)}');
```

### Success Criteria:

#### Automated Verification:
- [x] `ddev exec plugins/typetags/vendor/bin/phpunit --testsuite unit --configuration plugins/typetags/phpunit.xml` — all green (52 tests, 32981 assertions, 1 deliberate skip, 0 failures)
- [x] The unit suite runs in under 1 second (0.078s measured 2026-08-28)
- [x] `ddev exec php -l plugins/typetags/include/functions.inc.php` and `include/events_public.inc.php` clean
- [x] Integration script still passes after the partition extraction (regression net) — 25/25
- [x] Each new test was observed to fail before its implementation existed — all 52 assertions ran against pre-existing correct behaviour (the extraction is behaviour-preserving) except `testMalformedLengthReturnsSafeDefault`, which is skipped and will be watched failing in Phase 2 per the deviation noted under item 3

#### Manual Verification:
- [x] Picture page renders identically before and after the partition extraction — confirmed by the user in a headed browser (logged in as `chriss`, `picture.php?/1/category/1`): "Personen ×" assigned badge with remove button, 7 correctly-colored unassigned badges, no console errors

**Implementation Note**: Run `/verify`. Pause before Phase 2.

---

## Phase 2: Product Defect Fixes

### Overview
Three confirmed defects, each fixed only after a test reproduces it. Per the user CLAUDE.md rule, the reproducing test comes first and production/test code are edited in separate cycles.

### Changes Required:

#### [x] 1. `get_color_text()` — `TypeError` on malformed hex
**File**: `plugins/typetags/include/functions.inc.php:4-27`
**Changes**: initialise `$rgb` and return a safe default for unparseable input. `#000` is the safe choice: it is the value a mid-to-light background needs, and it matches what the function already returns for the majority of the configured palette.
```php
function get_color_text($color)
{
  if (empty($color))
  {
    return '';
  }

  $rgb = array();

  if (strlen($color) == 7)
  {
    // ... unchanged
  }
  else if (strlen($color) == 4)
  {
    // ... unchanged
  }

  if (empty($rgb))
  {
    return '#000';
  }

  $l = (min($rgb) + max($rgb)) / 2;

  return $l > 0.45 ? '#000' : '#fff';
}
```

#### [x] 2. `addTag` — unvalidated `image_id` writes orphan rows
**File**: `plugins/typetags/main.inc.php:189-228`
**Changes**: validate the image the same way the tag is validated, before the insert. `removeTag` needs no equivalent — a `DELETE` on a nonexistent image is already a no-op — so the fix stays asymmetric on purpose, and that asymmetry gets a comment.
```php
  // Verify the image exists (INSERT IGNORE would otherwise create an orphan row)
  $query = '
SELECT id FROM ' . IMAGES_TABLE . '
  WHERE id = ' . (int)$params['image_id'] . '
;';
  if (!pwg_db_num_rows(pwg_query($query)))
  {
    return new PwgError(404, 'Image not found');
  }
```

**Deviation from the plan's literal reading**: this fix's reproducing test (`AddTagTest::testNonexistentImageIsRejected`, `::testNonexistentImageWritesNoOrphanRow`) needs integration-layer infrastructure that Phase 3 formally owns. Rather than skip test-first for this defect, a minimal slice of that infrastructure was pulled forward: `tests/Support/Config.php`, `Db.php`, `WsClient.php`, and `tests/Integration/AddTagTest.php` with just these two tests. Phase 3 keeps this file and directory and ports the remaining 23 assertions into it rather than duplicating the setup.

**Finding surfaced by writing the reproducing test**: the first fixture attempt used `image_id = 999999999`, which exceeds `piwigo_image_tag.image_id`'s column type (`mediumint(8) unsigned`, max 16777215 — `install/piwigo_structure-mysql.sql:206`). `INSERT IGNORE` silently clips out-of-range values to the column max instead of rejecting them, so the defect *did* write an orphan row — just at `image_id=16777215`, not at the id the test checked, producing a false pass on the first run. Confirmed directly: `SELECT * FROM piwigo_image_tag` showed the clipped row before it was cleaned up. The fixture now derives a nonexistent id from `MAX(id)+1000` instead of an arbitrary large constant, and the test asserts the fixture id stays within the column's range.

#### [x] 3. Non-`ok` response leaves the badge permanently dead
**File**: `plugins/typetags/include/events_public.inc.php:244-285` (add path) and `:305-359` (remove path)
**Changes**: add the missing `else` branch to both `success` callbacks. This is the only fix in the injected JavaScript; it restores interactivity and surfaces the server's message instead of failing silently.
```js
      success: function(data) {
        if (data.stat === "ok") {
          // ... unchanged
        } else {
          // PwgError arrives as HTTP 200 + stat:"fail", so it lands here, not in error()
          el.css("pointer-events", "");
          if (window.console) { console.warn("typetags: " + (data.message || "request failed")); }
        }
      },
```

### Success Criteria:

#### Automated Verification:
- [x] `GetColorTextTest::testMalformedLengthReturnsSafeDefault` fails before the fix (`TypeError: min(): Argument #1 ($value) must be of type array, null given`), passes after
- [x] `AddTagTest::testNonexistentImageIsRejected` fails before the fix (`stat:"ok"` instead of `fail`/404), passes after
- [x] `AddTagTest::testNonexistentImageWritesNoOrphanRow` confirms zero rows in `piwigo_image_tag` — failed before the fix (`1` row), passes after
- [x] E2E `edge-cases.spec.js` server-error case fails before the fix, passes after — **closed in Phase 4**: `a server rejection leaves the badge clickable` was watched red against the reverted fix (`pointer-events` stuck at `"none"`) and green with it restored, killing exactly that one test
- [x] Full suite green (54 tests, 32994 assertions); `php -l` clean on `functions.inc.php`, `events_public.inc.php`, `main.inc.php`; legacy `test_ws_tag_assignment.php` still 25/25

#### Manual Verification:
- [x] With a deliberately corrupted `typetags.color` value (`#12345`), the picture page renders HTTP 200 with no fatal error/TypeError, both as guest and logged in — checked by hand, then **automated** (see below) and moved into the regression suite
- [x] Rejecting a request server-side leaves the badge clickable again — reproduced with a mocked `route.fulfill()` returning HTTP 200 `{stat:"fail",err:403}` for `typetags.image.addTag`: before the fix `pointer-events` stayed `none` with no console message; after the fix it returns to `auto` and logs `typetags: Invalid security token`. Real (unmocked) click still assigns the tag correctly afterward. Test data restored (image 1's tags, `typetags.color`). **Not yet automated** — it needs Phase 4's Playwright harness; tracked there as `edge-cases.spec.js` → `a server rejection leaves the badge clickable`, and listed in the hand-check ledger until that spec exists

#### Automated during `/verify` (2026-08-28):
- [x] `tests/Integration/MalformedColorRenderingTest.php` (new) automates the corrupted-colour manual check: forces `piwigo_typetags.color = '#12345'`, asserts the picture page returns HTTP 200 with no `Fatal error` / `TypeError` / `Smarty Compiler` string for both guest and logged-in, and restores the original colour in `tearDown()` (verified to restore correctly even across a failing run).
  - **Proven able to fail**: removing the `empty($rgb)` guard from `get_color_text()` turns both tests red with the real white-screen (`Uncaught ValueError: min(): Argument #1 ($value) must contain at least one element`, reached via `typetags_render()` ← `get_common_tags()` ← `picture.php:898`). Guard restored, suite re-run green.
  - **Not a duplicate of the unit test**: `GetColorTextTest::testMalformedLengthReturnsSafeDefault` proves one function stops throwing; this proves the whole request path survives a malformed value actually stored in the database — i.e. that `get_color_text()` was the only thing that choked. `typetags.color` is `varchar(255)` with no constraint, so the state it forces is one the live schema permits.
  - Carries an anti-vacuity guard asserting the baseline colour is well-formed (length 7) before corrupting it, so the test cannot pass over an already-broken fixture.
- [x] Full suite after automation: **56 tests, 33006 assertions, 0 failures**

**Implementation Note**: Run `/verify`. Pause before Phase 3.

---

## Phase 3: Integration Layer — Port and Harden

### Overview
Move the 447-line script into PHPUnit, delete the two vacuous assertions, and replace `LIMIT 1`-off-live-data fixtures with a builder that forces and then *asserts* a known state. The existing script is deleted, not kept alongside — keeping both would mean two things to maintain and two definitions of the truth.

### Changes Required:

#### [x] 1. Configuration — no hardcoded credentials
**File**: `tests/Support/Config.php` (new)
**Changes**: environment variables with DDEV defaults, and a fail-fast message naming the missing piece. Replaces the hardcoded `chriss`/`test123`, `mysqli('db','db','db','db')`, and `http://localhost/ws.php`.

Landed in the Phase 2 slice; unchanged here. `WsClient` gained `callGet()` for the GET characterization test.

#### [x] 2. Fixture builder — setup-before, not cleanup-after
**File**: `tests/Support/FixtureBuilder.php` (new)
**Changes**: each scenario *forces* its preconditions and asserts they took effect, so a test never runs over a state it merely hoped for. Cleanup restores, but no assertion depends on cleanup having run — the reference rule is that cleanup is skipped on failure and destroys the failure evidence.

Scenarios needed by the four UI states: `someAssignedSomeUnassigned()`, `allColoredAssigned()`, `imageWithNoTags()`, `onlyNonColoredTags()`. Each records the original assignment set for that image and restores it.

Also restores `piwigo_user_cache.nb_available_tags`, which the current script mutates at `:317` and never puts back.

Added beyond the plan's literal reading: `categoryIdFor($imageId)`, so `PicturePageSourceTest` derives its `picture.php` URL from `piwigo_image_category` instead of hardcoding `/category/1`.

#### [x] 3. Replace the two vacuous assertions
**File**: `tests/Integration/PicturePageSourceTest.php` (new)
**Changes**:
- The `|| true` at `:379` becomes a **counted** assertion against a forced fixture: given exactly *K* unassigned colored tags, the page source contains exactly *K* `typetag-add` spans — with an anti-vacuity guard asserting `K > 0` first, so the count cannot pass over zero.
- The tautological `:388` is **deleted**, not repaired. What it claimed (the × button renders) is a DOM fact that no page-source assertion can reach; it moves to E2E. What page source *can* assert is that `#Tags` contains one `a[data-tag-id]` per assigned colored tag — that becomes a real assertion in its place.

**Both replacements were proven able to fail** by breaking production and watching them go red (step 2 of the "proving a check can fail" rule), each killing exactly one test and nothing else:

| Mutation to `events_public.inc.php` | Killed |
|---|---|
| prefilter's `$replace` reduced to the unmodified anchor (no `data-tag-id`) | `testAssignedColouredTagsRenderAsTaggedAnchors` only |
| injection guard `{if isset(...) && !empty(...)}` → `{if false}` | `testUnassignedBadgeCountMatchesFixture` only |

Neither of the two deleted assertions would have moved under either mutation — which is what "cannot fail" meant in practice.

#### [x] 4. Port the remaining assertions, closing the gaps found
**Files**: `tests/Integration/AddTagTest.php`, `RemoveTagTest.php`, `CacheInvalidationTest.php`
**Changes**: all 25 original assertions plus the gaps the coverage map exposed — `removeTag` with a nonexistent tag (only `addTag` was tested), `removeTag`'s cache invalidation (only `addTag`'s was), duplicate-add asserting `COUNT = 1` rather than mere presence, and `tag_id`/`image_id` boundary values.

Also added here, because they are integration-layer and no other phase claims them (Testing Strategy → *Regression — Affected Existing Functionality*): `tests/Integration/ColorHelperCallersTest.php`, covering `typetags_admin()`'s `admin.php?page=tags` render and `ws_typetags_type_add()`'s `check_color()` → `get_color_text()` round trip.

**Deviations found by running the ported cases against the real endpoint** — the plan predicted the response codes from reading the handler, but two are decided a layer earlier, in `ws.php`'s parameter validation, and never reach it:

| Case | Plan said | Actual | Why |
|---|---|---|---|
| `testEmptyTokenIsRejected` | 403 | `1002` (`WS_ERR_MISSING_PARAM`) | ws.php treats `''` as an absent parameter, so the handler's token check never runs |
| `testZeroTagIdIsRejected` | 404 | `1003` (`WS_ERR_INVALID_PARAM`) | `WS_TYPE_ID` rejects non-positive ids before dispatch |

Both are recorded as they behave, with the reason in the test's docblock. `testZeroImageIdIsRejected` / `testNegativeImageIdIsRejected` were added for symmetry with the tag-id boundary pair.

#### [x] 5. Delete the superseded script
**File**: `plugins/typetags/tests/test_ws_tag_assignment.php` — deleted once every assertion has a named successor. A mapping table goes in the commit message so nothing is lost silently.

### Finding: editing a prefilter does not invalidate the compiled template

Surfaced while running this phase, and it cost a false-red debugging cycle. `Template::set_prefilter()` hashes only the filter's *callback name* into Smarty's `compile_id` (`include/template.class.php:1060-1070`) — not the callback's source. Editing `typetags_picture_prefilter()` therefore leaves the previously compiled `picture.tpl` in `_data/templates_c/` in place, and every later request keeps serving the old injection with no error anywhere.

Concretely: after reverting the `{if false}` mutation above, the compiled template still contained `<?php if (false) {?>` and `testUnassignedBadgeCountMatchesFixture` stayed red against correct source. `rm -rf _data/templates_c/*` fixed it. Written into CLAUDE.md's Testing section in this commit.

### Success Criteria:

#### Automated Verification:
- [x] `ddev exec plugins/typetags/vendor/bin/phpunit --testsuite integration --configuration plugins/typetags/phpunit.xml` — all green (39 tests, 124 assertions)
- [x] Assertion count ≥ 25 (nothing lost in the port) — 124 integration assertions; full suite 91 tests / 33110 assertions
- [x] Suite passes twice in a row without manual DB repair (fixtures are self-restoring)
- [x] Suite passes when run with the tests in reverse order (no inter-test dependency) — also verified with `--order-by=random`
- [x] `piwigo_user_cache` and `piwigo_image_tag` are byte-identical before and after a full run — also checked `piwigo_tags` and `piwigo_typetags`
- [x] `grep -rn '|| true' plugins/typetags/tests/` returns nothing

#### Manual Verification:
- [x] Reviewing the mapping table confirms every original assertion has a successor — **automated** during `/verify` (2026-08-29) rather than eyeballed:
  - `git show HEAD^:tests/test_ws_tag_assignment.php | grep -c 'assert_test('` → 26, minus the one `function assert_test(...)` definition line = **25 calls**, matching the mapping table's 25 rows
  - every successor named in the table was resolved against the suite by grepping for `function <name>(` in `tests/Integration/` — 19 fully-qualified `Class::method` entries plus the one continuation-line `::testFixtureProducesAtLeastOneUnassignedTag`, **20 resolved, 0 missing**
  - the integration suite declares 39 test methods in total, so the port added coverage rather than merely relocating it

  **Not added to the regression suite, deliberately.** The mapping is a one-time closure record: `test_ws_tag_assignment.php` no longer exists, so neither side of the table can drift again. A permanent test would have to read the commit message for method names, which is exactly the "build no apparatus that proves another apparatus" and "no test reads a document for a word" rule this plan sets out in Phase 5.

**Implementation Note**: Run `/verify`. Pause before Phase 4.

---

## Phase 4: E2E Layer — Playwright

### Overview
Close the 23 UI checklist items that page-source assertions cannot reach. Three-layer separation is enforced by convention: spec files orchestrate and assert, the page object owns every locator, and the seeding CLI owns fixtures. A locator appearing in a spec file is the first step toward an unmaintainable suite.

### Authoring workflow — drive the Playwright CLI, don't write specs blind

Draft and develop every spec through the Playwright CLI (the `playwright-cli` skill) against the running DDEV site, then commit the result. Writing a selector from reading `picture.tpl` and hoping is exactly the guessing the reference repositories prohibit — the badge markup is assembled at runtime by injected JavaScript, so the DOM a test sees is not the DOM the template shows.

```bash
ddev exec bash -c 'cd plugins/typetags && npx playwright codegen http://localhost/picture.php?/1/category/1'
npx playwright test --ui                       # pick apart a failing case interactively
npx playwright test remove.spec.js --debug      # step one spec
npx playwright show-trace test-results/<name>/trace.zip
```

Two things to confirm in the CLI *before* a spec is written, because both are runtime facts no file states:
- what the × button's DOM actually looks like once `events_public.inc.php:218-223` has appended it inside the badge `<span>`
- whether the separator text nodes between assigned tags are what the remove path's `previousSibling`/`nextSibling` logic assumes

**Both were probed against the running site before any spec was written**, driving Chromium directly rather than reading `picture.tpl` and hoping:

- **The × button is nested two levels deep**, not a sibling: `<a data-tag-id="3"><span style="background-color:…">Arbeiten <span class="typetag-remove" data-tag-id="3">×</span></span></a>`. The anchor's raw `textContent` therefore reads `"Arbeiten ×"`, which is why `PicturePage.assignedNames()` clones and strips the nested span before reading text — a naive text assertion would have compared against the wrong string.
- **The separator is a `", "` text node** (comma *and* trailing space) sitting directly between the anchors inside `#Tags dd`. Both branches of the remove path's cleanup handle it: `next.textContent.trim() === ","` matches, and `prev.textContent.match(/,\s*$/)` matches. The add path's `tagsDD.append(", ")` produces the identical shape, so server-rendered and JS-appended separators are indistinguishable.

Also confirmed by the probe and relied on by the specs: `#Categories` and `dl#standard` both exist on the picture page (the two insertion anchors the JS's "create the Tags row" branch falls back between), and `themes/modus` appears in the page's asset URLs, which is what lets the modus-theme spec guard against asserting under the wrong theme.

### Changes Required:

#### [x] 1. Page object — the only place locators live
**File**: `tests/e2e/support/PicturePage.js` (new)
```js
class PicturePage {
  constructor(page) {
    this.page = page;
    this.tagsRow        = page.locator('#Tags');
    this.assignedTags   = page.locator('#Tags dd a[data-tag-id]');
    this.removeButtons  = page.locator('.typetag-remove');
    this.unassignedBox  = page.locator('#typetags-unassigned');
    this.addBadges      = page.locator('.typetag-add');
  }
  async gotoImage(id) { await this.page.goto(`/picture.php?/${id}/category/1`); }
  async assignedNames() { /* strips the nested × before reading text */ }
}
```
Selector policy: locate by the stable ids and classes the plugin emits on purpose (`#Tags`, `#typetags-unassigned`, `.typetag-add`, `.typetag-remove`, `a[data-tag-id]`). Never by position within theme-generated markup.

Beyond the plan's sketch, the page object also owns `unassignedTagName()` (so no spec hardcodes a tag name that lives in the database), `assignedTagIds()`, `separatorTextNodes()`, `tagsRowPrecedesCategories()`, `loadedThemePaths()`, and the two static readers `computedOpacity()` / `inlinePointerEvents()`. Every one exists because a spec needed a runtime fact, and putting the reader here is what keeps `querySelector` out of the specs.

#### [x] 2. Scenario seeding, reusing the PHPUnit fixture builder
**File**: `tests/e2e/support/seed.php` (new), plus `tests/e2e/support/seed.js` (the `execFileSync` wrapper the specs call)
```bash
php tests/e2e/support/seed.php --scenario=all-assigned --image=1
php tests/e2e/support/seed.php --restore
```
Called from the spec body (seed) and `afterEach` (restore). Setup-before, and it prints the state it achieved as JSON — every spec asserts against `fixture.assigned_colored_count` / `unassigned_colored_count` rather than against a shape guessed from the scenario name.

**Deviation — cross-process restore.** `FixtureBuilder` records the original state in memory and restores it in the same process; Playwright seeds from one short-lived PHP process and restores from a later one. Rather than write a second restore path in the CLI (two definitions of how state is put back), `FixtureBuilder` gained `exportState()` / `importState()` and `seed.php` persists the export to a git-ignored `tests/e2e/.state/snapshot.json`. `--restore` re-imports it and calls the same `FixtureBuilder::restore()` the integration suite uses. A JSON round trip stringifies integer array keys, so `importState()` casts them back.

**Deviation — a fifth fixture.** Box 540 ("unassigned section hides when the last tag is assigned") is a *transition to empty*, and none of the four planned scenarios can produce it: `someAssignedSomeUnassigned` leaves 7 unassigned, `allColoredAssigned` leaves 0. `FixtureBuilder::allButOneColoredAssigned()` was added, which covers a case that actually differs rather than differing only in its numbers.

#### [x] 3. Specs
**Files**: `assign.spec.js`, `remove.spec.js`, `edge-cases.spec.js` — 21 specs, case list in *Testing Strategy*. `rendering.spec.js` (4 more) was added by this phase's `/verify` run; see below.

Two cases deserve calling out because they distinguish the defect from its lookalike:
- **Network failure** (`route.abort()`) → jQuery's `error` callback fires, badge is re-enabled. This already worked; it is Plan B box 553.
- **Server rejection** (`route.fulfill()` with HTTP 200 and `{"stat":"fail","err":403}`) → lands in `success`, and before the Phase 2 fix leaves the badge permanently dead. No existing box covers this; it is the defect's real signature.

**Authentication**: the assignment UI is not rendered for guests, so `auth.setup.js` logs in once as a Playwright setup project and saves `storageState`. Credentials come from `TYPETAGS_TEST_USERNAME` / `TYPETAGS_TEST_PASSWORD`, never from a file — the same rule `tests/Support/Config.php` enforces. The setup asserts the login form is *gone* rather than merely that a navigation happened, since Piwigo re-renders that form on a failed login.

#### [x] 4. No retries, no parallelism
**File**: `plugins/typetags/playwright.config.js`
**Changes**: `retries: 0`, `workers: 1`, `fullyParallel: false`, `forbidOnly: true`, `trace: 'on'`. A flaky test gets fixed or made deterministic — never retried into green, never disabled. Waits are on locator state and `expect.poll`, never on a bare timeout; the one `setTimeout` in the suite is inside a `page.route` handler, holding a mocked response open on purpose so the double-click test observes the in-flight window rather than racing past it.

**Deviation — config location.** The plan put this at `tests/e2e/playwright.config.js`, but Playwright resolves its config relative to the working directory, so the documented command (`cd plugins/typetags && npx playwright test`) would not find it there without a `--config` flag. The config sits at the submodule root with `testDir: './tests/e2e'`, which keeps the documented command working verbatim. Specs still live in `tests/e2e/`, so the `grep tests/e2e/*.spec.js` criterion below is unaffected.

### Finding: the two separator-cleanup branches are not symmetric

Surfaced by `comma separators clean up with no leading or trailing comma` failing on its first run — a real finding, not a flaky test. Removing an assigned tag takes one of two cleanup paths:

- **`nextSibling` branch** (a tag with a tag after it): `next.remove()` — the separator text node is deleted.
- **`previousSibling` branch** (the last tag): `prev.textContent = prev.textContent.replace(/,\s*$/, "")` — the node's text is emptied, but the node itself stays.

So removing the last tag leaves one zero-length text node behind. It is invisible and no requirement forbids it, so it is **recorded, not fixed**: the spec counts non-empty separator nodes for its real assertion and carries a separate characterization assertion pinning the leftover empty node at exactly one, so a future change to either branch shows up instead of passing silently.

### Checklist mapping — Plan B boxes to specs

**The plan said 23; the actual count of E2E-mapped boxes is 21**, and all 21 have a named spec. The 23 came from adding Plan B Phase 2's 4 E2E boxes to Phase 3's 17 and over-counting by two; boxes 512 and 513 in that same section are integration-covered, not E2E. Boxes 516 and 556 are split across layers, noted below.

| Box | Spec |
|---|---|
| 514 | `assign` → unassigned badges render at reduced opacity with a plus prefix |
| 515 | `remove` → assigned coloured tags show a remove button |
| 516 | `remove` → the unassigned section is recreated when it had been hidden (browser half; server half is `PicturePageSourceTest::testAllAssignedRendersNoUnassignedSection`) |
| 517 | `edge-cases` → the modus theme renders both sections correctly |
| 537 | `assign` → clicking an unassigned badge moves it into the Tags row at full opacity |
| 538 | `assign` → a remove button appears on the newly assigned tag |
| 539 | `assign` → the badge disappears from the unassigned list |
| 540 | `assign` → the unassigned section hides when the last tag is assigned |
| 541 | `assign` → the Tags row is created when the image had no tags |
| 542 | `assign` → the assignment survives a page reload |
| 545 | `remove` → clicking it removes the tag from the Tags row |
| 546 | `remove` → the tag reappears in the unassigned list at reduced opacity |
| 547 | `remove` → the Tags row hides when the last tag is removed |
| 548 | `remove` → the unassigned section is recreated when it had been hidden |
| 549 | `remove` → the removal survives a page reload |
| 552 | `edge-cases` → double-clicking issues exactly one request |
| 553 | `edge-cases` → a network failure leaves the tag in place and the badge clickable |
| 554 | `edge-cases` → comma separators render between multiple assigned tags |
| 555 | `edge-cases` → comma separators clean up with no leading or trailing comma |
| 556 | `assign` → the Tags row is created when the image had no tags (the "assigning creates the Tags row" half; the `#Tags`-absent half is `PicturePageSourceTest::testImageWithNoTagsRendersNoTagsRow`) |
| 557 | `edge-cases` → an image with only non-coloured tags shows no remove buttons |

Two specs map to no box, on purpose: `add then remove returns the page to its starting state` (round trip) and `a server rejection leaves the badge clickable` (the Phase 2 defect's signature — the box list predates the defect being found).

### Success Criteria:

#### Automated Verification:
- [x] `npx playwright test` — all green, exit 0 (21 specs + 1 setup = 22 passed, 8.3s; 26 after `/verify`)
- [x] Passes three consecutive runs with no retries configured (no flake) — 22/22 on all three, and again with the spec files given in reverse order
- [x] Every one of the mapped checklist items has a named spec — **21, not 23**; mapping table above, and the miscount is explained there
- [x] `grep -rnE "locator\(|querySelector" tests/e2e/*.spec.js` returns nothing (locators stayed in the page object)
- [x] Traces are written for each test — one `trace.zip` per test under `test-results/`
- [x] `piwigo_image_tag`, `piwigo_user_cache`, `piwigo_tags` and `piwigo_typetags` are byte-identical before and after three full runs; no snapshot file left behind
- [x] Full PHPUnit suite still green after the `FixtureBuilder` change (91 tests, 33110 assertions); `php -l` clean on `seed.php` and `FixtureBuilder.php`

#### Proven able to fail (step 2 of "proving a check can fail"):
- [x] Reverting the Phase 2 `else` branch on the add path (and clearing `_data/templates_c/`) turns `a server rejection leaves the badge clickable` red with the defect's exact signature — `inlinePointerEvents` stuck at `"none"` instead of `""`. **Exactly one test moved**: the other 7 in `edge-cases.spec.js` stayed green. Fix restored, file confirmed byte-identical to HEAD, suite re-run green.

#### Manual Verification:
- [x] Watching one run headed confirms the assertions describe what actually happens on screen — **automated** during `/verify` (2026-08-29), see below
- [x] Rendering under the modus theme matches the screenshots in `.agent-tests/` — done as a one-time comparison and then **superseded by an automated check**, see below

#### Automated during `/verify` (2026-08-29):

Both manual boxes were about the same gap: the rest of the suite asserts DOM *shape*, which a
badge can satisfy while being painted the wrong colour, collapsed to zero height, or sitting on
a page whose script threw. That part has an oracle, so it is now a test —
`tests/e2e/rendering.spec.js` (4 specs):

- [x] `every unassigned badge paints its configured colour at a real size` — seeds `no-tags` so
  the whole palette is on one page, then compares each badge's **computed** `background-color`
  against the colour read from `piwigo_typetags` via the seeding CLI. `seed.php` now emits both
  notations (`#FFCA4F` / `rgb(255, 202, 79)`), so the expected value is read from production
  rather than a second copy of the palette typed into a spec.
- [x] `every assigned badge paints its configured colour at a real size` — the same over
  `all-assigned`, covering the `typetags_render()` path instead of the prefilter's.
- [x] `a badge assigned in the browser is painted like a server-rendered one` — the add path
  builds its badge as a JavaScript string literal, entirely separately from the PHP that renders
  one. This clicks, reads the painted result, reloads, and asserts the PHP-rendered badge is
  painted identically. Nothing below the browser can see a divergence between those two.
- [x] `the assignment UI initialises with no console or page errors` — with an anti-vacuity guard
  asserting the badges and remove buttons are actually present first, so a page that rendered no
  assignment UI cannot pass by having no script to throw.

**Proven able to fail** — four mutants, each killing exactly its target and nothing else:

| Mutant | Killed | Nothing else moved |
|---|---|---|
| unassigned badge `background-color` hardcoded to `#123456` in the prefilter | `every unassigned badge paints its configured colour…` | yes (3 others green) |
| JS-built badge's `background-color` hardcoded, PHP path untouched | `a badge assigned in the browser is painted like a server-rendered one` | yes (3 others green) |
| `nonexistentFunctionCall()` at the top of the injected script | both `a badge assigned…` **and** `the assignment UI initialises…` | expected — a throwing init means no × buttons exist, so the second test died on its anti-vacuity guard rather than on its error assertion |
| `console.error(…)` at the end of the injected script, UI otherwise intact | `the assignment UI initialises with no console or page errors` only | yes — this is the mutant that proves the error assertion itself has teeth, which the one above did not |

After each mutant, `events_public.inc.php` was confirmed byte-identical to HEAD and
`_data/templates_c/` cleared (per the prefilter/compile-cache finding in Phase 3).

**The modus screenshot comparison, done once and recorded**: the 2026-04-27 reference images
(`.agent-tests/2026-04-27-tag-assignment-ui/screenshots/07`, `10`) were compared against the new
captures. Structure and colour match — `Schlagworte` carrying a pill badge with the `×` *inside*
it, then `Alben`, `Besuche`, then the `+` badges at reduced opacity; `Personen` `#FFFFB6`,
`Arbeiten` `#FFCA4F`, `Gewerbe` `#BE6CB7` in both. The only difference is modus's light/dark
colour scheme (the reference was captured in dark mode), which is a theme setting, not a
rendering regression. **Not added to the regression suite as a screenshot baseline**: pixel
comparison of a photo gallery is flaky for reasons unrelated to this feature, and the substance
of the comparison — that badges are painted their configured colours at real size — is what the
four specs above now assert on every run, more precisely than a screenshot diff would.

New evidence set: `.agent-tests/2026-08-29-phase4-e2e/` (report + 7 screenshots covering all four
UI states and the add / remove / create-row transitions).

**What stays manual** — no oracle, so it goes to the hand-check ledger rather than a test:
whether the badge contrast is *legible* for all 8 colours, and whether the hover opacity
transition *feels* right. Both were already listed under *Manual Testing Steps*.

#### Final state of the suite (2026-08-29):
- [x] Playwright: **26 passed** (25 specs + 1 auth setup), 9.5s
- [x] PHPUnit: 91 tests, 33110 assertions
- [x] `grep -rnE "locator\(|querySelector" tests/e2e/*.spec.js` → nothing; `grep -rn waitForTimeout tests/e2e/*.spec.js` → nothing
- [x] Database byte-identical to the pre-Phase-4 baseline after every run above, mutation runs included

**Implementation Note**: Run `/verify`. Pause before Phase 5.

---

## Phase 5: Conventions and Plan Closure

### Overview
Make the conventions this plan already uses explicit, and close out the two plans left in `draft` with 33 unticked boxes.

### Changes Required:

#### [x] 1. State the technique legend once
**File**: `docs/agents/TESTING.md` (new)
**Changes**: the seven-tag vocabulary, stated in exactly one place and cited from everywhere else. Both reference repositories keep the legend in a single early document and never restate it; this repository has neither the legend nor a place for it.

| Tag | Meaning |
|---|---|
| `[HAPPY]` | happy path |
| `[NEG]` | negative case |
| `[ECP]` | equivalence class partition |
| `[BVA]` | boundary value |
| `[ST]` | state transition |
| `[DT]` | decision table |
| `[ERR]` | error guessing |

Plus the three rules that make the tags do more than decorate: a technique that does not apply is recorded **with its reason** rather than omitted; tags attach to individual test-case bullets so a plan doubles as its coverage map; and the legend is stated once.

The rules the file records, each adapted from a reference repository that has run it in anger:

- **Watch it fail first.** *Write it, run it, and watch it fail before the code it describes exists. Read the failure and check it is the one expected, since a test can fail for a reason that has nothing to do with it.* A test written against code that already exists records it instead of driving it — passing on its first run is the tell.
- **Proving a check can fail** — three steps: run it repeatedly, alone and in a suite; break the system and confirm it goes red; reverse the assertion and confirm it goes red. Step two stops at the unit boundary.
- **Placement**: *put each behaviour at the lowest layer that can express it, and do not restate it higher up.* When a test is hard to place, ask what has to break for it to fail — one function, unit; two parts meeting across a real boundary, integration; the shipped page, E2E.
- **Anti-regression cross-check**: *when you break a low-level function, its own test must fail before the E2E test does. If the E2E test fails first, coverage has not been pushed down far enough.*
- **Mutation testing, unit level only.** Never for a UI or E2E test — a red end-to-end run does not say which mutation caused it. Never for a structural guard test. Kept as prose, because *a script that patches a source file and reverts it is a second thing to keep correct, and it breaks as soon as the line it patches moves.* Where a higher-layer test is the only witness for a rule, *that is a gap in the pyramid; close it by pushing the rule down, not by mutating the browser.*
- **Anti-vacuity**: every guard that scans anything carries a lower-bound constant, and every count assertion is preceded by a guard proving the count is not zero. *Whoever removes a line removes the watchman, not the risk.*
- **Do not transcribe production data into a test.** Read it from production, or the copy rots silently.
- **A test whose oracle is the code must say so.** It cannot find a defect in that code; it reports a change. Each such test carries a comment naming the behaviour it records and stating that no requirement confirms it. They are a refactor safety net and nothing more.
- **A known gap becomes a deliberately failing test**, marked skipped with its reason and a link to the record — not silence, and not prose.
- **Flakiness**: a flaky test gets fixed or made deterministic; it does not get disabled or retried into green.
- **Assert the causal fact, not a wall-clock figure**, so nothing depends on the machine — and never quietly widen a threshold to make a run pass.
- **Build no apparatus that proves another apparatus.** This is the rule that stops a guard suite metastasising, and it is why the mutant record stays prose and why no test here reads a document for a word.
- **Numbers in documentation rot.** Every measured count in `TESTING.md` or a plan carries the date it was measured. Where a number is load-bearing, keep it as a dated measurement and say so; otherwise leave it out.

**Deviation — the rules acquired a home between planning and implementation, so the split
went the other way.** Commit `b8481dcee`, made after this plan was written, added
`.claude/rules/{testing,test-design,mutation-testing,e2e-tests,backpressure,precommit-hooks}.md`,
which already own the legend and most of the list above. Written literally, this item would
create a second copy of rules that already have an owner — the exact thing the plan's own
*single source of truth* rule forbids.

Resolved by splitting on **rule vs. record**, which is the distinction that actually holds:

- **`.claude/rules/`** owns every rule. It is auto-loaded and path-scoped, so it is what an
  agent is actually holding when it edits a test. The five rules from the list above that
  were genuinely missing there were added to `test-design.md`: a characterization test must
  declare its oracle; a known gap becomes a skipped test with its reason; assert the causal
  fact rather than a wall-clock figure; build no apparatus that proves another apparatus;
  numbers in documentation carry their date.
- **`docs/agents/TESTING.md`** owns what the rules produced and what no rules file should
  carry: the mutant table with its three findings, the hand-check ledger, the deliberate
  non-coverage table, the dated measurements, and the two codebase-specific rulings
  (`<script>`-stripping before any page-source scan; the prefilter's search strings as named
  constants). It cites the rules rather than restating any of them, and cites CLAUDE.md for
  the run commands rather than carrying a third copy.

Records do not belong in an auto-loaded rules file — they grow, they are history rather than
instruction, and they would cost context on every matching edit.

#### [x] 1b. Record deliberate non-coverage
**File**: `docs/agents/TESTING.md`
**Changes**: a **Tests NOT required (with justification)** table, in the same technique vocabulary, so a later reader can tell a considered omission from an oversight.

| Component | Why no test | Technique rationale |
|---|---|---|
| `check_color()`'s DB branch in `get_typetag_id()` | Covered at the integration layer by `typetags.type.add` | Duplicating it at the unit layer adds no equivalence class |
| `typetags_escape_prefilter()` | One `str_replace`, no branching | No partition and no boundary to sit on |
| `typetags_tags()` letters/cloud/cumulus modes | Not touched by this feature | Outside the change's blast radius |
| `removeTag` image validation | A `DELETE` on a nonexistent image is already a no-op | No reachable defect; the asymmetry with `addTag` is deliberate and commented |
| Concurrent add/remove from two tabs | The `PRIMARY KEY` makes both operations idempotent | Testing it would assert the database's behaviour, not the plugin's |

Landed with three rows beyond the five planned, each pointing at the decision that settles
it rather than restating the reasoning: the `nb_available_tags` scoping (0004), per-image
visibility on `addTag` (0005), and `post_only` (0003).

#### [x] 2. Decision log
**File**: `docs/agents/decisions/` (new directory)
**Changes**: numbered decisions this plan already depends on, so later work can cite rather than re-litigate them:
- `0001-mutation-testing-unit-only.md` — settled 2026-08-28
- `0002-e2e-runner-location.md` — the Phase 0 gate's outcome and why
- `0003-no-post-only-on-ws-methods.md` — with the CSRF reasoning
- `0004-unscoped-tag-cache-invalidation-accepted.md`
- `0005-tag-assignment-permission-model.md` — records "all logged-in users", and explicitly names the unexamined question about images in non-browsable categories

#### [x] 3. Close out the two existing plans via `/verify`
**Files**: `docs/agents/plans/2026-04-19-install-colored-tags-plugin.md`, `docs/agents/plans/2026-04-27-picture-page-tag-assignment.md`
**Changes**: run `/verify` against each. Its job is to automate the manual steps, run them, fold them into the regression suite, fix what fails, list what cannot be automated, and update plan status. Concretely:
- **Plan A** (3 manual boxes + 3 step headings): plugin active, config page loads, tag colors configurable — all three are assertable against the database and over HTTP.
- **Plan B Phase 1** (7 boxes): already covered by the integration suite; each gets ticked with a reference to the test that covers it.
- **Plan B Phase 2** (6 boxes): 2 covered by integration, 4 by E2E.
- **Plan B Phase 3** (17 boxes): all by E2E.
- Anything `/verify` reports as non-automatable goes into a dated hand-check ledger in `docs/agents/TESTING.md` — nothing gets ticked on prose alone.
- Flip both frontmatters off `draft` only once every box is either ticked-with-a-reference or in the ledger.

**Outcome (2026-08-29).** All 33 boxes closed with a named successor; both frontmatters now
read `status: complete` with a `completed:` date. `grep -c '^\s*- \[ \]'` returns 0 for both
files.

- **Plan A** (3 boxes + 3 step headings): all three were assertable and became
  `tests/Integration/PluginActivationTest.php` — 5 tests, 24 assertions. Two of the five go
  beyond the checklist: `::testTagColourCanBeRemovedAgain` `[ST]`, and
  `::testGuestCannotAssignAColour` `[NEG]`, which covers the one `admin_only` method in the
  plugin — nothing had asserted that gate. Each test was watched failing against a deliberate
  break (plugin deactivated / template property renamed / handler's `WHERE` neutered /
  `admin_only` removed), and each mutation killed only its own targets.
- **Plan B Phase 1** (7 boxes): integration suite, as predicted. Three of them gained an
  assertion the box did not ask for — that the row *survived* a rejected call.
- **Plan B Phase 2** (6 boxes): 2 integration, 4 E2E. Box 516 turned out to be two different
  facts and is closed at both layers rather than one.
- **Plan B Phase 3** (17 boxes): all E2E, plus two specs mapping to no box.
- The two subjective items (badge contrast legibility, hover feel) are in the ledger with
  their reason, not ticked.

#### [x] 4. Update CLAUDE.md
**File**: `CLAUDE.md`
**Changes**: the Testing section currently states "Piwigo core has no test suite (no PHPUnit)" and names two mechanical checks. Replace with the command table from *Desired End State*, note that `plugins/typetags` now carries composer and npm dev dependencies, and correct the "no dependency manager" claim, which this plan makes untrue. Per the reference repositories' meta-rule: when something in the instructions stops being true, fix it in the commit that made it untrue.

### Success Criteria:

#### Automated Verification:
- [x] `docs/agents/TESTING.md` exists, and the legend has exactly one normative owner — but that owner is `.claude/rules/test-design.md`, not `TESTING.md`, per the rule-vs-record split above. `grep -rln '\[BVA\].*boundary' --include='*.md' .` returns 3: the rules file (normative), plus this plan and the 2026-08-28 research document, which are dated records that *proposed and specified* the legend. Those two are deliberately left alone — editing a research record or deleting this plan's own specification to satisfy a grep would trade one honesty problem for a worse one. `grep -rl ... docs/ | wc -l` returns 2 for the same reason, and returned 2 before this phase as well, so the criterion as written was never achievable without rewriting history.
- [x] Neither plan file contains `status: draft` — both read `status: complete` with `completed: 2026-08-29`
- [x] No unticked `- [ ]` remains in either plan file — `grep -c` returns 0 for both; every box names its successor, and the two subjective items are in the ledger rather than ticked
- [x] CLAUDE.md contains no stale "no PHPUnit" / "no dependency manager" claim — the Testing section had already been corrected in `b8481dcee`/`56080e4b2`; what remained stale was line 34's blanket "no composer.json, no package.json", now scoped to the application itself. Added: a pointer to `TESTING.md` and a convention entry for `docs/agents/decisions/`.

#### Manual Verification:
- [x] Each newly ticked box names the test that covers it, and spot-checking confirms the test genuinely asserts that behaviour — done by mutation rather than by reading, which is the stronger check: for Plan A's three boxes, each named successor was watched failing against a deliberate break of exactly the behaviour the box describes (plugin deactivated; the template's `background-color` property renamed; the `setType` handler's `WHERE` clause neutered). Each mutation killed only its own targets. Plan B's successors were already proven able to fail in Phases 3 and 4.

#### Mutation table for the unit suite (the plan's *Testing Strategy → Mutation Testing* section):
- [x] Six mutants run by hand 2026-08-29, one at a time, `functions.inc.php` confirmed byte-identical to HEAD after each. Table and findings in [docs/agents/TESTING.md](../TESTING.md#mutant-table--unit-suite).
- [x] Three findings, recorded with which reading applies rather than guessed:
  - `$l > 0.45` → `>= 0.45` **survived because the boundary is unreachable**, not because the test is weak — `l == 0.45` needs `min+max = 229.5` on 8-bit channels. This is the reading the plan anticipated.
  - `strlen($color) == 7` → `>= 7` **survived because the test was genuinely weak**: none of its four inputs discriminated the mutant, since `str_repeat('a', 1000)` takes the mutant's branch but returns `'#000'` anyway — the same answer the guard gives. Fixed by adding `'#000000_overlong'`, which reads as `'#000000'` under the mutant; the mutant now dies, killing that one test and nothing else.
  - The template-guard mutant **as specified is degenerate**: weakening the guard's own assertion is not a mutation of production, so "killed by nothing" is trivially true — no test tests the test, and per *build no apparatus that proves another apparatus*, none should. Replaced with the production-side mutant it was reaching for: a duplicated `TYPETAGS_TPL_TAG_ANCHOR` in `picture.tpl`, which the shipped `=== 1` guard kills and which survives when the guard is weakened to `>= 1`. **The exact-count assertion earns its strictness** — a stronger result than the plan predicted, which expected to record a gap.
- [x] "Nothing else moved" verified for every killed row: each mutant killed exactly the tests watching it, and the rest of the 52 stayed green.

**Implementation Note**: Run `/verify`. Pause before Phase 6.

---

## Phase 6: Commit Gate (pre-commit hook)

### Overview
A ratchet, not a wall: block new breakage, leave existing state alone, and stay bypassable. The gate exists only because Phases 1–4 created a real command to gate on.

### Changes Required:

#### [x] 1. The hook
**File**: `.githooks/pre-commit` (new, version-controlled)
**Changes**:
- `php -l` on staged `*.php` only (host PHP for speed; the container is the parity check, run separately)
- the **unit suite only** — it is sub-second and needs no DDEV. Integration and E2E stay out: they need the stack up, and a hook that fails when Docker is down is a hook people disable.
- if DDEV is down, `php -l` still runs and the suite is skipped **with a printed warning**, never silently
- `--no-verify` bypasses, by design

**Deviation — the suite does need DDEV.** The plan's phrasing ("the unit suite only — it is
sub-second and needs no DDEV") conflates two things: the *suite* needs no database or HTTP,
but the *runner* lives in the container (`ddev exec plugins/typetags/vendor/bin/phpunit`),
and the host runs PHP 8.5 against a container on 8.4. Running it on the host would gate
commits on a different PHP than the project targets. So the hook shells out to `ddev exec`
(1.6s wall clock, measured 2026-08-29, of which the suite itself is 0.098s) and takes the
DDEV-down path the plan already specifies. `php -l` still uses host PHP, as specified.

**Deviation — a third file, `.githooks/lib.sh`.** The hook and its self-test would otherwise
each carry a hand-typed copy of the vacuous-assertion pattern, which is exactly the
*do not transcribe production data into a test* case. `lib.sh` holds the three shared
constants (`TEST_PATH_PATTERN`, `VACUOUS_PATTERN`, `UNIT_SUITE_ARGS`); both source it, and
the self-test builds its probe by interpolating `$VACUOUS_PATTERN` rather than retyping it.

#### [x] 2. Install for BOTH repositories
**File**: `tools/install-hooks.sh` (new)
**Changes**: `core.hooksPath` on the superproject alone does **not** cover submodule commits, and every plugin commit is a submodule commit — so the hook would silently never run on the commits that matter. The installer configures both:
```bash
git config core.hooksPath .githooks
git -C plugins/typetags config core.hooksPath "$(pwd)/.githooks"
```

#### [x] 3. Self-test for the hook — the hook's own anti-vacuity check
**File**: `tools/test-hooks.sh` (new)
**Changes**: a hook nobody has watched fail is a hook nobody knows works, and a hook that silently stops blocking is worse than none. The self-test stages throwaway probes, runs the hook, asserts the exit code, then unstages and deletes — it never creates a commit. It needs **two red probes and one green**, so it proves the hook can both block and let through.

```bash
run_case "syntax error blocks"   1 "$probe_dir/probe_broken.php"
run_case "vacuous assertion blocks" 1 "$probe_dir/probe_vacuous.php"   # a staged '|| true'
run_case "clean file passes"     0 "$probe_dir/probe_clean.php"
```

The second probe is the ratchet that matters most here: the defect this whole plan started from was an assertion ending in `|| true`. Grep **added lines only** (`git diff --cached -U0 | grep '^+'`), so pre-existing code is grandfathered and only new occurrences are blocked. A `trap` on `EXIT`/`INT`/`TERM` restores the tree even when the run is interrupted.

**Deviation — a temporary index instead of stage-then-unstage.** The plan's "stages probes, then unstages and deletes" would run the hook over whatever the developer already had staged, so an unrelated staged file could turn the green case red and the self-test would report a fault that is not the hook's. Each case instead exports `GIT_INDEX_FILE` to a throwaway index seeded with `git read-tree HEAD`, so the hook sees exactly one file and the real index is never written to at all. The `trap` still removes the probe directory and the temp index.

**Added beyond the plan**: the self-test asserts `probe_vacuous.php` is *syntactically valid* before running the hook on it. Without that, a typo in the probe would make the syntax check fire, the case would go green, and it would say nothing about the vacuity ratchet it claims to test.

#### [x] 4. Guard against the mutation that changes nothing
**File**: `tools/test-hooks.sh`
**Changes**: if a probe pattern stops matching what the hook looks for, the self-test passes over nothing and proves nothing. Assert each probe file actually differs from a clean baseline before running the hook on it — the designed failure is *"probe changed nothing"*, reported red rather than silently green.

**Proven able to fail**: rewriting `probe_vacuous.php` as a byte-copy of the clean baseline turned the guard red (`probe_vacuous.php is identical to the clean baseline - it would prove nothing`) alongside the case it protects, instead of that case passing over nothing.

### Success Criteria:

#### Automated Verification:
- [x] `bash tools/test-hooks.sh` passes all three cases (two red, one green) — **10 cases after `/verify`**: three probe preconditions, the three direct-invocation cases, two installation checks, two real-`git commit` cases
- [x] `git -C plugins/typetags config --get core.hooksPath` is set — `/Users/christian.baumann/git_repos/_own/piwigo/.githooks` (absolute; the superproject's is the relative `.githooks`)
- [x] A commit in the submodule with a PHP syntax error is rejected — real `git commit`, rejected naming the file; submodule HEAD unmoved
- [x] A staged new `|| true` inside a test file is rejected; an existing one elsewhere is not — the probe was committed with `--no-verify`, then an unrelated edit to the *same file* committed cleanly, which is the stricter form of the criterion
- [x] `git commit --no-verify` bypasses — used to create the grandfathering fixture above
- [x] With Docker stopped, the hook still runs `php -l` and prints the skip warning rather than passing silently — exercised with a stub `ddev` on `PATH` returning non-zero (`WARNING: DDEV is not running - unit suite skipped`, exit 0) rather than by stopping Docker, which takes the identical branch; the `ddev`-absent branch was checked too
- [x] Deleting the hook's grep pattern makes `test-hooks.sh` go red (the self-test has teeth) — neutered `hits=$(… grep -F …)` to `hits=""`; **exactly one case moved**, the other two stayed green. Hook restored and confirmed byte-identical.

#### Manual Verification:
- [x] The hook was watched failing once by hand before being trusted — two real `git commit` invocations in `plugins/typetags` (syntax error, then a new `|| true`), both rejected at the terminal, neither creating a commit. Recorded in [TESTING.md](../TESTING.md#the-commit-gate-and-what-it-was-watched-doing)

#### Automated during `/verify` (2026-08-29):

The manual box had an automatable core the three original self-test cases could not reach:
they invoke `"$HOOK"` **directly**, so all three stay green in a repository where git has
never been told the hook exists. What was watched by hand was the other thing — that a real
`git commit` is refused — and that is what got automated.

- [x] `superproject` / `plugins/typetags core.hooksPath resolves to .githooks` — resolves the
  configured value (relative against the repo's top level, absolute as given) and compares it
  to `.githooks`. This is the fresh-clone failure mode: nobody ran the installer, and the gate
  silently does nothing.
- [x] `git rejects a real commit` / `git accepts a clean commit` — a throwaway repo under
  `mktemp -d`, wired via `core.hooksPath`, seeded with one `--no-verify` commit so the hook has
  a `HEAD` to diff against. Asserts the exit code **and** that `rev-list --count HEAD` moved by
  exactly one on the clean case and not at all on the rejected one. The count assertion is the
  point: an exit code alone does not witness that the commit was withheld.
- [x] **Proven able to fail** — `git -C plugins/typetags config --unset core.hooksPath` turned
  `plugins/typetags core.hooksPath resolves to .githooks` red and **nothing else moved**; the
  two real-commit cases wire their own repository, so they stayed green. Installer re-run.
- [x] Hook wall clock on a clean run: **1.0s, measured 2026-08-29** (unit suite 0.098s, the
  rest `ddev exec` overhead). A dated measurement, not an assertion — nothing gates on it, per
  *assert the causal fact, not a wall-clock figure*.

**Deliberately not automated**, each for a stated reason rather than dropped from the list
(the reasons are in [TESTING.md](../TESTING.md#the-commit-gate-and-what-it-was-watched-doing)):
grandfathering a pre-existing `|| true` (needs two commits and a file already carrying the
pattern in `HEAD`, to exercise one line of the hook); `--no-verify` (git's behaviour, not the
hook's); and the DDEV-down degradation (needs `PATH` manipulation around the very runner the
self-test depends on). All three were watched by hand and are in the ledger.

**Implementation Note**: Run `/verify`. Then Phase 7.

---

## Phase 7: Push the Submodule

### Overview
The submodule is 3 commits ahead of `origin/master` (`e07139f`, `7dc69fc`, `9974177`) plus everything this plan adds. Until it is pushed, a fresh clone of the superproject fetches a pointer to commits the remote does not have — the superproject is unbuildable for anyone but this machine.

### Changes Required:

#### [x] 1. Push the plugin
```bash
git -C plugins/typetags push origin master
```
Pushed 2026-08-29: `aaf0c0e..3eeee00  master -> master`. Eight commits, not three — the
three pre-existing ones (`9974177`, `7dc69fc`, `e07139f`) plus the five this plan produced
(`6059914` toolchain, `7642e08` unit layer + defect fixes, `4aebd2a` integration port,
`39a8fa8` E2E layer, `3eeee00` Plan A closure).

#### [x] 2. Bump the superproject pointer and commit

**Deviation — already done, so nothing to commit.** The pointer was bumped incrementally as
each phase landed (most recently in `91d3a820a`), so `git ls-tree HEAD plugins/typetags`
already read `3eeee007d`, identical to the submodule's `HEAD`. `git submodule status`
carried no `+`. A separate bump commit would have been empty. The ordering the phase
assumes — push, *then* bump — held anyway: the pointer only became *valid for anyone else*
at the moment of the push, which is the property this phase exists to establish.

### Success Criteria:

#### Automated Verification:
- [x] `git -C plugins/typetags status` reports no commits ahead of origin — `rev-list --count origin/master..master` → 0
- [x] A clone into a temp dir with `--recurse-submodules` checks out cleanly and the unit suite passes there — the clone resolved `plugins/typetags` from the GitHub URL in `.gitmodules` at `3eeee007d`, which is what proves the push landed rather than merely that the local repo is self-consistent. `composer install` then `phpunit --testsuite unit` → **52 tests, 32986 assertions, 0 failures** (host PHP 8.5.7, PHPUnit 13.3.2). Ran on the host deliberately: the temp clone is outside the DDEV mount, and the unit suite passing with no container is the "no DB, no HTTP, no bootstrap" property from the layer table demonstrated rather than asserted. The container remains the canonical runner for PHP-version parity.
- [x] `git submodule status` shows no `+` or `-` prefix

#### Manual Verification:
- [x] The three pre-existing commits are on the remote and the plugin still installs from a clean checkout — **both clauses automated during `/verify` (2026-08-29)**, see below

#### Automated during `/verify` (2026-08-29):

**First clause — commits on the remote.** `git merge-base --is-ancestor <c> origin/master` for
each of `e07139f`, `7dc69fc`, `9974177`: all three reachable. That is the entire clause; it
needed no eyeballing and is re-checkable by one command.

**Second clause — "still installs from a clean checkout".** Reading `maintain.class.php`
reframed this. `install()` does three things, each already guarded: `conf_update_param`
behind `empty($conf['TypeTags'])`, an `ALTER TABLE ... ADD id_typetags` behind a
`SHOW COLUMNS` probe, and `CREATE TABLE IF NOT EXISTS`. It is idempotent by construction, and
`PluginActivationTest` already asserts its effects on the live install. So the *database* half
of "installs" was never the risk.

The risk this phase actually introduces is the **checkout** half: Phase 0 added the submodule's
first `.gitignore`, and the plugin is consumed as a pinned submodule commit. A runtime file
present on this machine but never committed works perfectly here and is absent from every
clone — and no existing layer can see it, because the unit suite reads the working tree and the
integration and E2E suites drive the working tree through a web server. All three stay green
while a clone of the same commit is broken.

That is a structural guard, and it became one — `tests/Unit/CleanCheckoutTest.php` (4 tests,
23 assertions), unit layer, no DB or HTTP:

- [x] `testEveryRuntimeIncludeTargetIsCommitted` — the runtime file list is **discovered** from
  production by scanning tracked sources for `TYPETAGS_PATH . '<path>'` (3 PHP includes + 2
  `.tpl` templates), never transcribed into the test, so a new include is covered the day it is
  added `[ERR]`
- [x] `testLoaderEntryPointsAreCommitted` — `main.inc.php` and `maintain.class.php`, which
  Piwigo's loader requires by convention and which therefore never appear in the include graph `[ERR]`
- [x] `testNoRuntimeFileIsGitIgnored` — no `.gitignore` rule matches a runtime path `[ERR]`
- [x] `testGuardFixtureIsNotVacuous` — two lower bounds: ≥4 discovered targets (measured
  2026-08-29: 5) so a rotted regex fails loudly, and ≥100 tracked files (measured 2026-08-29:
  154) because `git ls-files` returns an empty set with exit status 0 outside a work tree `[ERR]`

Skips with a stated reason when there is no git work tree — a plugin installed from a zip has
no repository, and committed-ness is not observable there.

- [x] **Proven able to fail** — four production-side mutants, each killing exactly its target
  with the other 55 tests green; table and the finding in
  [TESTING.md](../TESTING.md#second-run--cleancheckouttest-2026-08-29).
- [x] **A finding, recorded rather than smoothed over**: `testNoRuntimeFileIsGitIgnored` was
  **vacuous as first written**. `git check-ignore` skips tracked files unless `--no-index` is
  passed, so the only path that could fail it was one both ignored and untracked — already
  caught by the first test. It duplicated coverage while appearing to add some. Fixed with
  `--no-index`; the mutant then died, killing that one test and nothing else. Same shape as the
  `strlen >= 7` finding in Phase 5.
- [x] Unit suite after the addition: **56 tests, 33009 assertions**, 0.105s — green in default
  and reverse order. `main.inc.php`, `.gitignore` and the git index all confirmed restored after
  the mutation run.

**What stays manual, and why** — in the ledger, not ticked: a first-time `install()` against a
genuinely empty schema. Reaching it on the live install would require `uninstall()` first, which
drops `piwigo_typetags` and `piwigo_tags.id_typetags` — destroying real tag-colour data to test
a method that is idempotent by construction. The coverage is not worth the risk, and provisioning
a second Piwigo instance is infrastructure this repository does not have.

---

## Testing Strategy

Test pyramid, bottom-heavy. Each behaviour lives at the lowest layer that can express it and is not restated higher up. Technique tags per the legend established in Phase 5.

### Unit Tests

#### `get_color_text($color)` — `tests/Unit/GetColorTextTest.php`

**Equivalence classes** (input domain: string):

| Class | Representative | Expected |
|---|---|---|
| falsy | `''` | `''` |
| falsy (PHP quirk) | `'0'` | `''` |
| 7-char | `'#FFFFB6'` | `'#000'` |
| 4-char | `'#fff'` | `'#000'` |
| other length | `'#12345'` | `'#000'` after fix (throws before) |

**Happy path:**
- [x] `testSevenCharLightColourGetsBlackText` — `'#FFFFB6'` → `'#000'` `[HAPPY]`
- [x] `testSevenCharDarkColourGetsWhiteText` — `'#007DAD'` → `'#fff'` `[HAPPY]`
- [x] `testFourCharShorthandIsSupported` — `'#fff'` → `'#000'`, `'#000'` → `'#fff'` `[ECP]`
- [x] `testAllConfiguredPaletteColoursResolve` — the 8 live colours, none throws, each returns `#000` or `#fff` `[HAPPY]`

**Boundary values** (threshold `$l > 0.45`):
- [x] `testThresholdJustBelowGetsWhiteText` — `'#00E500'`, `l = 0.449020` → `'#fff'` `[BVA]`
- [x] `testThresholdJustAboveGetsBlackText` — `'#00E600'`, `l = 0.450980` → `'#000'` `[BVA]`
- [x] `testThresholdIsUnreachableOnEightBitChannels` — documents that `l == 0.45` requires `min+max = 229.5`; asserts no `#RRGGBB` produces exactly `0.45` `[BVA]`
- [x] `testFourCharThresholdBoundary` — `'#0d0'` → `'#fff'`, `'#0e0'` → `'#000'` `[BVA]`
- [x] `testExtremes` — `'#000000'` → `'#fff'`, `'#ffffff'` → `'#000'` `[BVA]`

**Negative and edge:**
- [x] `testMalformedLengthReturnsSafeDefault` — `'#12345'`, `'#'`, `'ab'`, 1000-char string → `'#000'`, no throw `[NEG]` **(fails before Phase 2 fix)**
- [x] `testEmptyReturnsEmptyString` — `''` → `''` `[ECP]`
- [x] `testZeroStringIsTreatedAsEmpty` — `'0'` → `''`; records the `empty()` quirk `[ERR]`
- [x] `testNullIsTreatedAsEmpty` — `null` → `''` `[ERR]`
- [x] `testNonHexOfCorrectLengthDoesNotThrow` — `'notahex'`, `'#GGGGGG'` → `'#fff'`; records that `hexdec` silently ignores invalid characters, so garbage is accepted rather than rejected `[ERR]`
- [x] `testCaseInsensitive` — `'#ffffb6'` and `'#FFFFB6'` agree `[ECP]`
- [x] `testLeadingHashIsNotValidated` — `'1234567'` is processed as if it were a colour; characterization, no requirement behind it `[ERR]`

*Decision table not applicable: one condition (lightness) and two outcomes. State transition not applicable: the function is pure and holds no state.*

#### `check_color($hex)` — `tests/Unit/CheckColorTest.php`

**Happy path:**
- [x] `testSixDigitHexIsAccepted` — `'aabbcc'` → `'#aabbcc'` `[HAPPY]`
- [x] `testThreeDigitHexIsExpanded` — `'abc'` → `'#aabbcc'` `[HAPPY]`
- [x] `testLeadingHashIsStripped` — `'#abc'` → `'#aabbcc'` `[ECP]`

**Boundary values** (length after `ltrim('#')`):
- [x] `testLengthZeroRejected` — `''` → `false` `[BVA]`
- [x] `testLengthTwoRejected` — `'ab'` → `false` `[BVA]`
- [x] `testLengthThreeAccepted` — `'abc'` → `'#aabbcc'` `[BVA]`
- [x] `testLengthFourRejected` — `'abcd'` → `false` `[BVA]`
- [x] `testLengthFiveRejected` — `'abcde'` → `false` `[BVA]`
- [x] `testLengthSixAccepted` — `'aabbcc'` → `'#aabbcc'` `[BVA]`
- [x] `testLengthSevenRejected` — `'abcdefg'` → `false` `[BVA]`

**Negative and edge:**
- [x] `testNonHexCharactersRejected` — `'gggggg'`, `'ab c'` → `false` `[NEG]`
- [x] `testMultipleLeadingHashesAreAllStripped` — `'###abc'` → `'#aabbcc'`; characterization of `ltrim` `[ERR]`
- [x] `testCaseIsPreserved` — `'ABCDEF'` → `'#ABCDEF'` `[ERR]`
- [x] `testWhitespaceIsNotTrimmed` — `' abc'` → `false` `[ERR]`

**Cross-function property:**
- [x] `testCheckColorOutputNeverMakesGetColorTextThrow` — for every accepted input, feeding the result to `get_color_text()` yields `#000` or `#fff` `[ECP]`

#### `get_typetag_id($input)` — regex branch only — `tests/Unit/GetTypetagIdTest.php`

The `'|'` branch touches the database and is covered at the integration layer; only the pure paths belong here.

- [x] `testMarkerFormReturnsId` — `'~~123~~'` → `'123'` `[HAPPY]`
- [x] `testZeroId` — `'~~0~~'` → `'0'` `[BVA]`
- [x] `testEmptyMarkerRejected` — `'~~~~'` → `false` `[BVA]`
- [x] `testNonNumericMarkerRejected` — `'~~12a~~'` → `false` `[NEG]`
- [x] `testWhitespaceInMarkerRejected` — `'~~ 12 ~~'` → `false` `[NEG]`
- [x] `testAnchoringIsEnforced` — `'~~123~~x'` → `false` `[BVA]`
- [x] `testPlainStringReturnsFalse` — `'plain'`, `''` → `false` `[ECP]`

#### `typetags_partition_tags()` — `tests/Unit/PartitionTagsTest.php`

Zero-One-Many across both inputs:

- [x] `testNoColouredTags` — `[]`, `[]` → both lists empty `[BVA]`
- [x] `testOneColouredNoneAssigned` — 1 unassigned, 0 assigned `[BVA]`
- [x] `testOneColouredAndAssigned` — 0 unassigned, 1 assigned `[BVA]`
- [x] `testManyColouredNoneAssigned` — drives State C `[HAPPY]`
- [x] `testManyColouredAllAssigned` — unassigned empty; drives State B and box 516 `[BVA]`
- [x] `testManyColouredSomeAssigned` — drives State A `[HAPPY]`
- [x] `testAssignedIdsContainingNonColouredTagsAreIgnored` — a plain tag id in `$assigned_ids` must not appear in either output; drives State D and box 557 `[NEG]`
- [x] `testPartitionIsCompleteAndDisjoint` — invariant: `unassigned ∪ assigned == all_colored`, intersection empty `[ECP]`
- [x] `testColorTextIsAddedToUnassignedOnly` — every unassigned entry carries `color_text`; assigned ids are bare `[HAPPY]`
- [x] `testStringAndIntegerIdsBothMatch` — `'5'` and `5` both resolve; records the loose `in_array` `[ERR]`
- [x] `testInputOrderIsPreserved` — the query orders by name; the partition must not reorder `[ST]`

#### Structural guards — `tests/Unit/TemplateContractTest.php`

- [x] `testPictureTemplateStillContainsBothPrefilterTargets` — each search string occurs exactly once `[ERR]`
- [x] `testNoChildThemeShadowsPictureTemplate` — `themes/modus/template/picture.tpl` absent `[ERR]`
- [x] `testGuardFixtureIsNotVacuous` — the template file exists and exceeds 1000 bytes, so a moved or emptied template fails loudly instead of matching zero times `[ERR]`

*These are the "assert what the compiler does not watch" tests. Without them, a theme change or upstream merge removes the feature with no error anywhere.*

### Integration Tests

#### `AddTagTest.php`

**Happy path:**
- [x] `testAssignsColouredTag` — `stat: ok`, row present `[HAPPY]`

**Negative:**
- [x] `testGuestIsRejected` — 401 `[NEG]`
- [x] `testBadTokenIsRejected` — 403 `[NEG]`
- [x] `testEmptyTokenIsRejected` — **1002, not 403**: ws.php treats `''` as absent `[BVA]`
- [x] `testMissingTokenParameterIsRejected` — WS missing-param error (1002) `[BVA]`
- [x] `testNonColouredTagIsRejected` — 404 `[NEG]`
- [x] `testNonexistentTagIsRejected` — `MAX(id)+1000` → 404 `[NEG]`
- [x] `testZeroTagIdIsRejected` — **1003, not 404**: `WS_TYPE_ID` rejects before dispatch `[BVA]`
- [x] `testNegativeTagIdIsRejected` — `WS_TYPE_ID` rejects (1003) `[BVA]`
- [x] `testZeroImageIdIsRejected` / `testNegativeImageIdIsRejected` — same boundary on the other id `[BVA]`
- [x] `testNonexistentImageIsRejected` — 404 `[NEG]` **(failed before Phase 2 fix)**
- [x] `testNonexistentImageWritesNoOrphanRow` — zero rows in `piwigo_image_tag` `[NEG]` **(failed before fix)**

**State transition / idempotency:**
- [x] `testDuplicateAddIsIdempotent` — second call `ok`, and `COUNT(*) == 1` (stronger than the original presence check) `[ST]`

**Characterization (oracle is the code — no requirement confirms these):**
- [x] `testMethodAlsoAnswersToGet` — `post_only` is not set; records current behaviour so a future change is visible `[ERR]`

#### `RemoveTagTest.php`
- [x] `testRemovesAssignedTag` — `stat: ok`, row gone `[HAPPY]`
- [x] `testGuestIsRejected` / `testBadTokenIsRejected` / `testNonColouredTagIsRejected` — each also asserts the row survived the rejection `[NEG]`
- [x] `testNonexistentTagIsRejected` — gap: only `addTag` was tested `[NEG]`
- [x] `testRemoveWhenNotAssignedIsIdempotent` — `ok`, zero rows `[ST]`
- [x] `testRoundTrip` — unassigned → assigned → unassigned, DB verified at each step `[ST]`

#### `CacheInvalidationTest.php`
- [x] `testAddNullsAvailableTagCount` — with an anti-vacuity guard asserting the value was non-null *before* the call `[ST]`
- [x] `testRemoveNullsAvailableTagCount` — gap: only `addTag`'s was tested `[ST]`
- [x] `testCacheIsRestoredAfterRun` — the deleted script left `nb_available_tags` mutated `[ERR]`

#### `PicturePageSourceTest.php`
- [x] `testPageReturnsTwoHundredForLoggedInUser` `[HAPPY]`
- [x] `testPageHasNoFatalError` / `testPageHasNoSmartyCompilerError` `[NEG]`
- [x] `testExactlyOneScriptBlockIsInjected` — regression guard for the duplicate-injection fix `[ERR]`
- [x] `testUnassignedBadgeCountMatchesFixture` — exactly *K* add badges for a forced *K*; **replaces the unconditionally-true assertion** `[ECP]`
- [x] `testFixtureProducesAtLeastOneUnassignedTag` — anti-vacuity: the count test cannot pass over zero `[ERR]`
- [x] `testAllAssignedRendersNoUnassignedSection` — State B server-side: the container is not rendered at all `[BVA]`
- [x] `testAssignedColouredTagsRenderAsTaggedAnchors` — one `a[data-tag-id]` per assigned colored tag; **replaces the tautological assertion**, asserting what page source can actually witness `[HAPPY]`
- [x] `testGuestSeesNoAssignmentUi` — 200, no `Fatal error`, and neither `typetags-unassigned` nor `typetag-add` `[NEG]`
- [x] `testImageWithNoTagsRendersNoTagsRow` — `#Tags` absent; proves State C's precondition is real `[BVA]`

All four element-presence assertions scan the page **with `<script>` blocks stripped**. The injected JavaScript builds both `#Tags` and `#typetags-unassigned` as string literals, so a raw-body scan finds the JS copy and reports an element the server never rendered — this was caught by two tests failing on their first run. `assertMarkupSurvivedStripping()` is the anti-vacuity guard on the stripping itself.

#### `ColorHelperCallersTest.php` — the other callers of the colour helpers
- [x] `testAdminTagsPageRenders` — `admin.php?page=tags` returns 200 with no fatal; `typetags_admin()` calls `get_color_text()` per colour `[HAPPY]`
- [x] `testTypeAddReturnsContrastColour` — `typetags.type.add` normalises `AABBCC` → `#AABBCC` and returns `color_text` `#000` `[HAPPY]`

### End-to-End Tests

Mapped one-to-one onto the unticked boxes in Plan B. Box numbers are that file's line numbers.

#### `assign.spec.js` — add flow
- [x] `unassigned badges render at reduced opacity with a plus prefix` — box 514 `[HAPPY]`
- [x] `clicking an unassigned badge moves it into the Tags row at full opacity` — box 537 `[HAPPY]`
- [x] `a remove button appears on the newly assigned tag` — box 538 `[HAPPY]`
- [x] `the badge disappears from the unassigned list` — box 539 `[ST]`
- [x] `the unassigned section hides when the last tag is assigned` — box 540, State B `[BVA]`
- [x] `the Tags row is created when the image had no tags` — box 541, State C `[BVA]`
- [x] `the assignment survives a page reload` — box 542 `[ST]`

#### `remove.spec.js` — remove flow
- [x] `assigned coloured tags show a remove button` — box 515 `[HAPPY]`
- [x] `clicking it removes the tag from the Tags row` — box 545 `[HAPPY]`
- [x] `the tag reappears in the unassigned list at reduced opacity` — box 546 `[ST]`
- [x] `the Tags row hides when the last tag is removed` — box 547 `[BVA]`
- [x] `the unassigned section is recreated when it had been hidden` — box 548, State B `[BVA]`
- [x] `the removal survives a page reload` — box 549 `[ST]`
- [x] `add then remove returns the page to its starting state` — round trip `[ST]`

#### `edge-cases.spec.js`
- [x] `double-clicking issues exactly one request` — asserted by counting intercepted POSTs, not by eyeballing the UI — box 552 `[ERR]`
- [x] `a network failure leaves the tag in place and the badge clickable` — `route.abort()` — box 553 `[NEG]`
- [x] `a server rejection leaves the badge clickable` — `route.fulfill()`, HTTP 200 + `stat:"fail"` — **no existing box; this is the Phase 2 defect's signature** `[NEG]`
- [x] `comma separators render between multiple assigned tags` — box 554 `[HAPPY]`
- [x] `comma separators clean up with no leading or trailing comma` — box 555 `[BVA]`
- [x] `an image with only non-coloured tags shows no remove buttons` — box 557, State D `[NEG]`
- [x] `the modus theme renders both sections correctly` — box 517 `[HAPPY]`

#### `rendering.spec.js` — what the browser actually paints

Added during Phase 4's `/verify` to automate the two manual boxes. Maps to no Plan B box: the
checklist asked a human to look at the page, and these assert the part of "looks right" that has
an oracle. Expected colours are read from `piwigo_typetags` through the seeding CLI, never typed
into a spec.

- [x] `every unassigned badge paints its configured colour at a real size` — whole palette on one
  page via the `no-tags` fixture; computed `background-color` per badge, text colour is black or
  white, bounding box above a named minimum `[ECP]` `[BVA]`
- [x] `every assigned badge paints its configured colour at a real size` — the same over
  `all-assigned`, exercising `typetags_render()` rather than the prefilter `[ECP]`
- [x] `a badge assigned in the browser is painted like a server-rendered one` — the JS string
  literal and the PHP renderer are two independent implementations of one badge; click, read the
  paint, reload, assert they agree `[ST]`
- [x] `the assignment UI initialises with no console or page errors` — with an anti-vacuity guard
  asserting the badges and remove buttons exist first `[NEG]`

*State transition applies only to the third case; the others are pure render checks.*

### Regression — Affected Existing Functionality

The partition extraction (Phase 1) and the `get_color_text` guard (Phase 2) are both touched by code well outside this feature:

- [x] `typetags_render()` calls `get_color_text()` on every tag on every public page — covered by `PicturePageSourceTest` and `MalformedColorRenderingTest`; the E2E runs add the visual half in Phase 4
- [x] `typetags_admin()` calls `get_color_text()` for the admin tags page — `ColorHelperCallersTest::testAdminTagsPageRenders`
- [x] `ws_typetags_type_add()` calls both `check_color()` and `get_color_text()` — `ColorHelperCallersTest::testTypeAddReturnsContrastColour`
- [x] `typetags_picture_tags()` is the sole caller of the extracted partition — the integration suite is its net; ran before and after the extraction with no diff (Phase 1)

### Mutation Testing

Per the decision settled 2026-08-28, mutation applies to **unit tests only** — never to an integration, E2E, or structural guard test, because a red end-to-end run does not say which mutation caused it. Kept as prose, not as a script: a script that patches and reverts source is a second thing to keep correct.

- [x] Record a mutant table for the unit suite: mutant → killed by. Minimum set:
  - `$l > 0.45` → `$l >= 0.45` — should be killed by the BVA pair, and if it is *not*, that proves the threshold is unreachable rather than that the test is weak; record which
  - `$l > 0.45` → `$l > 0.5` — killed by the palette test
  - `strlen($color) == 7` → `>= 7` — killed by the malformed-length test
  - `return '#000'` (the new guard) → `return '#fff'` — killed by the malformed-length test
  - `in_array($tag['id'], $assigned_ids)` → `!in_array(...)` — killed by the partition tests
  - `substr_count(...) === 1` → `>= 1` in the template guard — killed by nothing today; record the gap honestly rather than claiming coverage
- [x] Record what did **not** move. The phrasing matters: *"Nothing else moved"* is the claim that a mutant killed exactly the tests that watch it. A mutant too weak to kill proves nothing about the test and is recorded as such, not quietly replaced with an easier one.
- [x] Where a mutant is expected to be killed and is not, record which — an unkillable `$l >= 0.45` mutant is evidence the threshold is unreachable, not evidence the test is weak. Both readings are findings; guessing between them is not.

### Fixture Provenance

The current fixtures are `SELECT ... LIMIT 1` off live data, so what a test asserts depends on whatever the database happens to hold that day. Each scenario fixture gets a recorded purpose, so a later reader can tell what case it covers and whether a new one would be redundant:

| Fixture | What case it covers | How it is built |
|---|---|---|
| `someAssignedSomeUnassigned` | State A — the only state covered today | forces ≥1 assigned and ≥1 unassigned colored tag |
| `allColoredAssigned` | State B — boxes 516, 540, 548 | assigns every colored tag to the image |
| `imageWithNoTags` | State C — boxes 541, 556; `#Tags` absent entirely | strips every tag from a dedicated image |
| `onlyNonColoredTags` | State D — box 557 | assigns only `id_typetags IS NULL` tags |

Rule for adding one: **cover a case that actually differs.** A second fixture with a different tag name proves nothing the first does not.

### Hand-Check Ledger

For behaviour no automated layer reaches. Each entry records the date, what was checked, and — once something automates it — which test replaced it, so the ledger shrinks rather than accumulating.

| Date | Checked by hand | Replaced by |
|---|---|---|
| 2026-08-28 | Picture page renders identically after the Phase 1 partition extraction (headed browser, logged in as `chriss`, `picture.php?/1/category/1`: "Personen ×" assigned badge, 7 correctly-coloured unassigned badges, 0 console errors). Confirmed by the user. | Not replaceable as-is — it was a before/after comparison and the "before" no longer exists. Ongoing rendering is covered by `MalformedColorRenderingTest` and, from Phase 4, the E2E specs. |
| 2026-08-28 | A server-side rejection (HTTP 200 + `stat:"fail"`) leaves the badge clickable and logs a warning. Mocked via `route.fulfill()` in a headed browser; verified red before the fix (`pointer-events: none` forever, no console output) and green after (`pointer-events: auto`, `typetags: Invalid security token`). | **Replaced 2026-08-29** by `edge-cases.spec.js` → `a server rejection leaves the badge clickable`, which was itself watched failing against the reverted fix |
| 2026-08-29 | Modus rendering compared against the 2026-04-27 reference screenshots. Structure and palette match (`×` nested inside the badge pill; `#FFFFB6` / `#FFCA4F` / `#BE6CB7`); the only difference is modus's dark colour scheme in the older capture. | Superseded by `rendering.spec.js` — the four specs assert computed colour and geometry on every run, which is what the comparison was for. Not kept as a screenshot baseline (pixel diffing a photo gallery is flaky for unrelated reasons). |
| *(further entries added during Phase 5's `/verify` run)* | | |

### Manual Testing Steps

Only what no layer can reach. Everything else is automated by `/verify` in Phase 5 and moves out of this list.

1. Visual check that badge contrast is legible for all 8 configured colours against the modus theme background — subjective, no oracle.
2. Confirm the hover opacity transition feels right — subjective.
3. Confirm hook runtime does not make committing feel slow — subjective.

Anything else `/verify` reports as non-automatable joins the dated hand-check ledger in `docs/agents/TESTING.md`, recording the date, who checked, and which automated test has since replaced it (if any).

### Test Commands

```bash
# Unit — fast, no stack needed
ddev exec vendor/bin/phpunit --testsuite unit --configuration plugins/typetags/phpunit.xml

# Integration — needs DDEV up
ddev exec vendor/bin/phpunit --testsuite integration --configuration plugins/typetags/phpunit.xml

# E2E — needs DDEV up and browsers installed (or host runner, per Phase 0)
ddev exec bash -c 'cd plugins/typetags && npx playwright test'

# Full suite
ddev exec vendor/bin/phpunit --configuration plugins/typetags/phpunit.xml && \
  ddev exec bash -c 'cd plugins/typetags && npx playwright test'

# Syntax parity check (container PHP 8.4, not host 8.5)
ddev exec php -l plugins/typetags/include/functions.inc.php

# Hook self-test
bash tools/test-hooks.sh
```

## Migration Notes

- **`test_ws_tag_assignment.php` is deleted, not deprecated.** Its 25 assertions each get a named successor, and the mapping goes in the commit message. Keeping both would mean two definitions of the truth.
- **`plugins/typetags` gains two dependency managers.** `vendor/` and `node_modules/` are git-ignored via a `.gitignore` that must be created first — the submodule has none today. A fresh clone needs `composer install` and `npm install` before tests run; this goes in CLAUDE.md.
- **CLAUDE.md's "no dependency manager" claim becomes false** in Phase 0 and must be corrected in the same commit that makes it untrue.
- **The database is mutated by the integration and E2E suites.** Fixtures record and restore, but the suites are not safe against a production database and never will be. This is stated in `docs/agents/TESTING.md` rather than assumed.
- **No schema change.** `maintain.class.php` is untouched; `install()` is already idempotent.

## References

- [Research: plan implementation status and test coverage](../research/2026-08-28-plan-implementation-and-test-coverage.md) — the audit this plan acts on
- [Research: picture page tag assignment](../research/2026-04-24-picture-page-tag-assignment.md) — `:256` Design Decisions, incl. the recorded permission model
- [Plan A: install colored tags plugin](2026-04-19-install-colored-tags-plugin.md) — 3 manual boxes to close
- [Plan B: picture page tag assignment](2026-04-27-picture-page-tag-assignment.md) — 30 manual boxes to close
- `plugins/typetags/include/functions.inc.php:4-27` — `get_color_text`, the `TypeError`
- `plugins/typetags/include/events_public.inc.php:156-175` — partition logic to extract
- `plugins/typetags/include/events_public.inc.php:244-285`, `:305-359` — the missing `else` branches
- `plugins/typetags/main.inc.php:189-228` — `addTag`, missing image validation
- `plugins/typetags/tests/test_ws_tag_assignment.php:379`, `:388` — the two vacuous assertions
- `themes/default/template/picture.tpl:210` — the conditional `#Tags` div (State C's cause)
- `themes/default/template/picture.tpl:214`, `:303` — the prefilter's two search strings
- `install/piwigo_structure-mysql.sql:208` — `PRIMARY KEY (image_id, tag_id)`, the idempotency mechanism
- `~/.claude/skills/given-when-then/` and `~/.claude/skills/review-user-story/references/testing-heuristics.md` — available in every project; Phase 5 decides whether to reference them from here

### Reference repositories

The conventions in Phase 5 are adapted from the two external reference repositories studied in the research document, which keeps them anonymised — as does this plan. Both run these mechanisms in practice; neither is a dependency, and nothing is copied as code. See [the research document](../research/2026-08-28-plan-implementation-and-test-coverage.md), Part 3 and the follow-up section, for what each contributes:

- **Reference A** — the mutation-scope rule, the pyramid placement rule and the anti-regression cross-check; the structural guard tests with the anti-vacuity floor pattern; the ratcheted pre-commit hook with its version-controlled installer and its self-test.
- **Reference B** — the seven-tag legend and the Testing Strategy section's structure; the mutant tables and the "mutants stay prose" decision; the fixture manifest and the hand-check ledger.
