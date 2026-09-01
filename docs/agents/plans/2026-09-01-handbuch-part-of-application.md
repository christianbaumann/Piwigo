---
date: 2026-09-01T08:57:19+00:00
git_commit: 2fc6e14936ab4e012cf9a05ba86423b3c936622d
branch: master
topic: "Move the German handbook into the deployed application tree"
tags: [plan, deployment, handbuch, fileset]
status: draft
---

# Move the German Handbook Into the Deployed Application Tree

## Overview

`docs/handbuch/` is the fork's German end-user handbook (six HTML pages, a stylesheet,
screenshots, and a dev-only toolchain that generates them). `docs/` is entirely excluded from
`pwg-deploy`'s published file set by design, so the handbook has never been reachable on a real
install — only via `file://` locally. This plan moves the handbook's shippable content into the
application tree at top-level `handbuch/`, so it deploys and is reachable at `/handbuch/` on the
gallery's own web space, while keeping its dev-only generator tooling out of the published set.

Confirmed with the user before drafting: the new location is a **top-level `handbuch/`
directory** (not `local/handbuch/`, not a carve-out inside `docs/`), and this plan makes the
handbook **reachable by direct URL only** — it does not add an in-app link (footer/help menu).
That stays a separate, later change if wanted.

## Current State Analysis

- `docs/handbuch/` holds: `index.html`, `01-alben.html` … `05-personen.html`, `assets/handbuch.css`,
  `assets/screenshots/*.png` (20 files), and `tools/{seed.php,shoot.js,check.php}` — dev-only
  generator/checker scripts that need DDEV, `php exec`, Node and Playwright, and refuse to run
  without the install's `persons_throwaway_install` marker (`.claude/rules/handbook.md`).
- `tools/deploy/pwgdeploy/fileset.py`'s `EXCLUDED_PREFIXES` contains `"docs/"` unqualified — this
  is what keeps the whole handbook (pages and tooling alike) off every deploy today. The exclusion
  is intentional and correct for the rest of `docs/` (agent research, plans, decisions, the
  `TESTING.md` ledger — none of which should ever ship), so narrowing it is the wrong fix; moving
  the shippable content out is the clean one.
- There is **no root `.htaccess`** and no Piwigo rewrite rule that would intercept an arbitrary
  static subdirectory — core's request lifecycle is a fixed set of named entry scripts
  (`index.php`, `admin.php`, `ws.php`, …), so a new static directory is simply served by the web
  server (nginx-fpm under DDEV; the production host per `.claude/rules/deployment.md`) with no
  routing conflict to work around.
- `docs/agents/decisions/0024-german-handbook-location-and-demo-content.md`'s **Decision 1**
  chose `docs/handbuch/` specifically to reject `language/de_DE/help/` — that is upstream's
  directory, rewritten on every upstream merge, and the handbook covers two fork-local plugins
  upstream's help has no place for. That rejection is **still correct** and this plan does not
  reopen it; only the "not reachable from the gallery" consequence of Decision 1 changes.
  Decisions 2 (generated demo content) and 3 (translate first, screenshot second) are unaffected.

### Key Discoveries:

- **The pages already work with no server at all** — `plugins/provenance/tests/e2e/handbuch-pages.spec.js:52`
  navigates each page via a bare `file://` URL and asserts the stylesheet applied and every
  screenshot loaded. That test's own docstring states this was a deliberate original success
  criterion: "the pages 'open correctly from the filesystem with no server' — a `file://` fact no
  server-side check can reach." Because every internal reference in the handbook is already
  relative (proven by this requirement), relocating the whole subtree as one unit preserves both
  the `file://` property and correct rendering when served over HTTP — no HTML content needs to
  change, only the directory's position in the tree.
- **Path-depth constants are the only code that needs updating for the move.** Each tool climbs a
  hardcoded number of directory levels to find the repo root:
  - `docs/handbuch/tools/check.php:25` — `const ROOT = __DIR__ . '/../../..';` (3 levels)
  - `docs/handbuch/tools/seed.php:192` — `define('PHPWG_ROOT_PATH', dirname(__DIR__, 3) . '/');` (3 levels)
  - `docs/handbuch/tools/shoot.js:40` — `const ROOT = path.resolve(__dirname, '..', '..', '..');` (3 levels), and
    `shoot.js:41` hardcodes the output path as `path.join(ROOT, 'docs', 'handbuch', 'assets', 'screenshots')`.
  - `plugins/provenance/tests/e2e/handbuch-pages.spec.js:21` — `path.resolve(__dirname, '../../../../docs/handbuch')`.
  Moving `docs/handbuch/` (3 levels: `tools → handbuch → docs → root`) to `handbuch/` (2 levels:
  `tools → handbuch → root`) means every one of these constants drops by exactly one level.
  `check.php`'s `HANDBUCH_DIR = __DIR__ . '/..'` is already relative and needs no change.
- **The dev tooling needs its own deploy exclusion, by prefix, not by directory-name.**
  `EXCLUDED_DIR_NAMES = ("tests",)` matches a path *segment* anywhere in the tree — adding
  `"tools"` there would also exclude `plugins/persons/tools/helper.php`, which
  `tools/deploy/tests/test_fileset.py:142` (`test_a_toolsish_path_outside_the_directory_is_kept`)
  explicitly asserts must **survive** (decision 0022's own boundary case). The correct mechanism
  is a dedicated prefix, `"handbuch/tools/"`, added to `EXCLUDED_PREFIXES` — the same shape as the
  existing bare `"tools/"` entry for the deploy tool itself, just scoped one level deeper.
- **`EXCLUDED_PREFIXES` is already table-driven in the test suite**
  (`@pytest.mark.parametrize("prefix", fileset.EXCLUDED_PREFIXES)` at `test_fileset.py:101`), so
  adding the new prefix constant automatically gains a parametrized exclusion test with no extra
  code — only the two positive/boundary cases specific to the handbook need writing by hand.
- Six other files hold **prose references** to `docs/handbuch/...` in comments/docs, not in code
  that resolves a path at runtime: `CLAUDE.md:64`, `.claude/rules/handbook.md` (throughout),
  `plugins/provenance/tests/Unit/GermanOverrideKeyTest.php:11`,
  `plugins/provenance/tests/Integration/CorePhotoTextCharacterizationTest.php:258,296`,
  `plugins/provenance/tests/e2e/core-admin-screens.spec.js:109`,
  `plugins/typetags/tests/e2e/normal-account.spec.js:14`,
  `plugins/typetags/tests/e2e/auth.setup.js:48`, and `docs/agents/TESTING.md:573,761,784`. These
  need a text update for accuracy (per the "keep instructions honest" meta-rule in
  `.claude/rules/backpressure.md`) but touch no test logic or assertions.
- `docs/agents/research/2026-08-31-german-end-user-documentation.md` and
  `docs/agents/plans/2026-08-31-german-end-user-documentation.md` are **not** touched — they are
  dated historical records of what was researched/planned on 2026-08-31, same as decision 0024
  itself; per this project's own convention they are never edited to match a later reality (see
  "What We're NOT Doing").
- `.gitignore` has no rule that would catch a new top-level `handbuch/` directory — the blanket
  ignore rules are scoped to `plugins/*`, `themes/*`, `local/*`, `_data`, `upload`, `galleries/*`,
  none of which match. No `.gitignore` change is needed.

## Desired End State

- `handbuch/` is a top-level, git-tracked directory holding the six HTML pages, the stylesheet and
  screenshots, and `handbuch/tools/` (the dev-only generator/checker scripts).
- A deploy (`uv run pwg-deploy deploy.local.json`) publishes `handbuch/index.html`,
  `handbuch/01-alben.html` … `handbuch/05-personen.html`, `handbuch/assets/**`, and **excludes**
  `handbuch/tools/**`.
- `/handbuch/` renders correctly both on the local DDEV install (`https://piwigo.ddev.site/handbuch/`)
  and on the deployed sandbox host — verified by loading it in a real browser, not inferred.
- `docs/handbuch/tools/check.php` (now `handbuch/tools/check.php`) and the existing
  `handbuch-pages.spec.js` E2E suite still pass unchanged in behavior — only their path constants
  moved.
- Every rule file, decision, and TESTING.md line that names the old path now names the new one.
- A new decision file records why the location changed, explicitly re-affirming (not
  re-litigating) that `language/de_DE/help/` is still rejected.

### Directory shape, before and after

```
BEFORE                                  AFTER
docs/                                   docs/
  agents/                                 agents/
    decisions/                             decisions/
    plans/                                 plans/
    research/                              research/
    TESTING.md                             TESTING.md
  handbuch/            <- excluded       handbuch/            <- published (tools/ excluded)
    index.html          from deploy        index.html
    01-alben.html        (docs/ prefix)     01-alben.html
    ...05-personen.html                     ...05-personen.html
    assets/                                 assets/
    tools/                                  tools/             <- NOT published
      seed.php                               seed.php             (handbuch/tools/ prefix)
      shoot.js                                shoot.js
      check.php                               check.php
plugins/                                plugins/
themes/                                 themes/
admin/                                  admin/
```

### Key Discoveries: see "Current State Analysis" above.

## What We're NOT Doing

- **Not adding an in-app link** to the handbook (footer, help menu, admin dashboard). Confirmed
  with the user: this plan only makes the handbook deployable and reachable by direct URL.
- **Not moving the handbook into `language/de_DE/help/`.** Decision 0024's rejection of that
  location (upstream owns it, an upstream merge rewrites it, it has no place for fork-local
  plugin coverage) still holds and is restated, not reopened.
- **Not editing `docs/agents/decisions/0024-...md`, the 2026-08-31 research doc, or the
  2026-08-31 plan doc.** They are dated historical records; a new decision supersedes the
  relevant part of 0024 without erasing it, per `.claude/rules/backpressure.md`'s decision-log
  convention ("a decision that later changes gets a new file superseding the old, never an edit
  that erases what was decided").
- **Not changing any handbook page's HTML content.** Every internal reference is already relative;
  the move needs no content edit, only path-depth constants in the tooling.
- **Not adding a `.htaccess` or web-server config change.** None exists today and none is needed —
  the new E2E test in Phase 4 is exactly what proves that.
- **Not building an automated check against the real remote host.** Per
  `.claude/rules/deployment.md` ("What has no local test double"), the remote HTTP endpoint has no
  test double; the remote-reachability check stays a recorded hand check, same as the existing
  FTPS/`install.php` probes.

## Implementation Approach

Four phases, each independently verifiable: (1) relocate the directory and fix every path
constant and prose reference so behavior is unchanged at the new location; (2) teach the deploy
tool's fileset to publish the pages and exclude the tooling, test-first; (3) record the decision
and cross-link the rule files; (4) add the one piece of behavior that is genuinely new — HTTP
reachability — as an automated DDEV-based E2E test, then hand-verify the real deploy once.

Phase 1 lands the move with zero behavior change (provable by the existing `file://` E2E suite and
`check.php` passing unchanged). Phase 2 is the actual new capability (deployability), built
test-first against the pure `fileset.select()` function. Phases 1 and 2 both land before any real,
non-dry-run deploy is run, so production is never in an inconsistent state.

## Phase 1: Relocate the directory and fix path constants

### Overview
Move `docs/handbuch/` to `handbuch/` with `git mv`, so git records it as a rename. Update every
path-depth constant in the tooling and the one code reference in the E2E suite. Update prose
references everywhere else. No behavior changes — this phase is a pure relocation, verified by
re-running the tests that already characterize the handbook's correctness.

### Changes Required:

#### [x] 1. Move the directory
**Command**: `git mv docs/handbuch handbuch`

#### [x] 2. Fix path-depth constants in the moved tooling
**File**: `handbuch/tools/check.php`
**Changes**: `ROOT` drops from 3 levels to 2; update the two hardcoded `docs/handbuch/` strings in
`fail()` messages (lines ~66, ~240) and the two doc-comment references (lines ~10, ~17) to
`handbuch/`.
```php
const ROOT = __DIR__ . '/../..';
```

**File**: `handbuch/tools/seed.php`
**Changes**: `PHPWG_ROOT_PATH` drops from 3 levels to 2.
```php
define('PHPWG_ROOT_PATH', dirname(__DIR__, 2) . '/');
```

**File**: `handbuch/tools/shoot.js`
**Changes**: `ROOT` drops from 3 `'..'` segments to 2; `OUT_DIR` drops the `'docs'` path segment.
```js
const ROOT = path.resolve(__dirname, '..', '..');
const OUT_DIR = path.join(ROOT, 'handbuch', 'assets', 'screenshots');
```

#### [x] 3. Fix the E2E suite's path constant
**File**: `plugins/provenance/tests/e2e/handbuch-pages.spec.js`
**Changes**: Drop the `docs` segment; the `../../../../` prefix (4 levels from
`plugins/provenance/tests/e2e/` to repo root) is unchanged.
```js
const HANDBUCH_DIR = path.resolve(__dirname, '../../../../handbuch');
```

#### [x] 4. Update prose references (comments/docs only, no logic change)
**Files, each a straight text substitution of `docs/handbuch` → `handbuch`**:
- `CLAUDE.md:64`
- `.claude/rules/handbook.md` — every occurrence, including the file's own opening sentence, the
  three-commands block's `ddev exec php docs/handbuch/tools/...` lines, and the
  `docs/handbuch/tools/check.php` command
- `plugins/provenance/tests/Unit/GermanOverrideKeyTest.php:11`
- `plugins/provenance/tests/Integration/CorePhotoTextCharacterizationTest.php:258,296`
- `plugins/provenance/tests/e2e/core-admin-screens.spec.js:109`
- `plugins/typetags/tests/e2e/normal-account.spec.js:14`
- `plugins/typetags/tests/e2e/auth.setup.js:48`
- `docs/agents/TESTING.md:573,761,784`

### Success Criteria:

#### Automated Verification:
- [x] `git status` shows the move as a rename (`renamed:` for each file), not a delete+add
- [x] Syntax check: `ddev exec php -l handbuch/tools/check.php`
- [x] Syntax check: `ddev exec php -l handbuch/tools/seed.php`
- [x] `ddev exec php handbuch/tools/check.php` exits 0 and reports the same page/reference/route
      counts as before the move (6 pages, 20 screenshots, ≥20 references, ≥5 admin routes)
- [x] `ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; cd plugins/provenance && npx playwright test handbuch-pages.spec.js'`
      passes all cases unchanged
- [x] `grep -rn "docs/handbuch"` over the repo (excluding `docs/agents/research/`,
      `docs/agents/plans/`, and `docs/agents/decisions/`) returns nothing

#### Manual Verification:
- [x] Open `handbuch/index.html` directly in a browser via `file://` and click through to each of
      the five pages — same as before the move, confirming no relative link broke

**Implementation Note**: After completing this phase and all automated verification passes, pause
here for manual confirmation from the human before proceeding to the next phase.

---

## Phase 2: Exclude the tooling, publish the rest

### Overview
Add a dedicated `handbuch/tools/` prefix to the deploy tool's exclusion list, so a deploy
publishes the handbook's pages and assets but not its dev-only generator/checker scripts.
Test-first: the new unit tests are written and run red before the one-line production change,
then green after.

### Changes Required:

#### [x] 1. Write the new fileset unit tests (red first)
**File**: `tools/deploy/tests/test_fileset.py`
**Changes**: Three new tests plus two new assertions in the existing characterization test.

```python
def test_the_handbook_ships_with_the_application():
    """[HAPPY] The pages, stylesheet and screenshots are ordinary application content."""
    kept = [
        "handbuch/index.html",
        "handbuch/01-alben.html",
        "handbuch/assets/handbuch.css",
        "handbuch/assets/screenshots/01-alben-verwaltung.png",
    ]
    assert fileset.select(kept) == kept


def test_the_handbook_toolchain_stays_off_the_web_space():
    """[NEG] The generator/checker scripts need DDEV, php exec and Node — dev-only,
    same posture as the top-level tools/ directory (decision 0022)."""
    assert fileset.select([
        "handbuch/tools/seed.php",
        "handbuch/tools/shoot.js",
        "handbuch/tools/check.php",
    ]) == []


def test_a_handbuch_toolsish_path_outside_the_tools_directory_is_kept():
    """[BVA] handbuch/tools/ is a path prefix, not a substring: a sibling file whose
    name merely starts the same way survives, mirroring
    test_a_toolsish_path_outside_the_directory_is_kept for the top-level tools/ rule."""
    assert fileset.select(["handbuch/toolshed.html"]) == ["handbuch/toolshed.html"]
```

Add to `test_real_repository_file_set` (after the existing `themes/default/vendor/fontello/`
assertion, alongside the other real-repo checks):
```python
    assert "handbuch/index.html" in selected
    assert "handbuch/tools/seed.php" in tracked
    assert "handbuch/tools/seed.php" not in selected
```

#### [x] 2. Add the exclusion prefix (green)
**File**: `tools/deploy/pwgdeploy/fileset.py`
**Changes**: One new entry in `EXCLUDED_PREFIXES`, with a comment matching the file's existing
citation style.

```python
EXCLUDED_PREFIXES = (
    "docs/",
    ".claude/",
    ".githooks/",
    ".ddev/",
    "local/config/",
    "tools/",
    # decision 0025: the handbook's dev-only generator/checker tooling stays off the
    # web space, same reasoning as the bare tools/ entry above — just scoped to the
    # one subtree that needs it, since handbuch/ itself now ships.
    "handbuch/tools/",
    ".gitignore",
    ".gitmodules",
)
```

### Success Criteria:

#### Automated Verification:
- [x] Before step 2: run `uv run pytest tests/test_fileset.py -k handbuch` — the new
      `test_the_handbook_toolchain_stays_off_the_web_space` and
      `test_drops_each_excluded_prefix[handbuch/tools/]` (once parametrized) fail red, since
      `handbuch/tools/` is not yet excluded; the other two new tests pass already, because
      `select()` is a pure filter and nothing today excludes a bare `handbuch/` prefix
- [x] After step 2: `cd tools/deploy && uv run pytest` — full suite green, including the three new
      tests, the updated `test_real_repository_file_set`, and the auto-generated
      `test_drops_each_excluded_prefix[handbuch/tools/]` parametrized case
- [x] `cd tools/deploy && uv run pwg-deploy --list-files deploy.local.json | grep '^handbuch/'`
      lists the six HTML pages and every asset, and lists nothing under `handbuch/tools/`

#### Manual Verification:
- [x] None — this phase is fully covered by the pure-function unit tests above

**Implementation Note**: After completing this phase and all automated verification passes, pause
here for manual confirmation from the human before proceeding to the next phase.

---

## Phase 3: Record the decision, cross-link the rule files

### Overview
Write the decision file that supersedes Decision 1 of 0024 without editing 0024 itself, and add
one bullet to `deployment.md`'s "What is published" section so the new exclusion has a named
reason discoverable from the file that already documents every other one.

### Changes Required:

#### [x] 1. Write the decision record
**File**: `docs/agents/decisions/0025-handbuch-moves-into-the-application-tree.md`
**Changes**: New file. Must state, per this project's decision-log convention: what changed
(location only), why (docs/ must stay a clean, unqualified "never ships" prefix — nesting an
allow-list inside a deny-list prefix was rejected as the weaker option), what stays the same
(the rejection of `language/de_DE/help/`, decisions 2 and 3 of 0024, the `file://`-with-no-server
property), and the deliberate scope limit (URL-only, no in-app link, confirmed with the user
2026-09-01). Header must read `Supersedes Decision 1 of docs/agents/decisions/0024-german-handbook-location-and-demo-content.md`.

#### [x] 2. Add the bullet to deployment.md
**File**: `.claude/rules/deployment.md`
**Changes**: In the "What is published" section's list of exceptions that need a reason, add:
```markdown
- **`handbuch/` ships, `handbuch/tools/` does not** — the German handbook is application content
  since [decision 0025](../../docs/agents/decisions/0025-handbuch-moves-into-the-application-tree.md);
  its generator/checker scripts are dev tooling and stay off the web space, same as `tools/`.
```

### Success Criteria:

#### Automated Verification:
- [x] `wc -l .claude/rules/deployment.md` stays under the 500-line cap
      (`.claude/rules/backpressure.md`)

#### Manual Verification:
- [x] Decision file reads as a complete, standalone record per the format of the existing 24
      decision files (Date, Status, Supersedes line, Context, Decision(s), Consequences, What
      would reverse this)

**Implementation Note**: After completing this phase and all automated verification passes, pause
here for manual confirmation from the human before proceeding to the next phase.

---

## Phase 4: Prove HTTP reachability, then deploy for real

### Overview
Everything so far proves the file set is correct and the pages still render from disk. Nothing
yet proves the web server actually serves `/handbuch/` as a static directory rather than routing
it somewhere unexpected — that is the one genuinely new piece of behavior this plan adds, and it
gets its own automated test against the real DDEV server (not a double, since DDEV *is* the real
nginx-fpm stack this fork runs on). The remote host has no test double per `deployment.md`, so
that half stays a recorded hand check, same as the existing FTPS/`install.php` probes.

### Changes Required:

#### [x] 1. New E2E test: the handbook is servable over HTTP, not just from disk
**File**: `plugins/provenance/tests/e2e/handbuch-pages.spec.js`
**Changes**: New `test.describe` block alongside the existing `file://` one. No session needed —
`/handbuch/` is public static content, same as the existing block's comment already notes for the
file-based tests.

```js
test.describe('the handbook is served by the running app', () => {
  test('index.html answers at /handbuch/ with its content intact', async ({ page }) => {
    const response = await page.goto('/handbuch/');
    expect(response.status()).toBe(200);
    await expect(page.locator('h1')).toBeVisible();
  });

  test('a screenshot referenced from a page loads over HTTP', async ({ page }) => {
    await page.goto('/handbuch/01-alben.html');
    const firstImage = page.locator('img').first();
    await expect(firstImage).toBeVisible();
    const naturalWidth = await firstImage.evaluate((img) => img.naturalWidth);
    expect(naturalWidth).toBeGreaterThan(0);
  });
});
```

### Success Criteria:

#### Automated Verification:
- [x] `ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; cd plugins/provenance && npx playwright test handbuch-pages.spec.js'`
      — both new HTTP-based tests pass against `https://piwigo.ddev.site/handbuch/`
- [x] Full provenance suite regression: unit, integration and the full e2e run all still green
      (`plugins/provenance` commands in `.claude/rules/plugin-test-suites.md`)
- [x] `cd tools/deploy && uv run pwg-deploy --dry-run deploy.local.json` predicts `handbuch/`'s
      pages and assets as new/changed uploads and nothing under `handbuch/tools/`

#### Manual Verification:
- [ ] Run the real deploy: `cd tools/deploy && uv run pwg-deploy deploy.local.json`
- [ ] Visit `http://bilder.foerderverein-sefferweich.de/handbuch/` in a browser and confirm the
      index page renders with its stylesheet
- [ ] Click through to each of the five pages on the remote host and confirm every screenshot
      loads
- [ ] Record the hand check, dated, in `docs/agents/TESTING.md`'s ledger — this is exactly the
      shape of check that section already exists for (remote HTTP has no local test double)

**Implementation Note**: This is the final phase. After all verification passes, the plan is
complete.

---

## Testing Strategy

### Test Design Techniques Applied

- **Equivalence class partitioning**: two classes of path under `handbuch/` — shippable content
  (pages, assets) vs. dev tooling (`handbuch/tools/`) — one representative test per class.
- **Boundary value analysis**: a path that starts the same as the excluded prefix without matching
  it (`handbuch/toolshed.html`), mirroring the existing boundary test for the bare `tools/` rule.
- **State transition testing**: not applicable — `fileset.select()` is a pure, stateless filter
  over a static list, not applicable to the deploy fileset (already noted in the file's own
  module docstring for the existing suite).
- **Decision table testing**: not applicable — same reason the existing suite's docstring gives:
  "the rules are a disjunction, not a matrix — one hit excludes, and no combination changes the
  outcome."
- **Error guessing**: the one genuinely error-prone step is the four hardcoded path-depth
  constants (Phase 1) — an off-by-one there would make `ROOT`/`PHPWG_ROOT_PATH` resolve to the
  wrong directory silently. `check.php` and `seed.php` both `require`/`file_exists()` against
  paths built from that constant, so a wrong depth fails loudly (missing-file error) rather than
  silently — confirmed by re-running `check.php` and the E2E suite in Phase 1's success criteria.

### Unit Tests (base of pyramid — fast, isolated, exhaustive):

#### New/Changed Functionality (`tools/deploy/pwgdeploy/fileset.py`):

**Happy path:**
- [ ] `test_fileset.py::test_the_handbook_ships_with_the_application` — pages, stylesheet and a
      screenshot all survive `select()` `[HAPPY]`

**Negative testing:**
- [ ] `test_fileset.py::test_the_handbook_toolchain_stays_off_the_web_space` — the three tool
      scripts are all dropped `[NEG]`

**Edge cases and boundary values:**
- [ ] `test_fileset.py::test_a_handbuch_toolsish_path_outside_the_tools_directory_is_kept` — a
      sibling file whose name starts with "tools" but isn't the directory survives `[BVA]`
- [ ] `test_fileset.py::test_drops_each_excluded_prefix[handbuch/tools/]` — auto-generated by the
      existing `@pytest.mark.parametrize("prefix", fileset.EXCLUDED_PREFIXES)` once the new prefix
      constant exists; no code to write `[ECP]`

#### Regression — Affected Existing Functionality:
- [ ] `test_fileset.py` full suite (all pre-existing tests) — verify still green; nothing else in
      `EXCLUDED_PREFIXES`, `EXCLUDED_BASENAMES` or `EXCLUDED_DIR_NAMES` changed
- [ ] `test_fileset.py::test_real_repository_file_set` — extended with the two new assertions
      above; this is the test that would catch the move itself going wrong (e.g. `handbuch/`
      accidentally left nested under something still excluded)
- [ ] `test_fileset.py::test_the_selected_file_set_weighs_what_a_deploy_expects` — the byte-total
      band (`MIN_SELECTED_BYTES`..`MAX_SELECTED_BYTES`) must still hold; the handbook's ~20
      screenshots add a small, bounded weight that should not push the real file set outside the
      existing band (verify by running it, not by calculating — the band is wide precisely so a
      change like this does not require re-tuning it)

### Integration Tests (middle of pyramid — component interactions):

There is no integration layer for the deploy tool's decision logic (`fileset.py` is pure and
covered at the unit layer above); the tool's only real "integration" points are FTPS and the
remote HTTP endpoint, both of which are declared as having no local test double
(`.claude/rules/deployment.md`) and are covered by the hand checks in Phase 4 instead.

**Happy path:**
- [ ] `handbuch/tools/check.php` run against the moved directory — a real filesystem/PHP
      interaction (not pure), covered in Phase 1's automated verification `[HAPPY]`

### End-to-End Tests (top of pyramid — critical user journeys):
- [ ] `handbuch-pages.spec.js` — existing `file://`-based suite, re-run unchanged after the path
      constant fix (Phase 1) `[HAPPY]`
- [ ] `handbuch-pages.spec.js::'the handbook is served by the running app'` — new, HTTP-based,
      proves the one behavior this whole plan exists to add (Phase 4) `[HAPPY]`
- [ ] `handbuch-pages.spec.js::'a screenshot referenced from a page loads over HTTP'` — proves a
      relative asset reference resolves correctly when served, not just when opened from disk
      (Phase 4) `[HAPPY]`

### Manual Testing Steps:
1. Phase 1: click through all five pages via `file://` after the move (relative-link regression,
   cheaper to eyeball than to instrument further given the E2E suite already covers page load).
2. Phase 4: click through all five pages on the real deployed sandbox host — the one thing no
   automated layer can reach, per `deployment.md`'s "no local test double" for the remote HTTP
   endpoint.

### Test Commands:
```bash
# Unit tests (deploy fileset)
cd tools/deploy && uv run pytest tests/test_fileset.py

# Full deploy tool suite (regression)
cd tools/deploy && uv run pytest

# Handbook mechanical check
ddev exec php handbuch/tools/check.php

# Provenance E2E suite (includes the handbook specs)
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; \
  cd plugins/provenance && npx playwright test'

# Provenance full suite (regression: unit + integration + e2e)
ddev exec plugins/provenance/vendor/bin/phpunit --testsuite unit --configuration plugins/provenance/phpunit.xml
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; \
  plugins/provenance/vendor/bin/phpunit --testsuite integration --configuration plugins/provenance/phpunit.xml'
```

## Performance Considerations

None. This is a directory relocation plus a one-line addition to a pure filter function; the
deploy's payload grows by the handbook's own ~20 screenshots' worth of bytes, already inside the
existing byte-band test's tolerance.

## Migration Notes

Not applicable to the running application — no database, no user data. On the already-deployed
sandbox host, the *old* `docs/` prefix exclusion means nothing handbook-related was ever
published there before this plan, so there is nothing stale to clean up remotely.

## References

- `docs/agents/decisions/0024-german-handbook-location-and-demo-content.md` — the decision this
  plan partially supersedes
- `docs/agents/decisions/0022-the-tools-directory-is-not-published.md` — the precedent this plan's
  `handbuch/tools/` exclusion mirrors
- `.claude/rules/handbook.md`, `.claude/rules/deployment.md` — rule files updated by this plan
- `plugins/provenance/tests/e2e/handbuch-pages.spec.js` — existing E2E suite this plan extends
- `tools/deploy/pwgdeploy/fileset.py`, `tools/deploy/tests/test_fileset.py` — the deploy tool's
  fileset logic and its test suite
