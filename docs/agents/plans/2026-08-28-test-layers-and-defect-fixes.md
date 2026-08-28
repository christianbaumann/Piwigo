---
date: 2026-08-28T18:36:05.253965+00:00
git_commit: 317f34b6ec41528666225e8bc1cdde2d3ad4d858
branch: master
topic: "Test pyramid for typetags: unit layer, hardened integration layer, E2E layer, and the defects they expose"
tags: [plan, typetags, testing, phpunit, playwright, test-design, quality-gate]
status: draft
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

#### [ ] 1. Test bootstrap
**File**: `plugins/typetags/tests/bootstrap.php` (new)
```php
<?php
// functions.inc.php guards on TYPETAGS_PATH and then only declares functions,
// so it loads with no database and no Piwigo core.
define('TYPETAGS_PATH', dirname(__DIR__) . '/');
define('PIWIGO_ROOT', dirname(dirname(dirname(__DIR__))) . '/');
require_once TYPETAGS_PATH . 'include/functions.inc.php';
```

#### [ ] 2. Extract the partition logic so it can be tested at the unit layer
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

#### [ ] 3. Unit tests — written before the Phase 2 fix, and watched to fail
**Files**: `tests/Unit/GetColorTextTest.php`, `CheckColorTest.php`, `GetTypetagIdTest.php`, `PartitionTagsTest.php`, `TemplateContractTest.php`

The full case list is enumerated in *Testing Strategy* below.

#### [ ] 4. Structural guard for the prefilter's template coupling
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

#### [ ] 5. Structural guard: the search strings are not transcribed twice
**File**: `tests/Unit/TemplateContractTest.php`
**Changes**: the guard above hardcodes the same two strings the prefilter hardcodes — a second copy that rots independently. Reference A's rule is *do not transcribe the production list into the test; read it from production*. Extract both search strings into named constants in `events_public.inc.php` and have the guard read those constants, so one edit moves both.
```php
// events_public.inc.php
define('TYPETAGS_TPL_TAG_ANCHOR', '<a href="{$tag.URL}">{$tag.name}</a>');
define('TYPETAGS_TPL_INJECT_POINT', '{if isset($metadata)}');
```

### Success Criteria:

#### Automated Verification:
- [ ] `ddev exec vendor/bin/phpunit --testsuite unit --configuration plugins/typetags/phpunit.xml` — all green
- [ ] The unit suite runs in under 1 second
- [ ] `ddev exec php -l plugins/typetags/include/functions.inc.php` and `include/events_public.inc.php` clean
- [ ] Integration script still passes after the partition extraction (regression net)
- [ ] Each new test was observed to fail before its implementation existed (recorded in the mutant/failure table)

#### Manual Verification:
- [ ] Picture page renders identically before and after the partition extraction

**Implementation Note**: Run `/verify`. Pause before Phase 2.

---

## Phase 2: Product Defect Fixes

### Overview
Three confirmed defects, each fixed only after a test reproduces it. Per the user CLAUDE.md rule, the reproducing test comes first and production/test code are edited in separate cycles.

### Changes Required:

#### [ ] 1. `get_color_text()` — `TypeError` on malformed hex
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

#### [ ] 2. `addTag` — unvalidated `image_id` writes orphan rows
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

#### [ ] 3. Non-`ok` response leaves the badge permanently dead
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
- [ ] `GetColorTextTest::testMalformedLengthReturnsSafeDefault` fails before the fix, passes after
- [ ] `AddTagTest::testNonexistentImageIsRejected` fails before the fix, passes after
- [ ] `AddTagTest::testNonexistentImageWritesNoOrphanRow` confirms zero rows in `piwigo_image_tag`
- [ ] E2E `edge-cases.spec.js` server-error case fails before the fix, passes after
- [ ] Full suite green; `php -l` clean on all three changed files

#### Manual Verification:
- [ ] With a deliberately corrupted `typetags.color` value, the picture page renders instead of white-screening
- [ ] Rejecting a request server-side leaves the badge clickable again

**Implementation Note**: Run `/verify`. Pause before Phase 3.

---

## Phase 3: Integration Layer — Port and Harden

### Overview
Move the 447-line script into PHPUnit, delete the two vacuous assertions, and replace `LIMIT 1`-off-live-data fixtures with a builder that forces and then *asserts* a known state. The existing script is deleted, not kept alongside — keeping both would mean two things to maintain and two definitions of the truth.

### Changes Required:

#### [ ] 1. Configuration — no hardcoded credentials
**File**: `tests/Support/Config.php` (new)
**Changes**: environment variables with DDEV defaults, and a fail-fast message naming the missing piece. Replaces the hardcoded `chriss`/`test123`, `mysqli('db','db','db','db')`, and `http://localhost/ws.php`.

#### [ ] 2. Fixture builder — setup-before, not cleanup-after
**File**: `tests/Support/FixtureBuilder.php` (new)
**Changes**: each scenario *forces* its preconditions and asserts they took effect, so a test never runs over a state it merely hoped for. Cleanup restores, but no assertion depends on cleanup having run — the reference rule is that cleanup is skipped on failure and destroys the failure evidence.

Scenarios needed by the four UI states: `someAssignedSomeUnassigned()`, `allColoredAssigned()`, `imageWithNoTags()`, `onlyNonColoredTags()`. Each records the original assignment set for that image and restores it.

Also restores `piwigo_user_cache.nb_available_tags`, which the current script mutates at `:317` and never puts back.

#### [ ] 3. Replace the two vacuous assertions
**File**: `tests/Integration/PicturePageSourceTest.php` (new)
**Changes**:
- The `|| true` at `:379` becomes a **counted** assertion against a forced fixture: given exactly *K* unassigned colored tags, the page source contains exactly *K* `typetag-add` spans — with an anti-vacuity guard asserting `K > 0` first, so the count cannot pass over zero.
- The tautological `:388` is **deleted**, not repaired. What it claimed (the × button renders) is a DOM fact that no page-source assertion can reach; it moves to E2E. What page source *can* assert is that `#Tags` contains one `a[data-tag-id]` per assigned colored tag — that becomes a real assertion in its place.

#### [ ] 4. Port the remaining assertions, closing the gaps found
**Files**: `tests/Integration/AddTagTest.php`, `RemoveTagTest.php`, `CacheInvalidationTest.php`
**Changes**: all 25 original assertions plus the gaps the coverage map exposed — `removeTag` with a nonexistent tag (only `addTag` was tested), `removeTag`'s cache invalidation (only `addTag`'s was), duplicate-add asserting `COUNT = 1` rather than mere presence, and `tag_id`/`image_id` boundary values.

#### [ ] 5. Delete the superseded script
**File**: `plugins/typetags/tests/test_ws_tag_assignment.php` — deleted once every assertion has a named successor. A mapping table goes in the commit message so nothing is lost silently.

### Success Criteria:

#### Automated Verification:
- [ ] `ddev exec vendor/bin/phpunit --testsuite integration --configuration plugins/typetags/phpunit.xml` — all green
- [ ] Assertion count ≥ 25 (nothing lost in the port)
- [ ] Suite passes twice in a row without manual DB repair (fixtures are self-restoring)
- [ ] Suite passes when run with the tests in reverse order (no inter-test dependency)
- [ ] `piwigo_user_cache` and `piwigo_image_tag` are byte-identical before and after a full run
- [ ] `grep -rn '|| true' plugins/typetags/tests/` returns nothing

#### Manual Verification:
- [ ] Reviewing the mapping table confirms every original assertion has a successor

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

### Changes Required:

#### [ ] 1. Page object — the only place locators live
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

#### [ ] 2. Scenario seeding, reusing the PHPUnit fixture builder
**File**: `tests/e2e/support/seed.php` (new)
```bash
php tests/e2e/support/seed.php --scenario=all-assigned --image=1
```
Called from `beforeEach`. Setup-before, and it prints the state it achieved so a spec can assert the fixture is what it claims.

#### [ ] 3. Specs
**Files**: `assign.spec.js`, `remove.spec.js`, `edge-cases.spec.js` — case list in *Testing Strategy*.

Two cases deserve calling out because they distinguish the defect from its lookalike:
- **Network failure** (`route.abort()`) → jQuery's `error` callback fires, badge is re-enabled. This already worked; it is Plan B box 553.
- **Server rejection** (`route.fulfill()` with HTTP 200 and `{"stat":"fail","err":403}`) → lands in `success`, and before the Phase 2 fix leaves the badge permanently dead. No existing box covers this; it is the defect's real signature.

#### [ ] 4. No retries, no parallelism
**File**: `tests/e2e/playwright.config.js`
**Changes**: `retries: 0`, `workers: 1`, tracing on. A flaky test gets fixed or made deterministic — never retried into green, never disabled. Waits are on events and locator state, never on a bare timeout.

### Success Criteria:

#### Automated Verification:
- [ ] `npx playwright test` — all green, exit 0
- [ ] Passes three consecutive runs with no retries configured (no flake)
- [ ] Every one of the 23 mapped checklist items has a named spec
- [ ] `grep -rnE "locator\(|querySelector" tests/e2e/*.spec.js` returns nothing (locators stayed in the page object)
- [ ] Traces are written for each test

#### Manual Verification:
- [ ] Watching one run headed confirms the assertions describe what actually happens on screen
- [ ] Rendering under the modus theme matches the screenshots in `.agent-tests/`

**Implementation Note**: Run `/verify`. Pause before Phase 5.

---

## Phase 5: Conventions and Plan Closure

### Overview
Make the conventions this plan already uses explicit, and close out the two plans left in `draft` with 33 unticked boxes.

### Changes Required:

#### [ ] 1. State the technique legend once
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

#### [ ] 1b. Record deliberate non-coverage
**File**: `docs/agents/TESTING.md`
**Changes**: a **Tests NOT required (with justification)** table, in the same technique vocabulary, so a later reader can tell a considered omission from an oversight.

| Component | Why no test | Technique rationale |
|---|---|---|
| `check_color()`'s DB branch in `get_typetag_id()` | Covered at the integration layer by `typetags.type.add` | Duplicating it at the unit layer adds no equivalence class |
| `typetags_escape_prefilter()` | One `str_replace`, no branching | No partition and no boundary to sit on |
| `typetags_tags()` letters/cloud/cumulus modes | Not touched by this feature | Outside the change's blast radius |
| `removeTag` image validation | A `DELETE` on a nonexistent image is already a no-op | No reachable defect; the asymmetry with `addTag` is deliberate and commented |
| Concurrent add/remove from two tabs | The `PRIMARY KEY` makes both operations idempotent | Testing it would assert the database's behaviour, not the plugin's |

#### [ ] 2. Decision log
**File**: `docs/agents/decisions/` (new directory)
**Changes**: numbered decisions this plan already depends on, so later work can cite rather than re-litigate them:
- `0001-mutation-testing-unit-only.md` — settled 2026-08-28
- `0002-e2e-runner-location.md` — the Phase 0 gate's outcome and why
- `0003-no-post-only-on-ws-methods.md` — with the CSRF reasoning
- `0004-unscoped-tag-cache-invalidation-accepted.md`
- `0005-tag-assignment-permission-model.md` — records "all logged-in users", and explicitly names the unexamined question about images in non-browsable categories

#### [ ] 3. Close out the two existing plans via `/verify`
**Files**: `docs/agents/plans/2026-04-19-install-colored-tags-plugin.md`, `docs/agents/plans/2026-04-27-picture-page-tag-assignment.md`
**Changes**: run `/verify` against each. Its job is to automate the manual steps, run them, fold them into the regression suite, fix what fails, list what cannot be automated, and update plan status. Concretely:
- **Plan A** (3 manual boxes + 3 step headings): plugin active, config page loads, tag colors configurable — all three are assertable against the database and over HTTP.
- **Plan B Phase 1** (7 boxes): already covered by the integration suite; each gets ticked with a reference to the test that covers it.
- **Plan B Phase 2** (6 boxes): 2 covered by integration, 4 by E2E.
- **Plan B Phase 3** (17 boxes): all by E2E.
- Anything `/verify` reports as non-automatable goes into a dated hand-check ledger in `docs/agents/TESTING.md` — nothing gets ticked on prose alone.
- Flip both frontmatters off `draft` only once every box is either ticked-with-a-reference or in the ledger.

#### [ ] 4. Update CLAUDE.md
**File**: `CLAUDE.md`
**Changes**: the Testing section currently states "Piwigo core has no test suite (no PHPUnit)" and names two mechanical checks. Replace with the command table from *Desired End State*, note that `plugins/typetags` now carries composer and npm dev dependencies, and correct the "no dependency manager" claim, which this plan makes untrue. Per the reference repositories' meta-rule: when something in the instructions stops being true, fix it in the commit that made it untrue.

### Success Criteria:

#### Automated Verification:
- [ ] `docs/agents/TESTING.md` exists and the legend appears in exactly one file: `grep -rl '\[BVA\].*boundary' docs/ | wc -l` returns 1
- [ ] Neither plan file contains `status: draft`
- [ ] No unticked `- [ ]` remains in either plan file that is not cross-referenced in the ledger
- [ ] CLAUDE.md contains no stale "no PHPUnit" / "no dependency manager" claim

#### Manual Verification:
- [ ] Each newly ticked box names the test that covers it, and spot-checking three of them confirms the test genuinely asserts that behaviour

**Implementation Note**: Run `/verify`. Pause before Phase 6.

---

## Phase 6: Commit Gate (pre-commit hook)

### Overview
A ratchet, not a wall: block new breakage, leave existing state alone, and stay bypassable. The gate exists only because Phases 1–4 created a real command to gate on.

### Changes Required:

#### [ ] 1. The hook
**File**: `.githooks/pre-commit` (new, version-controlled)
**Changes**:
- `php -l` on staged `*.php` only (host PHP for speed; the container is the parity check, run separately)
- the **unit suite only** — it is sub-second and needs no DDEV. Integration and E2E stay out: they need the stack up, and a hook that fails when Docker is down is a hook people disable.
- if DDEV is down, `php -l` still runs and the suite is skipped **with a printed warning**, never silently
- `--no-verify` bypasses, by design

#### [ ] 2. Install for BOTH repositories
**File**: `tools/install-hooks.sh` (new)
**Changes**: `core.hooksPath` on the superproject alone does **not** cover submodule commits, and every plugin commit is a submodule commit — so the hook would silently never run on the commits that matter. The installer configures both:
```bash
git config core.hooksPath .githooks
git -C plugins/typetags config core.hooksPath "$(pwd)/.githooks"
```

#### [ ] 3. Self-test for the hook — the hook's own anti-vacuity check
**File**: `tools/test-hooks.sh` (new)
**Changes**: a hook nobody has watched fail is a hook nobody knows works, and a hook that silently stops blocking is worse than none. The self-test stages throwaway probes, runs the hook, asserts the exit code, then unstages and deletes — it never creates a commit. It needs **two red probes and one green**, so it proves the hook can both block and let through.

```bash
run_case "syntax error blocks"   1 "$probe_dir/probe_broken.php"
run_case "vacuous assertion blocks" 1 "$probe_dir/probe_vacuous.php"   # a staged '|| true'
run_case "clean file passes"     0 "$probe_dir/probe_clean.php"
```

The second probe is the ratchet that matters most here: the defect this whole plan started from was an assertion ending in `|| true`. Grep **added lines only** (`git diff --cached -U0 | grep '^+'`), so pre-existing code is grandfathered and only new occurrences are blocked. A `trap` on `EXIT`/`INT`/`TERM` restores the tree even when the run is interrupted.

#### [ ] 4. Guard against the mutation that changes nothing
**File**: `tools/test-hooks.sh`
**Changes**: if a probe pattern stops matching what the hook looks for, the self-test passes over nothing and proves nothing. Assert each probe file actually differs from a clean baseline before running the hook on it — the designed failure is *"probe changed nothing"*, reported red rather than silently green.

### Success Criteria:

#### Automated Verification:
- [ ] `bash tools/test-hooks.sh` passes all three cases (two red, one green)
- [ ] `git -C plugins/typetags config --get core.hooksPath` is set
- [ ] A commit in the submodule with a PHP syntax error is rejected
- [ ] A staged new `|| true` inside a test file is rejected; an existing one elsewhere is not
- [ ] `git commit --no-verify` bypasses
- [ ] With Docker stopped, the hook still runs `php -l` and prints the skip warning rather than passing silently
- [ ] Deleting the hook's grep pattern makes `test-hooks.sh` go red (the self-test has teeth)

#### Manual Verification:
- [ ] The hook was watched failing once by hand before being trusted

**Implementation Note**: Run `/verify`. Then Phase 7.

---

## Phase 7: Push the Submodule

### Overview
The submodule is 3 commits ahead of `origin/master` (`e07139f`, `7dc69fc`, `9974177`) plus everything this plan adds. Until it is pushed, a fresh clone of the superproject fetches a pointer to commits the remote does not have — the superproject is unbuildable for anyone but this machine.

### Changes Required:

#### [ ] 1. Push the plugin
```bash
git -C plugins/typetags push origin master
```

#### [ ] 2. Bump the superproject pointer and commit
```bash
git add plugins/typetags
git commit -m "bump typetags submodule: test pyramid and defect fixes"
```

### Success Criteria:

#### Automated Verification:
- [ ] `git -C plugins/typetags status` reports no commits ahead of origin
- [ ] A clone into a temp dir with `--recurse-submodules` checks out cleanly and the unit suite passes there
- [ ] `git submodule status` shows no `+` or `-` prefix

#### Manual Verification:
- [ ] The three pre-existing commits are on the remote and the plugin still installs from a clean checkout

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
- [ ] `testSevenCharLightColourGetsBlackText` — `'#FFFFB6'` → `'#000'` `[HAPPY]`
- [ ] `testSevenCharDarkColourGetsWhiteText` — `'#007DAD'` → `'#fff'` `[HAPPY]`
- [ ] `testFourCharShorthandIsSupported` — `'#fff'` → `'#000'`, `'#000'` → `'#fff'` `[ECP]`
- [ ] `testAllConfiguredPaletteColoursResolve` — the 8 live colours, none throws, each returns `#000` or `#fff` `[HAPPY]`

**Boundary values** (threshold `$l > 0.45`):
- [ ] `testThresholdJustBelowGetsWhiteText` — `'#00E500'`, `l = 0.449020` → `'#fff'` `[BVA]`
- [ ] `testThresholdJustAboveGetsBlackText` — `'#00E600'`, `l = 0.450980` → `'#000'` `[BVA]`
- [ ] `testThresholdIsUnreachableOnEightBitChannels` — documents that `l == 0.45` requires `min+max = 229.5`; asserts no `#RRGGBB` produces exactly `0.45` `[BVA]`
- [ ] `testFourCharThresholdBoundary` — `'#0d0'` → `'#fff'`, `'#0e0'` → `'#000'` `[BVA]`
- [ ] `testExtremes` — `'#000000'` → `'#fff'`, `'#ffffff'` → `'#000'` `[BVA]`

**Negative and edge:**
- [ ] `testMalformedLengthReturnsSafeDefault` — `'#12345'`, `'#'`, `'ab'`, 1000-char string → `'#000'`, no throw `[NEG]` **(fails before Phase 2 fix)**
- [ ] `testEmptyReturnsEmptyString` — `''` → `''` `[ECP]`
- [ ] `testZeroStringIsTreatedAsEmpty` — `'0'` → `''`; records the `empty()` quirk `[ERR]`
- [ ] `testNullIsTreatedAsEmpty` — `null` → `''` `[ERR]`
- [ ] `testNonHexOfCorrectLengthDoesNotThrow` — `'notahex'`, `'#GGGGGG'` → `'#fff'`; records that `hexdec` silently ignores invalid characters, so garbage is accepted rather than rejected `[ERR]`
- [ ] `testCaseInsensitive` — `'#ffffb6'` and `'#FFFFB6'` agree `[ECP]`
- [ ] `testLeadingHashIsNotValidated` — `'1234567'` is processed as if it were a colour; characterization, no requirement behind it `[ERR]`

*Decision table not applicable: one condition (lightness) and two outcomes. State transition not applicable: the function is pure and holds no state.*

#### `check_color($hex)` — `tests/Unit/CheckColorTest.php`

**Happy path:**
- [ ] `testSixDigitHexIsAccepted` — `'aabbcc'` → `'#aabbcc'` `[HAPPY]`
- [ ] `testThreeDigitHexIsExpanded` — `'abc'` → `'#aabbcc'` `[HAPPY]`
- [ ] `testLeadingHashIsStripped` — `'#abc'` → `'#aabbcc'` `[ECP]`

**Boundary values** (length after `ltrim('#')`):
- [ ] `testLengthZeroRejected` — `''` → `false` `[BVA]`
- [ ] `testLengthTwoRejected` — `'ab'` → `false` `[BVA]`
- [ ] `testLengthThreeAccepted` — `'abc'` → `'#aabbcc'` `[BVA]`
- [ ] `testLengthFourRejected` — `'abcd'` → `false` `[BVA]`
- [ ] `testLengthFiveRejected` — `'abcde'` → `false` `[BVA]`
- [ ] `testLengthSixAccepted` — `'aabbcc'` → `'#aabbcc'` `[BVA]`
- [ ] `testLengthSevenRejected` — `'abcdefg'` → `false` `[BVA]`

**Negative and edge:**
- [ ] `testNonHexCharactersRejected` — `'gggggg'`, `'ab c'` → `false` `[NEG]`
- [ ] `testMultipleLeadingHashesAreAllStripped` — `'###abc'` → `'#aabbcc'`; characterization of `ltrim` `[ERR]`
- [ ] `testCaseIsPreserved` — `'ABCDEF'` → `'#ABCDEF'` `[ERR]`
- [ ] `testWhitespaceIsNotTrimmed` — `' abc'` → `false` `[ERR]`

**Cross-function property:**
- [ ] `testCheckColorOutputNeverMakesGetColorTextThrow` — for every accepted input, feeding the result to `get_color_text()` yields `#000` or `#fff` `[ECP]`

#### `get_typetag_id($input)` — regex branch only — `tests/Unit/GetTypetagIdTest.php`

The `'|'` branch touches the database and is covered at the integration layer; only the pure paths belong here.

- [ ] `testMarkerFormReturnsId` — `'~~123~~'` → `'123'` `[HAPPY]`
- [ ] `testZeroId` — `'~~0~~'` → `'0'` `[BVA]`
- [ ] `testEmptyMarkerRejected` — `'~~~~'` → `false` `[BVA]`
- [ ] `testNonNumericMarkerRejected` — `'~~12a~~'` → `false` `[NEG]`
- [ ] `testWhitespaceInMarkerRejected` — `'~~ 12 ~~'` → `false` `[NEG]`
- [ ] `testAnchoringIsEnforced` — `'~~123~~x'` → `false` `[BVA]`
- [ ] `testPlainStringReturnsFalse` — `'plain'`, `''` → `false` `[ECP]`

#### `typetags_partition_tags()` — `tests/Unit/PartitionTagsTest.php`

Zero-One-Many across both inputs:

- [ ] `testNoColouredTags` — `[]`, `[]` → both lists empty `[BVA]`
- [ ] `testOneColouredNoneAssigned` — 1 unassigned, 0 assigned `[BVA]`
- [ ] `testOneColouredAndAssigned` — 0 unassigned, 1 assigned `[BVA]`
- [ ] `testManyColouredNoneAssigned` — drives State C `[HAPPY]`
- [ ] `testManyColouredAllAssigned` — unassigned empty; drives State B and box 516 `[BVA]`
- [ ] `testManyColouredSomeAssigned` — drives State A `[HAPPY]`
- [ ] `testAssignedIdsContainingNonColouredTagsAreIgnored` — a plain tag id in `$assigned_ids` must not appear in either output; drives State D and box 557 `[NEG]`
- [ ] `testPartitionIsCompleteAndDisjoint` — invariant: `unassigned ∪ assigned == all_colored`, intersection empty `[ECP]`
- [ ] `testColorTextIsAddedToUnassignedOnly` — every unassigned entry carries `color_text`; assigned ids are bare `[HAPPY]`
- [ ] `testStringAndIntegerIdsBothMatch` — `'5'` and `5` both resolve; records the loose `in_array` `[ERR]`
- [ ] `testInputOrderIsPreserved` — the query orders by name; the partition must not reorder `[ST]`

#### Structural guards — `tests/Unit/TemplateContractTest.php`

- [ ] `testPictureTemplateStillContainsBothPrefilterTargets` — each search string occurs exactly once `[ERR]`
- [ ] `testNoChildThemeShadowsPictureTemplate` — `themes/modus/template/picture.tpl` absent `[ERR]`
- [ ] `testGuardFixtureIsNotVacuous` — the template file exists and exceeds 1000 bytes, so a moved or emptied template fails loudly instead of matching zero times `[ERR]`

*These are the "assert what the compiler does not watch" tests. Without them, a theme change or upstream merge removes the feature with no error anywhere.*

### Integration Tests

#### `AddTagTest.php`

**Happy path:**
- [ ] `testAssignsColouredTag` — `stat: ok`, row present `[HAPPY]`

**Negative:**
- [ ] `testGuestIsRejected` — 401 `[NEG]`
- [ ] `testBadTokenIsRejected` — 403 `[NEG]`
- [ ] `testEmptyTokenIsRejected` — 403 `[BVA]`
- [ ] `testMissingTokenParameterIsRejected` — WS missing-param error `[BVA]`
- [ ] `testNonColouredTagIsRejected` — 404 `[NEG]`
- [ ] `testNonexistentTagIsRejected` — `tag_id = 99999` → 404 `[NEG]`
- [ ] `testZeroTagIdIsRejected` — 404 `[BVA]`
- [ ] `testNegativeTagIdIsRejected` — `WS_TYPE_ID` rejects `[BVA]`
- [ ] `testNonexistentImageIsRejected` — 404 `[NEG]` **(fails before Phase 2 fix)**
- [ ] `testNonexistentImageWritesNoOrphanRow` — zero rows in `piwigo_image_tag` `[NEG]` **(fails before fix)**

**State transition / idempotency:**
- [ ] `testDuplicateAddIsIdempotent` — second call `ok`, and `COUNT(*) == 1` (stronger than the original presence check) `[ST]`

**Characterization (oracle is the code — no requirement confirms these):**
- [ ] `testMethodAlsoAnswersToGet` — `post_only` is not set; records current behaviour so a future change is visible `[ERR]`

#### `RemoveTagTest.php`
- [ ] `testRemovesAssignedTag` — `stat: ok`, row gone `[HAPPY]`
- [ ] `testGuestIsRejected` / `testBadTokenIsRejected` / `testNonColouredTagIsRejected` `[NEG]`
- [ ] `testNonexistentTagIsRejected` — gap: only `addTag` was tested `[NEG]`
- [ ] `testRemoveWhenNotAssignedIsIdempotent` — `ok`, zero rows `[ST]`
- [ ] `testRoundTrip` — unassigned → assigned → unassigned, DB verified at each step `[ST]`

#### `CacheInvalidationTest.php`
- [ ] `testAddNullsAvailableTagCount` — with an anti-vacuity guard asserting the value was non-null *before* the call `[ST]`
- [ ] `testRemoveNullsAvailableTagCount` — gap: only `addTag`'s was tested `[ST]`
- [ ] `testCacheIsRestoredAfterRun` — the current script leaves `nb_available_tags` mutated `[ERR]`

#### `PicturePageSourceTest.php`
- [ ] `testPageReturnsTwoHundredForLoggedInUser` `[HAPPY]`
- [ ] `testPageHasNoFatalError` / `testPageHasNoSmartyCompilerError` `[NEG]`
- [ ] `testExactlyOneScriptBlockIsInjected` — regression guard for the duplicate-injection fix `[ERR]`
- [ ] `testUnassignedBadgeCountMatchesFixture` — exactly *K* `typetag-add` spans for a forced *K*; **replaces the `|| true` assertion** `[ECP]`
- [ ] `testFixtureProducesAtLeastOneUnassignedTag` — anti-vacuity: the count test cannot pass over zero `[ERR]`
- [ ] `testAssignedColouredTagsRenderAsTaggedAnchors` — one `a[data-tag-id]` per assigned colored tag; **replaces the tautological assertion**, asserting what page source can actually witness `[HAPPY]`
- [ ] `testGuestSeesNoAssignmentUi` — neither `typetags-unassigned` nor `typetag-add` `[NEG]`
- [ ] `testImageWithNoTagsRendersNoTagsRow` — `#Tags` absent; proves State C's precondition is real `[BVA]`

### End-to-End Tests

Mapped one-to-one onto the unticked boxes in Plan B. Box numbers are that file's line numbers.

#### `assign.spec.js` — add flow
- [ ] `unassigned badges render at reduced opacity with a plus prefix` — box 514 `[HAPPY]`
- [ ] `clicking an unassigned badge moves it into the Tags row at full opacity` — box 537 `[HAPPY]`
- [ ] `a remove button appears on the newly assigned tag` — box 538 `[HAPPY]`
- [ ] `the badge disappears from the unassigned list` — box 539 `[ST]`
- [ ] `the unassigned section hides when the last tag is assigned` — box 540, State B `[BVA]`
- [ ] `the Tags row is created when the image had no tags` — box 541, State C `[BVA]`
- [ ] `the assignment survives a page reload` — box 542 `[ST]`

#### `remove.spec.js` — remove flow
- [ ] `assigned coloured tags show a remove button` — box 515 `[HAPPY]`
- [ ] `clicking it removes the tag from the Tags row` — box 545 `[HAPPY]`
- [ ] `the tag reappears in the unassigned list at reduced opacity` — box 546 `[ST]`
- [ ] `the Tags row hides when the last tag is removed` — box 547 `[BVA]`
- [ ] `the unassigned section is recreated when it had been hidden` — box 548, State B `[BVA]`
- [ ] `the removal survives a page reload` — box 549 `[ST]`
- [ ] `add then remove returns the page to its starting state` — round trip `[ST]`

#### `edge-cases.spec.js`
- [ ] `double-clicking issues exactly one request` — asserted by counting intercepted POSTs, not by eyeballing the UI — box 552 `[ERR]`
- [ ] `a network failure leaves the tag in place and the badge clickable` — `route.abort()` — box 553 `[NEG]`
- [ ] `a server rejection leaves the badge clickable` — `route.fulfill()`, HTTP 200 + `stat:"fail"` — **no existing box; this is the Phase 2 defect's signature** `[NEG]`
- [ ] `comma separators render between multiple assigned tags` — box 554 `[HAPPY]`
- [ ] `comma separators clean up with no leading or trailing comma` — box 555 `[BVA]`
- [ ] `an image with only non-coloured tags shows no remove buttons` — box 557, State D `[NEG]`
- [ ] `the modus theme renders both sections correctly` — box 517 `[HAPPY]`

### Regression — Affected Existing Functionality

The partition extraction (Phase 1) and the `get_color_text` guard (Phase 2) are both touched by code well outside this feature:

- [ ] `typetags_render()` calls `get_color_text()` on every tag on every public page — covered by `PicturePageSourceTest` and by the E2E runs, which would break visibly if badge contrast regressed
- [ ] `typetags_admin()` calls `get_color_text()` for the admin tags page — [ ] add one integration assertion that `admin.php?page=tags` returns 200 with no fatal
- [ ] `ws_typetags_type_add()` calls both `check_color()` and `get_color_text()` — [ ] add an integration test creating a colour and asserting the returned `color_text`
- [ ] `typetags_picture_tags()` is the sole caller of the extracted partition — the integration suite is its net; run it before and after the extraction and diff nothing

### Mutation Testing

Per the decision settled 2026-08-28, mutation applies to **unit tests only** — never to an integration, E2E, or structural guard test, because a red end-to-end run does not say which mutation caused it. Kept as prose, not as a script: a script that patches and reverts source is a second thing to keep correct.

- [ ] Record a mutant table for the unit suite: mutant → killed by. Minimum set:
  - `$l > 0.45` → `$l >= 0.45` — should be killed by the BVA pair, and if it is *not*, that proves the threshold is unreachable rather than that the test is weak; record which
  - `$l > 0.45` → `$l > 0.5` — killed by the palette test
  - `strlen($color) == 7` → `>= 7` — killed by the malformed-length test
  - `return '#000'` (the new guard) → `return '#fff'` — killed by the malformed-length test
  - `in_array($tag['id'], $assigned_ids)` → `!in_array(...)` — killed by the partition tests
  - `substr_count(...) === 1` → `>= 1` in the template guard — killed by nothing today; record the gap honestly rather than claiming coverage
- [ ] Record what did **not** move. The phrasing matters: *"Nothing else moved"* is the claim that a mutant killed exactly the tests that watch it. A mutant too weak to kill proves nothing about the test and is recorded as such, not quietly replaced with an easier one.
- [ ] Where a mutant is expected to be killed and is not, record which — an unkillable `$l >= 0.45` mutant is evidence the threshold is unreachable, not evidence the test is weak. Both readings are findings; guessing between them is not.

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
| *(to be filled during Phase 5's `/verify` run)* | | |

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
