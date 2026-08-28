---
date: 2026-08-28T17:56:10.533255+00:00
git_commit: 371c13ea94d27809f0590ec4c371b71606d513f4
branch: master
topic: "Implementation status of docs/agents/plans, test coverage of the new functionality, and external testing patterns"
tags: [research, codebase, typetags, plans, testing, coverage, tooling]
status: complete
---

# Research: Plan Implementation Status and Test Coverage

## Research Question

1. How well have the plans in `docs/agents/plans` been implemented?
2. What tests cover the new and changed functionality?
3. What testing, backpressure, and pre-commit-hook patterns exist in an external reference repository that could serve as a blueprint (adapted to this stack)?

## Summary

Both plans are implemented in code. Every implementation checkbox in both plan files is ticked, and the code matches the plans closely — Phase 1 of the picture-page plan is byte-for-byte identical to the code blocks written in the plan. Three deltas exist between plan and implementation, all in Phase 2/3 JavaScript, and all of them are fixes discovered during browser verification rather than deviations of intent.

Every *manual verification* checkbox in both plan files remains unticked, and both plans still carry `status: draft` in their frontmatter, even though a 447-line integration test and a browser verification run were produced afterwards and cover a substantial portion of those checklists.

Test coverage is strongly weighted to the web-service layer. The integration test makes 25 assertions and covers all seven Phase 1 checklist items. Coverage thins out sharply at the UI layer: of the 17 Phase 2/3 checklist items, 4 are covered by assertions, 4 rest on prose claims in the browser report with no command output substantiating them, and 9 have no evidence in either artifact. One assertion (`test_ws_tag_assignment.php:379`) ends in `|| true` and therefore passes unconditionally.

The repository has no CI, no linter, no static analysis, no active git hooks, and no test runner. The only mechanical checks are `php -l` and the single integration script. The reference repository studied for Part 3 reaches a comparable "no lint gate, no git hook" position on its main branch by explicit decision, while carrying a set of structural guard tests inside its ordinary test run, and holds a fuller guard-rail mechanism (ratcheted pre-commit hook, diff-scoped lint, coverage floor) on an unmerged branch.

Runtime verification was completed: the plugin is installed and `active`, the schema is present, and the integration test passes 25/25 with exit code 0 on PHP 8.4 in the container.

---

## Part 1: Plan Implementation Status

### Plan A — `2026-04-19-install-colored-tags-plugin.md`

Frontmatter `status: draft`. Phase 1 (three items) all ticked; Phase 2 (three items, marked "manual — user action") all unticked.

| Plan item | State | Evidence |
|---|---|---|
| `.gitignore` exception `!/plugins/typetags` | Present | `.gitignore:5` |
| `.gitmodules` with `[submodule "plugins/typetags"]` | Present | `.gitmodules:1-3` |
| Submodule added and committed | Present | `git submodule status` → `e07139f7… plugins/typetags (heads/master)` |
| `plugins/typetags/main.inc.php` exists | Present | `plugins/typetags/main.inc.php:1-10` (header block: `Plugin Name: Colored Tags`, `Has Settings: true`) |
| Plugin installed/activated in admin UI | Present | `piwigo_plugins` → `typetags \| active \| auto` |
| Tag colors configurable | Present | `piwigo_typetags` holds 8 colors; 8 tags carry a non-null `id_typetags` |

Verified runtime state (DDEV up, 2026-08-28):

```
piwigo_plugins:  typetags | active | auto
piwigo_typetags: 8 rows (Personen #FFFFB6, Arbeiten #FFCA4F, Gewerbe #BE6CB7,
                 Feste/Bräuche #D8D900, Vereine #007DAD, Friedhof/Kirche #77A600,
                 Stationen #FF759A, Häuser/Ortsansichten #938953)
piwigo_tags:     id_typetags smallint(5) NULL — present; 8 tags coloured
piwigo_images:   76 images, all 76 carry at least one tag
```

Phase 2 of this plan was marked "manual — user action" and its boxes are unticked, but the end state it describes is in place.

The submodule working tree is clean and on branch `master` (not detached), **3 commits ahead of `origin/master`** — the plugin-side work is committed locally but not pushed to `github.com/christianbaumann/Piwigo-Colored-Tags`.

`plugins/typetags/maintain.class.php` defines the schema the feature depends on: `install()` adds an `id_typetags SMALLINT(5) DEFAULT NULL` column to the core tags table and creates a `<prefix>typetags` table (`id`, `name`, `color`). `update()` delegates to `install()`. `uninstall()` drops both. No `activate()` is overridden.

### Plan B — `2026-04-27-picture-page-tag-assignment.md`

Frontmatter `status: draft`. All five implementation checkboxes across Phases 1–3 are ticked; all four "Automated Verification" boxes are ticked; **all 24 "Manual Verification" boxes across the three phases are unticked**.

Delivered by submodule commit `e07139f` (`add inline colored tag assignment UI on picture page`), which touched three files: `include/events_public.inc.php` (+260), `main.inc.php` (+111), `tests/test_ws_tag_assignment.php` (+447 — new).

#### Phase 1 — Web-service methods: implemented exactly as planned

`typetags.image.addTag` and `typetags.image.removeTag` are registered at `main.inc.php:93-113`, with `image_id`/`tag_id` as `WS_TYPE_ID` and `pwg_token`, and deliberately no `admin_only` option — matching the plan.

The two handlers (`main.inc.php:189-228` and `:230-268`) are **byte-for-byte identical to the code blocks in the plan**, including comments and whitespace: 401 for `is_a_guest()`, 403 for `pwg_token` mismatch, 404 for a tag whose `id_typetags IS NULL`, then `INSERT IGNORE` / `DELETE` on `IMAGE_TAG_TABLE`, then cache invalidation, then `return true`.

Two properties of the resulting security model, as implemented:

- `ws_core.inc.php:515` enforces `admin_only` via `is_admin()`; `ws_core.inc.php:510` enforces `post_only` via HTTP POST. Neither option is set on these two methods, so the framework applies no gate and both checks live entirely inside the handlers. `post_only` is not set, so the methods also answer to GET, although the injected JavaScript uses POST.
- The `is_a_guest()` check establishes that the caller is logged in. There is no check that the caller may edit the specific image — any non-guest user can add or remove a colored tag on any `image_id`.

The cache-invalidation query (`main.inc.php:221-225`, `:261-265`) is `UPDATE <user_cache> SET nb_available_tags = NULL` with no `WHERE` clause, so it clears the cached tag count for every user, not only the acting one. This matches the plan.

#### Phase 2 — Data preparation and prefilter: implemented, with one addition

`typetags_picture_tags()` (`include/events_public.inc.php:128-185`) matches the plan: guest early-return, the two queries, `get_color_text()` per unassigned tag, the same four template variables, and `set_prefilter('picture', 'typetags_picture_prefilter')`. Two small differences from the plan text: the colored-tag query also selects `t.url_name`, and the hook registration is conditional on `script_basename() == 'picture'` (`main.inc.php:38-41`).

Unlike `typetags_render()`, which memoizes through a `$typetags_cache` global (`events_public.inc.php:9-51`), `typetags_picture_tags()` runs both queries unconditionally on every picture-page load for a logged-in user.

`typetags_picture_prefilter()` (`:187-382`) performs the three planned operations. Both search strings resolve against the real template — `themes/modus/` has no `picture.tpl` of its own and inherits `themes/default/template/picture.tpl` via `'parent' => 'default'`:

| Search string | Match |
|---|---|
| `<a href="{$tag.URL}">{$tag.name}</a>` | `themes/default/template/picture.tpl:214` |
| `{if isset($metadata)}` | `themes/default/template/picture.tpl:303` |

**Addition not in the plan** — a guard at `:373-377` returns `$content` unchanged when `{if isset($metadata)}` is absent, so the JavaScript block is appended only to the main picture template. The comment states the reason: the prefilter also runs on sub-templates.

#### Phase 3 — Interactive behavior: three JS changes beyond the plan

The plan's Phase 3 said "exact changes TBD". The browser report records four issues found and fixed. Three are visible as differences between the plan's JavaScript and the shipped JavaScript:

| Plan | Implementation | Location |
|---|---|---|
| `jQuery(this).after('<span class="typetag-remove">…')` — x button placed *after* the link | `jQuery(this).find("span[style]").append(…)` — x button placed *inside* the colored badge | `:218-223` |
| `jQuery("#Tags a[data-tag-id='" + tagId + "']")` — remove handler looks the link up by id | `el.closest("a[data-tag-id]")` — walks up from the clicked x | `:308` |
| `badgeSpan.text()` for the tag name | `badgeSpan.clone().children().remove().end().text().trim()` — strips the nested x button first | `:316` |

Two further shipped details absent from the plan: a leading semicolon on the IIFE (`;(function() {`, `:212`) and `jQuery("#Tags").show()` in the add path (`:269`), matching items 2 and 4 of the browser report's fix list.

One behavior worth recording precisely: both AJAX calls disable the clicked element with `el.css("pointer-events", "none")` before the request, and re-enable it only in the jQuery `error` callback (`:282-284`, `:356-358`). The `success` callback acts only when `data.stat === "ok"` and has no `else` branch. A response that arrives with HTTP 200 but `stat !== "ok"` — which is how this web-service layer returns `PwgError` — therefore leaves the element permanently non-interactive with no message shown.

### Plan-to-implementation summary

| | Plan A | Plan B |
|---|---|---|
| Implementation steps ticked | 3/3 | 5/5 |
| Automated verification ticked | 3/3 | 4/4 |
| Manual verification ticked | 0/3 | 0/24 |
| Frontmatter status | `draft` | `draft` |
| Code deltas from plan | none | 1 addition (prefilter guard), 3 JS changes, 2 JS details |

---

## Part 2: Test Coverage of the New Functionality

### What exists

`plugins/typetags/tests/test_ws_tag_assignment.php` — 447 lines, no framework. It is a black-box script that drives the running app over curl and reads the database directly for setup and verification.

- **Transport**: curl to `http://localhost/ws.php?format=json` (`:11`, helper `ws_call()` at `:26-52`).
- **Database**: raw `mysqli('db','db','db','db')` (`:15`) — DDEV service credentials, bypassing the Piwigo db layer entirely.
- **Auth**: `pwg.session.login` with hardcoded `chriss`/`test123` (`:54-65`), cookie jar at `/tmp/typetags_test_cookies.txt`. CSRF token via `pwg.session.getStatus` (`:74-78`). Guest calls are simulated by passing `use_cookies = false`.
- **Fixtures** (`:101-133`): picks the first colored tag, picks or creates a plain tag `_test_plain_tag`, picks the first image, then deletes any existing assignment for a clean slate.
- **Teardown** (`:416-432`): re-inserts the colored tag assignment, deletes the plain tag if it created one, logs out, closes the DB, removes the cookie file. A comment at `:421` records the assumption that "image 70 had tag 1 assigned originally". `piwigo_user_cache` is mutated during the run (`:317`) and not restored.
- **Reporting**: a single `assert_test()` primitive (`:80-92`) printing `PASS:`/`FAIL:`, a summary line, and `exit(1)` on any failure, `exit(0)` otherwise. No JUnit/XML output.
- **Helpers**: `ws_call`, `login`, `logout`, `get_pwg_token`, `assert_test`, `image_has_tag`, `fetch_page`.

25 `assert_test()` call sites. One (`:328`, cache invalidation) is skipped at runtime when no `user_cache` row qualifies, so 24 or 25 assertions execute per run.

`.agent-tests/2026-04-27-tag-assignment-ui/report.md` — a browser run (rodney/showboat, git-ignored, on disk only). It logs in, loads `picture.php?/70/category/1`, clicks `.typetag-remove` once and `.typetag-add` once — each producing the bare output `Clicked` — then asserts five outcomes in prose. Ten screenshots exist on disk; only the first is referenced in the report narrative.

### Coverage map

**Phase 1 — fully covered.** All seven checklist items map to assertions: guest 401 (`:145`, `:156`), bad token 403 (`:178`, `:189`), non-colored tag 404 (`:204`, `:215`), nonexistent tag 404 (`:230`, addTag only), add succeeds + row present (`:249`, `:254`), remove succeeds + row gone (`:287`, `:292`), duplicate add idempotent (`:268`, `:273`), remove-when-absent idempotent (`:306`).

**Phases 2 and 3 — partial.**

| Checklist item | Covered by | Evidence |
|---|---|---|
| Picture page loads for logged-in user | Assertion | `:362`, `:367`, `:372` (HTTP 200, no `Fatal error`, no `Smarty Compiler`) |
| Picture page loads for guest, no UI | Assertion | `:397`, `:402`, `:407` |
| Exactly one IIFE injected | Assertion | `:383` — regression guard for the duplicate-injection fix |
| `typetag-remove` present in source | Assertion | `:388` |
| Unassigned tags render with opacity and "+" | **Neither** | `:377` is the nominal check but its condition ends `\|\| true` |
| x button appears on assigned colored tags | Prose only | report Summary. `:388` looks like coverage but is not — see below, it matches only JS source |
| Add flow moves tag into Tags row | Prose only | report `:65-75`, output `Clicked` |
| Remove flow moves tag out, row hides | Prose only | report `:53-63`, output `Clicked` |
| Round-trip works | Prose only | report Summary |
| Unassigned section hides when all assigned | Neither | — |
| Tags row created when it did not exist | Neither | — |
| Modus theme rendering | Neither | — |
| Double-click / rapid clicks | Neither | — |
| Network error leaves tag in place | Neither | — |
| Comma separators render and clean up | Neither | — |
| Image with no tags at all | Neither | — |
| Image with only non-colored tags | Neither | — |

Persistence-after-reload (both flows) is covered indirectly: `:254` and `:292` confirm the server-side row state via `image_has_tag()`, but neither artifact reloads the page and re-checks.

### Verified run

`ddev exec php plugins/typetags/tests/test_ws_tag_assignment.php` → **25 passed, 0 failed, exit code 0**. The cache-invalidation assertion at `:328` did execute (it was not skipped). `ddev exec php -l` on all six hand-written plugin PHP files reports no syntax errors on PHP 8.4.20.

### Observations about the test script itself

- `:379` — the condition is `strpos(…'typetag-add') !== false || strpos(…'typetags-unassigned') !== false || true`. The trailing `|| true` makes it unconditionally true; the assertion cannot fail.
- `:388` — "Picture page contains typetag-remove button for assigned colored tags" cannot detect what its name claims. Measured against the live page for image 1 (which does have an assigned colored tag): `typetag-remove` occurs **3 times, all of them inside the injected JavaScript source, and 0 times in server-rendered HTML**. The x button is created at runtime by `events_public.inc.php:218-223`, so the server never emits one. The server-rendered block is:

  ```html
  <div id="Tags" class="imageInfo"><dt>Schlagworte</dt><dd><a href="index.php?/tags/1-personen"
  data-tag-id="1"><span style="background-color:#FFFFB6;color:#000;padding:2px 8px;
  border-radius:12px;display:inline-block;">Personen</span></a></dd></div>
  ```

  The assertion therefore proves only that the JS block was injected — the same thing `:383` already checks — and would pass on a page with no assigned colored tags at all. The failure message at `:391` concedes this: "JS may not execute, but it should be in script".
- Image ids line up better than the teardown comment suggests: `SELECT id FROM piwigo_images LIMIT 1` returns **1**, so the fixtures and the page assertions both operate on image 1. The `:421` comment referring to "image 70" is stale — the browser run used image 70, but the script does not. The teardown restores `(image_id=1, tag_id=1)`, which matches the current DB state.
- Credentials, database name, host, and the base URL are hardcoded (`:11-15`, `:54`).
- The script asserts against page *source*, so it verifies that markup and script text were emitted, not that the JavaScript executed. No assertion in the file exercises a rendered DOM.
- `piwigo_user_cache.nb_available_tags` is set to `5` at `:317` and left as whatever the invalidation produced; teardown does not restore it.

### Test infrastructure present in the repository

| Thing | State |
|---|---|
| PHPUnit / any test framework | Absent (no `phpunit.xml`, no `composer.json`) |
| CI config | Absent (no `.github/`, no `.gitlab-ci.yml`, no `Makefile`) |
| Active git hooks | Absent — `.git/hooks` holds only `*.sample` files |
| husky / lefthook / lint-staged | Absent (no `package.json`) |
| Linter / static analysis | Absent (no PHP_CodeSniffer, PHPStan, Psalm config) |
| Test runner or aggregate command | Absent — the test is invoked by direct path |
| Other test-named files | `tools/test_piwigo.php` (unrelated upstream install smoke script); `include/smarty/src/TestInstall.php` (vendored third-party) |

`php -l` reports zero syntax errors across all 77 PHP files under `plugins/typetags/` (host PHP 8.5.7) and across the six hand-written plugin files run through `ddev exec php -l` (container PHP 8.4.20).

---

## Part 3: Patterns from an External Reference Repository

Studied for transferable mechanism only. The reference is a compiled, server-side web application built on a JVM toolchain with a component-based UI framework — a different stack in every respect, so what follows describes mechanisms, not code to copy.

### Agent rule-file organization

- A **short root instruction file with a hard length limit** (under 100 lines), holding a command table, a pointer list, and a "how work is done" section. Detail lives in separate rule files, each under 500 lines.
- **Path-scoped rule files** in a `rules/` directory, each with YAML frontmatter carrying a `paths:` glob. A rule file loads only when a matching file is being edited; files without a glob load every session. Roughly 18 files, split by concern: branches, communication style, delivery workflow, local stack, coding, architecture, error handling, testing, toolchain, and one per module.
- **Single source of truth discipline**, stated explicitly: a given rule is stated in exactly one file, and no other file may restate it.
- A **decision log** (`docs/agents/decisions/`) recording numbered decisions that the rule files then cite, including deliberate decisions *not* to add gates.
- A meta-rule: "when something here stops being true, fix it in the commit that made it untrue."
- The length limits are the one documentation constraint that is machine-checked — a guard test asserts the root file's line count, each rule file's line count, and a minimum rule-file count (so deleting the directory cannot pass by finding nothing to check).

### End-to-end test framework

- **Playwright driving a real browser, with tests written in the project's own language** and run by the project's own test runner — not a separate JS toolchain.
- **Three-layer separation, enforced by convention and stated as a rule**: test classes orchestrate and assert; page objects own the locators and interactions for exactly one view; data objects own fixtures and may read/write the database directly. A locator appearing in a test class is called out as the first step toward an unmaintainable suite.
- **E2E tests are excluded from the default test run by path glob**, and opted back in by naming them explicitly on the command line. There is no separate profile.
- **A base test class** handling browser lifecycle, viewport, tracing start/stop, and teardown where each close is independently guarded so one failure cannot leak a browser.
- **Tracing is always on**, one archive per test, written to a build-output directory.
- **Session reuse is opt-in per test class** via a storage-state file keyed by a name the class declares; classes that test identity or access control are documented as forbidden from reusing a session.
- **Fixtures via factory + builder**, generating valid randomized data scoped to a fixed reference record, with per-test cleanup that also handles the orphaned-record case where the primary handle was not captured.
- **A deployment freshness test**: one test compares a commit hash embedded in the deployed application against the working tree's `HEAD`, so testing a stale deployment fails one obvious test instead of producing confusing results elsewhere.
- **A redeploy rule**: because E2E hits a deployed artifact rather than the working tree, the documented procedure is build → clear old deployment markers → copy → wait for the ready marker → verify with a status-code check → only then run.
- **Selector policy written down**: locate by stable identifiers and by the classes the UI framework emits on purpose, never by position within framework-generated markup. A guard test greps the test sources for specific forbidden positional patterns.
- **No retries and no parallelism.** Flakiness is addressed by fixing the cause, with a stated rule that a flaky test is fixed or made deterministic, never disabled. Bare sleeps are prohibited except for three grandfathered cases that are named individually with the instruction not to add a fourth.
- **Config in properties files, secrets in a git-ignored file**, everything overridable by command-line property.

### Pre-commit hooks and quality gates

On the reference's main branch there is **no git hook and no lint gate**, by recorded decision. The reasoning captured in the decision log: the linter reports roughly 28,900 pre-existing violations, so it stays report-only and outside the definition of done rather than being ratcheted or bulk-fixed. "The gate" is a single manual command run before committing, and the rule files state plainly that nothing runs it for you.

An unmerged branch carries a fuller mechanism, built entirely on a **ratchet** principle — block new violations, leave existing ones alone:

- A **pre-commit hook** scoped to staged files only, failing on (a) a forbidden instantiation pattern in *added lines*, and (b) a size ratchet where a new file over 300 lines fails and an existing over-size file may not grow. Bypassable with `--no-verify`.
- Installed via `git config core.hooksPath <dir>` rather than copying into `.git/hooks`, so the hooks stay version-controlled. A self-test script for the hook exists.
- A **diff-scoped lint gate** that hard-fails only on files changed against the default branch, described in its own header as "fail on growth only".
- A **coverage floor** per module, defaulting to zero and intended to be raised over time.
- A **formatter check ratcheted to touched files**.
- **Architecture tests** that baseline existing violations and block only new ones.

### Backpressure that runs inside the ordinary test suite

The most transferable idea, and one that needs no CI: **structural guard tests** — ordinary tests that assert things a compiler does not watch. Examples in the reference include a schema change without its matching teardown, a generator without its seed row, a changed fixture, a test-only module leaking into the default build, a suite that was supposed to stay excluded no longer being excluded, a UI locator that became framework-version-dependent, and a module descriptor whose parent version drifted.

Also present: **commit-message discipline as prose only**, with no tool enforcing it; and **mutation testing as dated one-off scripts** kept out of the regular run, restricted by rule to the pure-unit layer.

### Test invocation

A **canonical command table** maintained in the instruction files, covering: the full gate, build-without-tests, the default test run, one class, one method, one suite by package glob, and the CI-only command marked "never run locally" with the reason. Alongside it, a list of measured runner quirks — flags that look right but do nothing, glob forms that silently match nothing — recorded as fact because they had previously been mis-documented.

### Which of these are stack-independent

Independent of language or framework: the short-root-file-plus-scoped-rule-files layout, the length guard, the decision log, the single-source-of-truth rule, the three-layer E2E separation, the selector policy, the always-on tracing, the deployment-freshness test, the redeploy procedure, the ratchet principle, `core.hooksPath` for version-controlled hooks, structural guard tests inside the normal run, the canonical command table, and the documented-quirks list.

Dependent on the reference's stack, and without a direct equivalent here: everything built on the Maven lifecycle (profiles, phase binding, module reactor), the coverage-floor and formatter plugins, and architecture tests. This repository has no package manager, no build step, and no test runner to hang any of those on.

---

## Code References

- `plugins/typetags/main.inc.php:38-41` — conditional `loc_end_picture` registration
- `plugins/typetags/main.inc.php:93-113` — the two new web-service method registrations
- `plugins/typetags/main.inc.php:189-228` — `ws_typetags_image_addTag`
- `plugins/typetags/main.inc.php:230-268` — `ws_typetags_image_removeTag`
- `plugins/typetags/main.inc.php:221-225` — unscoped `nb_available_tags` invalidation
- `plugins/typetags/include/events_public.inc.php:7-88` — `typetags_render`, badge markup and request-scoped cache
- `plugins/typetags/include/events_public.inc.php:128-185` — `typetags_picture_tags`
- `plugins/typetags/include/events_public.inc.php:187-382` — `typetags_picture_prefilter`
- `plugins/typetags/include/events_public.inc.php:218-223` — x button injected inside the badge
- `plugins/typetags/include/events_public.inc.php:282-284` — add-path error callback
- `plugins/typetags/include/events_public.inc.php:308-316` — remove-path link lookup and name extraction
- `plugins/typetags/include/events_public.inc.php:373-377` — sub-template guard
- `plugins/typetags/include/functions.inc.php:4-27` — `get_color_text`, contrast calculation
- `plugins/typetags/maintain.class.php` — `id_typetags` column and `typetags` table lifecycle
- `plugins/typetags/tests/test_ws_tag_assignment.php:26-99` — helpers
- `plugins/typetags/tests/test_ws_tag_assignment.php:101-133` — fixtures
- `plugins/typetags/tests/test_ws_tag_assignment.php:379` — assertion with `|| true`
- `plugins/typetags/tests/test_ws_tag_assignment.php:416-432` — teardown
- `include/ws_core.inc.php:510-518` — `post_only` and `admin_only` enforcement
- `include/ws_core.inc.php:316` — `addMethod` signature
- `themes/default/template/picture.tpl:214` — tag anchor, prefilter target 1
- `themes/default/template/picture.tpl:303` — `{if isset($metadata)}`, prefilter target 2
- `include/functions_user.inc.php:1560` — `is_a_guest`
- `include/functions.inc.php:2163` — `get_pwg_token`

## Architecture Documentation

The feature is implemented entirely inside the plugin — no core file is modified, matching the plan's stated approach. It uses three extension mechanisms the plugin system provides:

1. **Event handlers** (`add_event_handler`) for lifecycle hooks, registered in `main.inc.php` and conditionally gated on `script_basename()` and `defined('IN_ADMIN')` so handlers load only on pages that need them.
2. **Smarty prefilters** (`$template->set_prefilter`) for template modification by string replacement against literal template source. This is textual coupling: the prefilter's search strings must match the theme template exactly, which is why `themes/modus` inheriting `themes/default`'s `picture.tpl` matters — a modus-specific `picture.tpl` override would break both replacements silently.
3. **Web-service registration** via the `ws_add_methods` event. Because `ws.php` does not include `admin/include/functions.php`, the handlers use direct SQL, as the plan noted.

Badge styling is inline `style` attributes generated in three separate places — the PHP renderer (`events_public.inc.php:73-74`), the Smarty injection (`:199`), and twice in the injected JavaScript (`:247`, `:340`) — rather than in the plugin's `template/style.css`, which contains no rules for any of the picture-page classes.

The plugin's own `.git` is a submodule, so plugin changes are commits in a second repository. The superproject records only the commit pointer.

## Open Questions

Resolved during this research: plugin activation, schema state, and whether the test passes — all verified above.

Still open:

- **The submodule is 3 commits ahead of its origin.** The plugin work exists only locally; a fresh clone of the superproject would fetch a submodule commit that the remote does not have.
- **Both plans still say `status: draft`** and carry 27 unticked manual-verification boxes, although later artifacts cover many of them. Whether the convention is to tick these retrospectively and flip status to `complete` is not recorded anywhere in the repository.
- **The `|| true` at `test_ws_tag_assignment.php:379`** — whether this was deliberate (the assertion name mentions "or no unassigned tags", suggesting the intent was to tolerate an empty case) or a leftover is not determinable from the code or commit message.
- **Whether `:388` was meant to assert more than it does.** As written it duplicates `:383`. What it would take to actually assert the x button renders — a DOM-level check rather than a source-string check — is a design question, not a finding.
- **The test cannot currently exercise two of its own edge cases.** All 76 images carry at least one tag, so "image with no tags at all" has no fixture available without creating one; and the fixture picks the first image rather than constructing a known state.
- **No equivalent of the reference's "gate" exists**, and the repository has no build step to attach one to. What the smallest useful mechanical check would be here — and whether a `pre-commit` hook running `php -l` on staged PHP files plus the integration test is wanted — is a decision, not a finding.

---

## Follow-up Research 2026-08-28T18:14Z

Requested: testing and **test design** know-how from two reference repositories, read for what transfers. Both references are anonymised throughout, as are their domain nouns. A third source turned up during the search: skills already installed under `~/.claude/skills/`, available in this project today though nothing here points at them.

Sources, as referred to below:

- **Reference A** — a large JVM business application, layered, with a browser-driven end-to-end suite.
- **Reference B** — a smaller JVM desktop GUI application, built around a strict test pyramid.
- **Local skills** — `~/.claude/skills/given-when-then/` (SKILL.md + 6 references, 630 lines) and `~/.claude/skills/review-user-story/references/testing-heuristics.md` (150 lines).

### The technique taxonomy is already established practice

The `[HAPPY] [BVA] [NEG] [ST] [ERR]` tags used in `docs/agents/plans/2026-04-27-picture-page-tag-assignment.md:579-585` — confirmed by the user as the intended convention — are a subset of a **seven-tag vocabulary in active use in both reference repositories**:

| Tag | Meaning |
|---|---|
| `[HAPPY]` | happy path |
| `[NEG]` | negative case |
| `[ECP]` | equivalence class partition |
| `[BVA]` | boundary value |
| `[ST]` | state transition |
| `[DT]` | decision table |
| `[ERR]` | error guessing |

Reference B carries **780 tag occurrences across 16 plan documents**. Reference A uses the same brackets and additionally `[REG]` (regression) and `[NEW]`, and — unlike B — carries the tags into shipped test source as `//` and Javadoc comments, not only into plans.

Three usage details that make the tags do more than decorate:

1. **The legend is stated once and then reused.** In both references it lives in a single early plan document, not in a rules file.
2. **A technique that does not apply is recorded with its reason, not omitted.** From Reference B: *"Decision table is not applicable: there is one condition and two outcomes. State transition is not applicable: the type is immutable and the catalogue is queried once."* From Reference A, for a scheduling fix: *"Not directly applicable — the fix does not change state transitions."*
3. **Tags attach to individual test-case bullets**, so the plan doubles as the coverage map: `` `bitsPerPixel(8, "Gray", false)` is 8 `[ECP]` ``.

Reference A also keeps a **"Tests NOT required (with justification)"** table, recording deliberate non-coverage in the same technique vocabulary — e.g. a private duplicate method left untested because *"Duplicating for a private method adds no equivalence class coverage."*

### The wider catalogue behind the seven tags

Reference A states a catalogue (sourced from a testing course) of nine techniques with an "apply it when" column:

| Technique | Apply when |
|---|---|
| Boundary value analysis | An input, output or behaviour has a stated limit |
| Equivalence class partitioning | A field accepts a wide variety of inputs |
| Use case testing | An end-to-end workflow must be validated |
| Decision tables | Rule-based logic maps inputs to outputs |
| State transition testing | The application changes state, validly and invalidly |
| Error guessing | Experience names an error-prone area |
| User persona analysis | Different user types behave differently |
| Model-based testing | The workflow is complex or state-dependent |
| Pairwise / combinatorial | Variable combinations explode |

Of these, the first six are applied with worked examples; use case testing, model-based testing and pairwise appear in the catalogue only. Classification trees appear nowhere. Reference A also self-documents an absence: certain heuristic mnemonics *"appear nowhere in Module 9. A grep across every file returns zero hits."*

**Case-finding heuristics** recorded across Reference A and the local skills: Zero-One-Many; Beginning-Middle-End; Too Few / Too Many; Goldilocks; Some-None-All (watch for None treated as All); CRUD across every entity; Follow the Data through its whole lifecycle; Interrupt (kill, disconnect, time out, cancel); Starve (CPU, memory, disk); Reverse (undo everything, work backward); Never and Always (inviolate rules); Violate data format rules; Count / Position / Selection / Sequences; RCRCRC for regression selection (Recent, Core, Risky, Configuration-sensitive, Repaired, Chronic).

**Web-specific heuristics** from the local skills, several directly applicable to a PHP gallery: back/forward/history producing duplicate transactions or partial data; bookmarking mid-flow; URL parameter manipulation; injection through text fields and URLs; special characters and UTF-8; copy-paste versus typing bypassing validation; browser operations during a critical flow.

**API mnemonics** (Reference A): BINMEN (Boundary, Invalid entries, NULL, Method, Empty, Negative), POISED (Parameters, Output, Interoperability, Security, Errors, Data), VADER (Verbs, Authorisation, Data, Errors, Responsiveness).

**Mnemonics for judging a check rather than a feature** (Reference A): SACRED (State management, Actions, Codified oracle, Reporting, Execution, Deterministic) and TRIMS (Targeted, Reliable, Informative, Maintainable, Speedy).

**Oracles** — the local skills record the seven heuristic consistencies for deciding something is wrong: consistent with history, with our image, with comparable products, with claims, with user expectations, within the product, and with purpose.

### Deriving cases from a specification

Reference B runs a three-amigos process in which the agent plays each role in turn, and the testing voice has a defined job:

> "Testing: how it fails. Boundaries, the empty case, the single-element case, and the concrete example that separates two readings of the same sentence." … "Do not blend them into one voice: the value is in the disagreement between them."

It pairs this with a **research-before-writing ladder** and a blanket prohibition on guessing:

> "Never guess and never assume. Not the behaviour, not the value, not the shape. A sentence that starts 'presumably' or 'it is probably' is a sentence to delete, not to hedge."
> "Never produce an untested artifact to be corrected later."

A finding only inferred from a source, never stated by one, is *"labelled unverified everywhere it is written down, the question included."*

Rules on scenario content, from Reference B and the local `given-when-then` skill:

- Scenarios are declarative — *"no clicks, no waits, no key-by-key transcripts unless the key itself is the requirement."*
- One When step, one behaviour. Several When steps means several scenarios.
- *"Scenario count is not a quality metric: ten scenarios restating one rule are worse than three that separate three rules."*
- *"Logic in a step definition is logic with no unit test."*
- No scenario depends on another's result.
- Data in a When step is a warning sign — keep data in Given or Then so boundaries stay findable.
- If no user would notice the outcome, it is a unit test written in Gherkin and belongs a layer down.

Techniques for choosing the examples themselves (local skills): **Simple-Counter-Boundary** (simple examples, then counter-examples that could disprove the proposed rule, then probe where meaning changes); **Chat-Choose-Check**; *"show boundaries, not just ranges"* — showing 19, 20, 21 forces agreement on which side 20 falls; a comment column naming each example's purpose; **split validation from processing** to prevent combinatorial explosion; and Copeland's seven perspectives of coverage (statements, decisions, conditions, condition/decision combinations, multiple-condition combinations, loop iterations, paths).

### Judging whether a test is worth anything

This is the most developed area in both references, and the part that bears directly on the two weak assertions found in this repository.

**Watch it fail first**, stated identically in both:

> "Write it, run it, and watch it fail before the code it describes exists. Read the failure and check it is the one expected, since a test can fail for a reason that has nothing to do with it."

Reference A adds a **three-step protocol for proving a check can fail**: run it repeatedly, alone and in a suite; break the system and confirm it goes red; reverse or break the assertion and confirm it goes red. Step two is confined to the unit layer.

**Mutation testing**, in two different shapes:

- Reference B keeps it as **recorded prose, deliberately not a tool**: *"The mutants stay prose. A mutation test states that a test has teeth, and no assertion in the suite can hold that claim. A script that patches a source file and reverts it is a second thing to keep correct."* The record is a table of mutant → killed by, and it names what did **not** move: *"Nothing else moved."* A mutant too weak to kill is itself recorded as proving nothing.
- Reference A uses hand-written scripts, scoped by an explicit rule: mutation belongs to the unit level and nothing else. *"Never mutate for a UI test"* — a red end-to-end run does not say which mutation caused it. *"Never mutate for a structural guard test."* Where a UI test is the only witness for a rule, that is *"a gap in the pyramid. Close it by pushing the rule down, not by mutating the browser."*

Reference B records mutation testing catching a genuinely vacuous test: a cancellation test that *"passed with the cancellation check deleted, because the context never suspends"*.

**Decision for this repository (user, 2026-08-28): mutation testing applies to unit tests only.** This matches Reference A's rule verbatim in scope — never for a UI or end-to-end test, never for a guard or document test, never for a performance test.

The immediate consequence is that the rule currently binds nothing. This repository has no unit tests: `plugins/typetags/tests/test_ws_tag_assignment.php` drives a running application over HTTP and reads the database directly, so every one of its 25 assertions sits at the integration or end-to-end layer. Under this decision, none of them is a legitimate mutation target. The functions that could carry unit tests — `get_color_text()` (`plugins/typetags/include/functions.inc.php:4-27`, a pure hex-to-contrast calculation with 7-char, 4-char and empty-input branches) and the unassigned/assigned partition logic inside `typetags_picture_tags()` (`include/events_public.inc.php:156-175`) — have no tests at all today, unit or otherwise.

Reference A's reasoning for the same boundary is recorded as: a red end-to-end run does not say which mutation caused it, so the answer is not worth what it costs; and where a higher-layer test is the only witness for a rule, *"that is a gap in the pyramid. Close it by pushing the rule down, not by mutating the browser."*

**Anti-vacuity guards** — assertions whose job is to stop another test from passing over nothing:

- A count guard: a table-driven permission test holds the number of covered methods at 28, so a new method with no table entry fails the suite.
- Reference A's matrix test carries a dedicated case asserting the matrix actually covers cells, because if the fixture query returned nothing the tests above it would run over zero rows and be *"silently green"*.
- Reference B's oracle test carries a third case whose only job is to prove the oracle is configured correctly, so agreement between implementation and oracle cannot be *"two identical mistakes"*.
- A build-level `failOnNoDiscoveredTests` flag, with its blind spot documented rather than assumed: it catches zero discovery, not partial silent non-discovery.

**Tests whose oracle is the code** must say so. Reference A: *"A test whose oracle is the code cannot find a defect in that code. It reports a change. So each of these tests carries a comment that says which behaviour it records and that no requirement confirms it. They are a safety net for a refactor, and nothing more."*

**A known gap becomes a deliberately failing test** rather than silence: Reference A writes the test for the required behaviour and marks it ignored with the reason — *"Fails by design. Records the default-allow defect."*

**Flakiness**, identically in both: *"A flaky test gets fixed or made deterministic. It does not get disabled."* And for thresholds: *"Never quietly widen a budget to make a build pass."*

### Coverage thinking

Both references state the same placement rule, in near-identical words: *"Put each behaviour at the lowest layer that can express it, and do not restate it higher up."*

Both also state the same adequacy test, which is the closest either comes to defining "adequately covered" — the **anti-regression cross-check**:

> "When you break a low-level function, its own test must fail before the UI test does. If the UI test fails first, coverage has not been pushed down far enough."

Placement heuristic: *"ask what has to break for it to fail. One function, unit. Two parts meeting across a real boundary, integration. The shipped artifact, end-to-end."*

Reference A adds that four testability constraints legitimately push a test upward — ability, complexity, delivery, time — and that when one does, the stated cost is accepted rather than hidden. It also separates **checking** from **testing**: checking applies decision rules to observations and automates completely; testing includes questioning, modelling and inference and does not automate at all. Usability characteristics, new functionality before validation, and stable rarely-regressed functionality are named as deliberately manual.

Two rules about what not to write:

- Do not test unreachable equivalence classes: *"Testing unreachable paths adds no value and would require artificial setup that doesn't reflect production data."*
- Where a business rule is ambiguous, write no test until it is decided — an ambiguous rule must not be pinned by a test that picks one reading.

Reference B keeps a **hand-check ledger** for behaviour no automated layer can reach, recording each dated manual check and which automated test has since replaced it.

### Test data design

Reference A records four state-management patterns with their drawbacks (system profiles, database seeding, stubs/fakes/mocks, data builder) and concludes the builder survives a change of mechanism. Its operational rules:

- Set data up through the fastest available layer, never through the UI.
- Keep tests independent, because runners randomise and parallelise.
- **Wait for events, never for time.**
- **Prefer setup-before over cleanup-after** for asynchronous data, *"because cleanup is skipped on failure and it destroys the failure evidence."*
- Random draws as fixtures are recorded as a design flaw and replaced with seeded rows.
- Persona rights were *"chosen to make a boundary visible rather than to enumerate the rights."*

Reference B maintains a fixture corpus with a **manifest carrying provenance, licence, checksum, purpose and the generating recipe per file**. Synthetic fixtures are generated from solid colours so no third-party licence applies and expected values are known exactly; a handful of real files exist because *"flat colour decodes far faster than detail at the same size, so a budget measured against a generated image passes while the real thing was slow."* Numeric values carry their derivation — one fixture is sized specifically to land in the band where a formatter switches units, and performance thresholds are *"the measured p95 plus about 20 percent, so the gate does not flap on machine variance."* An anti-redundancy rule governs additions: *"Cover a case that actually differs. A second JPEG at a different quality proves nothing the first does not."*

Reference A notes the opposite state as a known gap: *"Provenance is not recorded for either. No manifest says where a sample came from or what case it covers."*

### Correspondence with what this repository has

Stated as observation, not recommendation.

| Mechanism in a reference | State here |
|---|---|
| Seven-tag technique vocabulary, legend stated once | Five of seven tags used, in one plan file; no legend, nothing codifying it |
| Technique recorded as not-applicable with a reason | Not present |
| Tags carried into test source | Not present — the test file has no technique annotations |
| "Watch it fail first" | Not stated anywhere in this repository |
| Anti-vacuity guard on a fixture query or count | Not present; `test_ws_tag_assignment.php:379` and `:388` are the cases such a guard addresses |
| `failOnNoDiscoveredTests` equivalent | No runner, so no equivalent |
| Mutation testing, prose or scripted | Not present. Confirmed as unit-tests-only for this repo; binds nothing today, since no unit tests exist |
| Change-detector tests labelled as such | Not applicable — no such tests |
| Anti-regression cross-check | Not stated; the coverage map shows UI behaviour witnessed only at page-source level |
| Fixture manifest with provenance and purpose | Not present; fixtures are picked with `LIMIT 1` from live data |
| Setup-before over cleanup-after | The test uses cleanup-after and does not restore one table |
| Flaky-test rule, threshold-widening rule | Not stated |

### Follow-up open questions

- **Where the tag legend would live.** Both references put it in a plan document rather than a rules file, and both note it is not codified. This repository has neither.
- **Whether `[ECP]` and `[DT]` join the five confirmed tags**, giving the same seven both references use.
- **Whether the local skills should be referenced from this repository.** `~/.claude/skills/given-when-then/` and `~/.claude/skills/review-user-story/` carry most of the design material above and are available in every project, but nothing in CLAUDE.md or any plan here points at them, and Reference B makes invoking its scenario skill mandatory rather than optional.
- **What an anti-vacuity guard looks like without a test runner.** Both references rely on runner or build features that have no counterpart in a repository with no build step.
- **Whether a unit layer gets created at all.** The unit-tests-only mutation rule is settled, but this repository currently has no unit tests for it to apply to. Whether `get_color_text()` and the tag-partition logic acquire direct tests — and what would run them, given there is no runner — is undecided.
