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

That writes the git-ignored `local/config/typetags-test.env` and creates `typetags_webmaster` and `typetags_normal`. `tests/Support/Config.php` and `tests/e2e/auth.setup.js` each fail fast naming both the missing variable and the script that creates it. Everything else defaults to DDEV values. Like the provenance script it writes user rows directly and is never safe against a production database. A fresh clone needs `ddev exec composer install -d plugins/typetags` and `ddev exec bash -c 'cd plugins/typetags && npm install'` first, and the same two for `plugins/provenance` and `plugins/persons`. Dependency and run output (`vendor/`, `node_modules/`, `test-results/`, `playwright-report/`, the pinned browser cache, the E2E suite's `tests/e2e/.state/`) is git-ignored by each plugin's own `.gitignore`.

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
account can find.

`tests/e2e/support/seed.php --scenario=overlay` creates a throwaway album and a copied photo,
writes two MWG regions into that photo's file with a plain exiftool call, indexes them, and prints
the box corners the specs assert against — computed with the same pure helpers the page uses, so
no spec carries a second copy of the conversion. `--restore` deletes the album, the photo row, the
copied file and exiftool's `_original` sidecar. It rewrites an image file in place, so it is never
pointed at a real scan.

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
