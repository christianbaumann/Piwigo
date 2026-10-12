---
datetime: 2026-10-12T14:03:06+02:00
author: Christian Baumann
tags: [permissions, roles, testing, typetags, provenance, persons, photoinfo, photoedit, core]
---

# Role test coverage: design

The role permission change
([`2026-10-11-role-permissions`](../2026-10-11-role-permissions/design.md), all 11 tasks done) gave
each fork function a target access level per role. Each task tested its own change. Nobody has yet
checked that **every cell** of the matrix has a test. This change adds those tests.

Goal: every cell (function × role) has tests that make a changed gate fail a named test. Cells
whose level did not change get the same depth of coverage as the cells that changed.

## Coverage today

An audit on 2026-10-12 mapped the existing tests to the 34 × 4 = 136 cells of the matrix in
[`docs/agents/research/2026-10-11-role-permissions.md`](../../../docs/agents/research/2026-10-11-role-permissions.md).
It counted only whether a cell has at least one test, not how deep that testing goes:

```text
            G   N   A   W
covered    28  30   8  30   = 96
partial     6   2   1   1   = 10
missing     0   2  25   3   = 30    (missing Admin cells: all typetags, provenance, core rows)
```

A "covered" cell usually has a single `[HAPPY]` or `[NEG]` case. It does not have the set from
[`test-design.md`](../../../.claude/rules/test-design.md). The audit also found these gaps:

- **No Admin account in typetags and provenance.** Both have only `_webmaster` and `_normal`.
  [0062](../../../docs/agents/decisions/0062-provenance-writeback-keeps-no-webmaster-check.md)
  left the Admin write-back as a hand check for this reason.
- **Guest cells covered by a stand-in.** `GuestLockoutTest` requests 3 pages and 4 ws methods.
  Eight guest cells count as covered only because their whole page is locked. No test sends a
  guest to `admin.php`.
- **Single missing cells:**
  - `photoinfo.tag-keywords` N (an `edit` cell, research decision 5)
  - `typetags.write-warning` N
  - `typetags.type-new-tag` W (every `addNewTag` case runs as normal)
  - `persons.name-list` W
  - `core.zoom` A and W (every zoom spec runs as normal)
  - the admin tagging save as A
  - `admin.php?page=album` and `page=photo` refused to N
- **Only one half tested:** for some `edit` and `none` cells, only the control or only the ws
  method has a test, not both. Examples: `photoinfo.core-screens` N (ws refused, `page=photo`
  never requested), `persons.admin-tagging` N (E2E only).
- **The `generic` status has no test anywhere.** Core gives it `ACCESS_GUEST`, so it passes every
  page gate. The plugins admit it through `!is_a_guest()`. Two plugins say so in a comment
  (`plugins/typetags/include/taggroups.inc.php:21`, `plugins/provenance/include/events_page.inc.php:28`).
  If a gate changed to `is_classic_user()` (`ACCESS_CLASSIC`), it would refuse `generic` accounts
  and no test would fail.

Tests are organized by feature or ws method (`SetDateTest`, `AddTagTest`, `RegionDrawerTest`).
Role cases are single methods or data providers. Nothing shows the matrix as a whole.

## The target matrix

This is the research matrix with the design's *Matrix changes* applied. `see*` = see on a read-only
page ([0064](../../../docs/agents/decisions/0064-normal-users-read-provenance-and-tag-groups-on-read-only-pages.md)).
`own` = only regions the account drew
([0065](../../../docs/agents/decisions/0065-normal-users-delete-only-the-regions-they-drew.md)).
The `generic` column always equals N.

| Suite | ID | G | N / generic | A | W |
|---|---|---|---|---|---|
| provenance | core.zoom | none | edit | edit | edit |
| provenance | core.subalbum-count | none | see | see | see |
| provenance | core.merge-buttons | none | none | see | see |
| provenance | core.german-strings | none | see | see | see |
| provenance | core.handbook | none | see | see | see |
| typetags | typetags.badges | none | see | see | see |
| typetags | typetags.assign-remove | none | edit | edit | edit |
| typetags | typetags.type-new-tag | none | edit | edit | edit |
| typetags | typetags.write-warning | none | see | see | see |
| typetags | typetags.groups | none | see* | edit | edit |
| typetags | typetags.admin-chips | none | none | see | see |
| provenance | provenance.public-row | none | see | see | see |
| provenance | provenance.album | none | see* | edit | edit |
| provenance | provenance.apply | none | none | edit | edit |
| provenance | provenance.writeback | none | none | edit | edit |
| provenance | provenance.photo-note | none | see* | edit | edit |
| provenance | provenance.move-prompt | none | none | edit | edit |
| provenance | provenance.inherit | none | none | edit | edit |
| provenance | provenance.history | none | see* | see | see |
| persons | persons.overlay | none | see | see | see |
| persons | persons.names-as-tags | none | see | see | see |
| persons | persons.add-region | none | edit | edit | edit |
| persons | persons.delete-region | none | own | edit | edit |
| persons | persons.name-list | none | see | see | see |
| persons | persons.admin-screen | none | none | see | edit |
| persons | persons.admin-tagging | none | none | edit | edit |
| photoinfo | photoinfo.rows | none | see | see | see |
| photoinfo | photoinfo.edit-date | none | edit | edit | edit |
| photoinfo | photoinfo.edit-info | none | edit | edit | edit |
| photoinfo | photoinfo.core-screens | none | none | edit | edit |
| photoinfo | photoinfo.tag-keywords | none | edit | edit | edit |
| photoinfo | photoinfo.rescan-prune | none | none | edit | edit |
| photoedit | photoedit.turn-crop | none | edit | edit | edit |
| photoedit | photoedit.warnings | none | see | see | see |

The research note is a dated record and stays as it is. After this change, the executable copy of
this table is the set of `RoleMatrixTest` data providers.

## How a cell is checked

Each matrix row has a **probe**: a function that sends the cell's real requests as one role and
returns the level it **observed**. The row then asserts `observed === expected`.

```text
RoleMatrixTest::testTheRoleGetsItsLevel(function, role, expected)
        │
        ▼
probe<Function>(role) ──► page request  ──► content shown?  control shown?
                     └──► ws request    ──► accepted?  refused (401/403/404)?
        │
        ▼
observed = none | see | edit | own | INCONSISTENT
        │
        ▼
assertSame(expected, observed, "<function> as <role>")
```

| Content shown | Control shown | ws accepted | observed |
|---|---|---|---|
| no | no | no | `none` |
| yes | no | no | `see` |
| yes | yes | yes | `edit` |
| any other combination | | | `INCONSISTENT: <what was seen>` |

- **Both halves in one row.** The control and the ws method are always checked together. If the
  button is shown but the method refuses, or the method accepts a call while the button is hidden,
  the result is `INCONSISTENT`. It is never a pass.
- **Not every function has all three parts.** A function without a page part (`rescan-prune`,
  `provenance.inherit`) or without a ws part (`core.subalbum-count`) declares that, and its probe
  checks only the parts it has.
- **`own`** (persons.delete-region): the probe deletes one region the account drew and one drawn
  by another account. The first must be accepted and the second refused.
- **Guest** (decision 4b of the brainstorm): every guest row requests **that function's own** page
  or ws method, `admin.php?page=…` included. A guest lockout of the gallery page does not count in
  place of the function. With `guest_access` off, a guest is sent to the login page or refused with
  401. The probe reads either as `none`.

Each row declares the ws methods its probe calls. The structural guard reads these declarations.

## Where each depth lives

```text
                    one row per cell, 5 roles           depth per function
                    (G, generic, N, A, W)
RoleMatrixTest ───► [HAPPY] at the role's level
                    [NEG]   one level above it          feature tests (existing files)
                    [BVA]   role boundaries:      ───►  [BVA] visibility: public / private /
                            G|generic|N|A|W                    private + grant
                    [ECP]   generic vs normal           [DT]  role × visibility × ownership
                                                        [ST]  drawn_by across a reindex
                                                        [ERR] core characterization
```

The matrix rows prove the **role** dimension. The feature files keep the dimensions that combine
with role, and they gain cases where those combinations are missing for a role. For example, the
private-album `[NEG]` case of `SetDateTest` gains `generic`. Only a missing case is added. A feature
file does not get a full grid over every role.

## Key Decisions

### One `RoleMatrixTest` per plugin is the executable matrix

- **Decision:** Each plugin gets `tests/Integration/RoleMatrixTest.php`. Its data provider yields
  one row per (function, role) with the expected level, and a probe per function computes the
  observed level. The core rows go into provenance's file, next to the `Core*CharacterizationTest`
  files. typetags' file lives in the submodule and is pushed to `christianbaumann/Piwigo-Colored-Tags`.
- **Reason:** Each cell becomes a named, visible row, and a changed gate fails exactly that row.
  Matching the `test-design.md` legend: role is the equivalence class, and the neighbouring role is
  the boundary.
- **Trade-offs:** Some overlap with the feature files. An existing case that checks only "role X
  gets level Y" for the same request is **deleted** once its matrix row exists. The mapping (old
  test → matrix row) goes into the commit message (`testing.md`, *superseded test*). A case that
  also checks content, a boundary or a combination stays. Rejected: filling the gaps only in the
  feature files, because nothing would show the matrix as a whole. Rejected: a cell → test table in
  `TESTING.md`, because that is a document that maps tests, and it rots.

### The row's oracle is the matrix, not the code

- **Decision:** The expected levels are typed from the target matrix above. The rows are therefore
  tests against a requirement, not `[ERR]` characterization tests. Each row is tagged with the
  technique it applies: `[HAPPY]` at the expected level, and `[NEG]` / `[BVA]` for the role
  boundaries it crosses.
- **Reason:** The owner set the matrix on 2026-10-11 as the requirement.
- **Trade-offs:** None of the rows was watched failing before its gate existed, because the gates
  are already built. Their strength check is the hand flip described in the last decision.

### Integration is the layer for gates; E2E only where JavaScript builds the result

- **Decision:** `RoleMatrixTest` is an integration suite: real session, real `ws.php`, real
  server-rendered page. These cells also get one E2E row per role, because client JavaScript builds
  what the role sees:
  - `core.zoom`
  - `persons.overlay`, `persons.add-region` (editor), `persons.delete-region` (menu),
    `persons.name-list` (picker)
  - `typetags.assign-remove` and `typetags.write-warning` (badge and warning, built after the ws
    answer)
  - `photoedit.warnings`

  These go into one `roles.spec.js` per plugin that has such cells. It runs once per role through
  the stored sessions of `auth.setup.js`.
- **Reason:** The role comes from the session. The gate sits in the ws options (`admin_only`), the
  handler, or the page include. Integration is the lowest layer that sees the whole gate. A unit
  test of a part of the gate would not fail when `admin_only` changes.
  [`testing.md`](../../../.claude/rules/testing.md): page source is not the DOM, so JS-built cells
  need a browser.
- **Trade-offs:** An integration suite with about 34 × 5 rows. To keep it at seconds, each role
  logs in once per run (one `WsClient` per role, kept for the whole run). No unit rows: the gates
  have no pure decision function to push down to, and adding one only for this would be invented
  structure.

### Guest: one row per cell, on the function's own request

- **Decision:** Every function gets a guest row like every other role. The row requests the
  function's own page, `admin.php` tab or ws method. There is no sweep over all ws methods, and no
  page lockout counts in place of the function.
- **Reason:** The owner chose this (4b). The guard below already makes sure that every method has a
  row, so a per-cell guest row reaches every method of the fork plugins.
- **Trade-offs:** Core methods that the fork does not wrap are not swept for guests. Core's
  `ws_isInvokeAllowed()` refuses them all the same way
  ([0055](../../../docs/agents/decisions/0055-guests-are-locked-out.md)), and `GuestLockoutTest`
  keeps its sample.

### A structural guard ties every ws method to a matrix row

- **Decision:** Each `RoleMatrixTest` has a guard case. It asks `reflection.getMethodList` as the
  webmaster for the plugin's methods (by prefix: `typetags.`, `pwg.provenance.`, `pwg.persons.`,
  `pwg.photoedit.`, `pwg.photoinfo.`) and fails, naming the method, if no row declares it. A
  lower-bound constant guards against an empty list (anti-vacuity). The prefix is read from one
  constant, not typed twice. Measured 2026-10-12: typetags 7, provenance 5, persons 7, photoedit 1,
  photoinfo 4.
- **Reason:** A new ws method without role rows is the most likely way for the matrix to fall out of
  date unnoticed.
- **Trade-offs:** The guard covers only methods, not pages. Listing a plugin's pages and admin tabs
  has no reliable source, and a hand-written list would be a second copy. photoinfo's wrapped core
  methods (`pwg.images.setInfo`, `pwg.tags.rename|duplicate|merge|delete`) and the typetags methods
  it wraps are not under its prefix. Its rows (`core-screens`, `tag-keywords`) declare them, but the
  guard does not enforce them.

### Every plugin gets `_admin` and `_generic` accounts

- **Decision:**
  - Add `typetags_admin` and `provenance_admin` (status `admin`, not webmaster, level 8, as in
    photoedit and photoinfo).
  - Add a `<plugin>_generic` account (status `generic`) to all five plugins.
  - Each `create-test-users.php` asserts the status it set. The E2E `auth.setup.js` saves a session
    for every new account. A new decision supersedes the hand-check consequence of 0062: Admin's
    write-back gets a test (the no-fix itself stands).
- **Reason:** An admin gate is proven only when a non-webmaster administrator fails to pass it
  (`testing.md`, *Test accounts*). For `generic`, the brainstorm said to add an account only where a
  gate can tell `generic` apart from `normal`. The research found that every plugin gate can do
  that: whether `generic` is admitted depends only on whether the gate uses `!is_a_guest()` or
  `is_classic_user()`. So every plugin needs one.
- **Trade-offs:** Ten more accounts in the user list. An install set up before this change must
  re-run each `create-test-users.php`. `Config` names the script when a variable is missing.

### Each gate is flipped once by hand to prove the rows can fail

- **Decision:** At the end, flip each gate once. Examples:
  - `admin_only` → removed
  - `is_webmaster()` → `is_admin()`
  - `!is_a_guest()` → `is_classic_user()`
  - visibility check → removed
  - drawer check → removed
  - `admin.php:27` access → `ACCESS_CLASSIC`

  For each flip, check that exactly the expected rows go red and nothing else does. Record the
  result as a dated table (gate → expected rows → result) in
  [`docs/agents/TESTING.md`](../../../docs/agents/TESTING.md). Follow the timing rules in
  [`mutation-testing.md`](../../../.claude/rules/mutation-testing.md): wait for the Mutagen
  checksum, opcache `revalidate_freq`, and Smarty `touch` after a template revert.
- **Reason:** The rows pass on their first run because the gates already exist, so this is how they
  get proven (`test-design.md`, *proving a check can actually fail*).
- **Trade-offs:** This is not mutation testing in the rule's sense: it runs against the integration
  layer, done once, and it is a manual step. No script patches the gates (`mutation-testing.md`,
  *keep it as prose*).

## Constraints the tasks must respect

- **Fixtures** come from each plugin's `FixtureBuilder`: throwaway albums and copied photos, never a
  real scan. An accepted `edit` call writes into the file, so its fixture photo is a copy. Where a
  method has a dry run that passes the gate first (photoedit), the row may use it. This must be
  checked per method, not assumed.
- **Shared state:** the suites take `/tmp/pwg-suite.lock` around integration and E2E runs, because
  parallel agents share the install. Cases that switch a plugin off or write a `guest_access` row
  keep their existing tear-down and refuse-to-start rules
  ([`plugin-test-suites.md`](../../../.claude/rules/plugin-test-suites.md)).
- **Class names** are unique across both suites of a plugin. `RoleMatrixTest` lives only in
  `Integration`.
- **Documentation in the same change:**
  - `plugin-test-suites.md`: the new accounts, the matrix suites and the guard
  - `testing.md`: replace "both plugins follow this" with the actual account list
  - the gate-flip table in `TESTING.md`

## Tasks (shape)

| # | Task | Depends on |
|---|---|---|
| 1 | Accounts: `_admin` for typetags and provenance, `_generic` for all five; auth.setup sessions; decision superseding 0062's hand check | - |
| 2 | provenance `RoleMatrixTest`: provenance rows + core rows + guard | 1 |
| 3 | typetags `RoleMatrixTest` + guard (submodule) | 1 |
| 4 | persons `RoleMatrixTest` + guard | 1 |
| 5 | photoinfo `RoleMatrixTest` + guard, incl. `tag-keywords` N | 1 |
| 6 | photoedit `RoleMatrixTest` + guard | 1 |
| 7 | E2E `roles.spec.js` for the JS-built cells | 1 |
| 8 | Feature-file depth: missing `[BVA]`/`[DT]` combinations for the new roles; delete superseded single-role cases | 2-6 |
| 9 | Gate flips by hand, table in `TESTING.md`, docs updated | 2-8 |

Tasks 2-6 each delete the single-role cases that their matrix rows supersede, in the same commit.

## Related documents

- [Previous change: role permissions](../2026-10-11-role-permissions/design.md) and its tasks
- [Research: role permissions](../../../docs/agents/research/2026-10-11-role-permissions.md): the matrix
- Decisions [0055](../../../docs/agents/decisions/0055-guests-are-locked-out.md) to
  [0065](../../../docs/agents/decisions/0065-normal-users-delete-only-the-regions-they-drew.md)
- Rules: [testing.md](../../../.claude/rules/testing.md), [test-design.md](../../../.claude/rules/test-design.md),
  [mutation-testing.md](../../../.claude/rules/mutation-testing.md), [e2e-tests.md](../../../.claude/rules/e2e-tests.md),
  [plugin-test-suites.md](../../../.claude/rules/plugin-test-suites.md)
