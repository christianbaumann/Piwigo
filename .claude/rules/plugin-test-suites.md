# Plugin Test Suites

Read before running or adding a test in `plugins/*`, and before claiming any check passed.
General, stack-independent test rules live in `testing.md`, `test-design.md`,
`mutation-testing.md`, `e2e-tests.md`, `backpressure.md` and `precommit-hooks.md` — this file
covers only what is specific to this repository's plugins.

## The suites

General test-design, layering, and quality-gate rules (stack-independent) live in
`.claude/rules/testing.md`, `test-design.md`, `mutation-testing.md`, `e2e-tests.md`,
`backpressure.md`, and `precommit-hooks.md` — this section covers only what's
Piwigo/typetags-specific.

`docs/agents/TESTING.md` is the project-facing record: the technique legend, the deliberate
non-coverage table, the unit suite's mutant table, and the hand-check ledger of what has no
oracle. Check it before adding a test — an omission there may be a recorded decision rather
than a gap.

Piwigo core has no test suite. All three plugins carry a PHPUnit suite of their own (`plugins/typetags/`, `plugins/provenance/`, `plugins/persons/`), and all three carry a Playwright suite. The typetags commands:

```bash
# Unit — pure functions, no DDEV, no DB, no HTTP
ddev exec plugins/typetags/vendor/bin/phpunit --testsuite unit --configuration plugins/typetags/phpunit.xml

# Integration — needs DDEV up; hits ws.php over curl and MariaDB directly
ddev exec bash -c 'set -a; . local/config/typetags-test.env; set +a; \
  plugins/typetags/vendor/bin/phpunit --testsuite integration --configuration plugins/typetags/phpunit.xml'

# E2E — needs DDEV up; drives Chromium in the container against http://localhost/
ddev exec bash -c 'set -a; . local/config/typetags-test.env; set +a; \
  cd plugins/typetags && npx playwright test'
```

No plugin's suite takes a human's login. Each creates its own accounts with generated passwords — see *Test accounts* in `.claude/rules/testing.md`:

```bash
# once per install (also rotates the passwords)
ddev exec php plugins/typetags/tests/Support/create-test-users.php
```

That writes the git-ignored `local/config/typetags-test.env` and creates `typetags_webmaster` and `typetags_normal`. `tests/Support/Config.php` and `tests/e2e/auth.setup.js` each fail fast naming both the missing variable and the script that creates it. Everything else defaults to DDEV values. Like the provenance script it writes user rows directly and is never safe against a production database. A fresh clone needs `git submodule update --init --recursive` **first** - `plugins/typetags` is a submodule, so a plain clone leaves that directory empty and every typetags command fails on a missing `composer.json` (measured 2026-08-31 against a real clone). Then `ddev exec composer install -d plugins/typetags` and `ddev exec bash -c 'cd plugins/typetags && npm install'`, and the same two for `plugins/provenance` and `plugins/persons` (neither is a submodule, so both arrive with the clone). Dependency and run output (`vendor/`, `node_modules/`, `test-results/`, `playwright-report/`, the pinned browser cache, the E2E suite's `tests/e2e/.state/`) is git-ignored by each plugin's own `.gitignore`.

The provenance suite does not take a human's login. It creates its own accounts — see
*Test accounts* in `.claude/rules/testing.md`:

```bash
# once per install (also rotates the passwords)
ddev exec php plugins/provenance/tests/Support/create-test-users.php

ddev exec plugins/provenance/vendor/bin/phpunit --testsuite unit --configuration plugins/provenance/phpunit.xml
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; \
  plugins/provenance/vendor/bin/phpunit --testsuite integration --configuration plugins/provenance/phpunit.xml'

# E2E - drives Chromium in the container against the admin album screen
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; \
  cd plugins/provenance && npx playwright test'
```

All three plugins share one pinned browser cache: `PLAYWRIGHT_BROWSERS_PATH` in `.ddev/config.yaml`
points at `plugins/typetags/.playwright-browsers`, so a fresh clone installs browsers once.

That script writes the git-ignored `local/config/provenance-test.env` and creates
`provenance_webmaster` and `provenance_normal`. It writes users directly and is never safe
against a production database.

The persons suite works the same way:

```bash
# once per install (also rotates the passwords)
ddev exec php plugins/persons/tests/Support/create-test-users.php

ddev exec plugins/persons/vendor/bin/phpunit --testsuite unit --configuration plugins/persons/phpunit.xml
ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; \
  plugins/persons/vendor/bin/phpunit --testsuite integration --configuration plugins/persons/phpunit.xml'

# E2E - drives Chromium in the container against the public picture page
ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; \
  cd plugins/persons && npx playwright test'
```

It writes the git-ignored `local/config/persons-test.env` and creates `persons_webmaster` and
`persons_normal`, and marks the install with a `persons_throwaway_install` config row that
`FixtureBuilder` refuses to run without. Never point it at a production database.

`IndexRebuildTest` is the one destructive outlier: it **uninstalls the plugin**, which drops both
tables, then reinstalls and rescans the whole gallery through `pwg.persons.rescan`. It snapshots
both tables verbatim first and puts them back in teardown, so a failure - which is exactly the case
where the rescan did not restore everything - does not leave the install short. It also asserts
"nothing the index held is missing", never "the index came back byte-identical": a rescan reads
every file, so a file whose regions were never indexed contributes new rows, and demanding
otherwise would assert that no file in the gallery has ever drifted.

The persons integration suite also creates a **private** album, to prove the per-image visibility
gate ([decision 0019](../../docs/agents/decisions/0019-person-region-permission-model.md)). That
needs `piwigo_user_cache` thrown away — `$user['forbidden_categories']` is cached per user, so a
private album created after the cache was computed is invisible to the gate and the `[NEG]` case
would pass for the wrong reason. `FixtureBuilder::invalidateUserCache()` does it; core recomputes
the rows on the next request, so nothing is left broken, but every user's cached tag and photo
counts are rebuilt once after a run.

`plugins/persons` got its first Playwright specs with the public overlay in Phase 5;
`docs/agents/decisions/0017-no-e2e-tests-for-persons-phases-1-to-4.md` records why the four phases
before it had none, and still holds for them. Unlike the other two suites, persons' `auth.setup.js`
logs in as `persons_normal` rather than the webmaster: the overlay is shown to any logged-in
non-guest, and running it as an administrator would hide a permission mistake only a normal
account can find. It saves a second, webmaster session to `tests/e2e/.state/auth-admin.json` as
well, because the admin tagging screen added in Phase 7 sits behind `ACCESS_ADMINISTRATOR`. The
`chromium` project runs as the normal account every spec whose file name does **not** start with
`admin`; `chromium-admin` runs the `admin*.spec.js` ones as the webmaster, and the one spec there
that proves a normal account is refused overrides the storage state for itself.

`tests/e2e/support/seed.php` creates a throwaway album and a copied photo, writes two MWG regions
into that photo's file with a plain exiftool call, indexes them, and prints the box corners the
specs assert against — computed with the same pure helpers the page uses, so no spec carries a
second copy of the conversion. Three scenarios: `--scenario=overlay` writes the photo's own
`AppliedToDimensions`, so nothing is stale; `--scenario=stale` writes a ratio no crop of the photo
could have, which is what a region written before a re-crop looks like; `--scenario=empty` writes
nothing into the file at all, which is what the editor specs start from — anything found in that
file afterwards was put there by the browser. `seed.php --read-file-regions=<id>` reads one photo's
regions back with a plain exiftool call in its own process, which is how a spec asserts a write
landed without asking the plugin's own parser. `seed.php --person-counts` prints every person's
photo and region counts straight from the database, which is the oracle the persons admin
screen's own numbers are compared against - a browser cannot reach MariaDB, and a screen that
agreed only with itself would prove nothing. `seed.php --exiftool=missing|present` writes or
removes a `persons_exiftool_path` config row pointing at a directory holding no binary, which is
how the disabled-editor spec forces a host without exiftool rather than waiting for one.
`--restore` deletes the album, the photo row and the
copied file with exiftool's `_original` sidecar when it finds a snapshot, and — before it even
looks for one — removes that config row and the persons the specs create through the UI. Both are
unconditional because both outlive a snapshot: a spec killed mid-run would otherwise leave every
later page without an editor, and a leftover person puts a stranger at the top of the next run's
picker, where `PicturePage.typeName()` would commit them. It rewrites an image file in place, so it
is never pointed at a real scan.

`admin-persons.spec.js` presses **Rescan all files**, which re-reads every photo in the gallery -
the seeded one and the install's own alike - because that is what the button does. The index is
derived from the files, so the run rebuilds it; on an install whose photos carry regions written
elsewhere it also indexes those, and `--restore` does not remove them. One more reason none of
these suites is safe against a production install.

`PicturePage.settle()` is the one to reuse after a viewport change, not `waitForPlacement()`: after
a resize the theme may swap in another derivative — which only changes the rendered size once that
file has loaded — while `overlay.js` debounces its own redraw, so a check that merely asks whether
the overlay matches the photo *right now* is satisfied by the layout from before the resize. It
demands the layout hold still across `PicturePage.SETTLE_FRAMES` consecutive animation frames.

Both the integration and the E2E suite mutate the database and restore it (`tests/Support/FixtureBuilder.php`; the E2E suite reaches the same builder through `tests/e2e/support/seed.php`, which persists the original state to the git-ignored `tests/e2e/.state/snapshot.json` so a later process can put it back). Neither is safe against a production install.

Two provenance E2E scenarios seed throwaway albums of their own rather than touching real scans:

- `seed.php --scenario=writeback` — one album of copied photos. The write-back writes **every**
  photo of the album it is started from, so it is never pointed at an album holding real scans.
- `seed.php --scenario=move` — a source and a destination album with one copied photo, for the
  Batch Manager move prompt. A move rearranges the gallery, so it never moves a real scan.

`--restore` deletes those albums, their photo rows, the copied files and exiftool's `_original`
sidecars. `seed.php --read-photo=<id>` reads one photo's provenance columns back, for outcomes
the browser cannot show.

E2E layout: `playwright.config.js` sits at the submodule root so the command above needs no `--config`, with `testDir: './tests/e2e'`. Every locator lives in a page object under `tests/e2e/support/` (`PicturePage.js`, `AlbumPropertiesPage.js`, `PhotoPropertiesPage.js`, `BatchManagerPage.js`) — specs orchestrate and assert, and a locator in a spec file is a bug. `retries: 0`, `workers: 1`: a flaky test gets fixed, never retried into green.

**Clear `_data/templates_c/` after editing a Smarty prefilter.** `Template::set_prefilter()` hashes only the filter's *callback name* into Smarty's `compile_id` (`include/template.class.php:1060-1070`), not the callback's source. Editing `typetags_picture_prefilter()` therefore leaves the previously compiled `picture.tpl` in place, and the page — and the integration suite reading it — keeps showing the old injection with no error.

Browser-level verification is done with `uvx rodney` (drive Chrome) and `uvx showboat` (report). The Chrome profile lands in the git-ignored `.rodney/`.

## No lint, no CI

Piwigo core has no `composer.json`, no `package.json`, no `.github/`, no CI pipeline, and no linter or static-analysis config (no PHP_CodeSniffer, PHPStan, or Psalm). The plugins are the exception: `plugins/typetags`, `plugins/provenance` and `plugins/persons` each carry their own `composer.json` (PHPUnit) and `package.json` (Playwright), all dev-only, with `vendor/` and `node_modules/` git-ignored per plugin.

The mechanical checks available are:

```bash
php -l <file>                  # syntax check; use ddev exec php -l for 8.4 parity
ddev exec plugins/typetags/vendor/bin/phpunit --configuration plugins/typetags/phpunit.xml
bash tools/test-hooks.sh       # self-test for the commit gate below
```

Everything else is manual or browser-driven. Don't claim a lint or test pass that no command produced — say which of the above actually ran.
