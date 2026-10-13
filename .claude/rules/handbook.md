# The German End-User Handbook

Read before changing anything under `handbuch/`, before re-taking a screenshot, and before
changing a German string a screenshot shows.

`handbuch/` is the fork's German end-user documentation: an index and seven plain HTML
pages plus a stylesheet, covering album creation, adding photos, photo texts, tags, person
tagging, turning or cropping a photo, and zooming on the picture page (measured 2026-10-10). It is
**not** part of upstream's `language/de_DE/help/` and must not be moved there — an upstream merge
rewrites those files. See
[decision 0024](../../docs/agents/decisions/0024-german-handbook-location-and-demo-content.md).

## Only logged-in users read it

Nothing under `handbuch/` is served directly: nginx (`.ddev/nginx/handbuch.conf`) and Apache
(`handbuch/.htaccess`, deployed) rewrite `/handbuch/<file>` to the root `handbuch.php`, which sends
a guest to the login page and serves only `html`, `css` and `png` outside `tools/`
([decision 0063](../../docs/agents/decisions/0063-the-handbook-is-served-through-a-login-checking-entry.md)).
A new file type in the handbook (an `svg`, a `js`) is refused until it is added to
`HANDBUCH_CONTENT_TYPES` in `include/functions_handbuch.inc.php`. The pages and their relative links
need no change for this; `check.php` reads files on disk and `shoot.js` photographs the gallery, so
neither goes through the entry. Provenance's `HandbookAccessTest` requests every page, the stylesheet,
every screenshot and every relative reference through both servers.

## The three commands

All three are committed and reproducible. Run them in this order when a documented screen changes.

```bash
# 1. Build the demo album the screenshots show (and only that album)
ddev exec php handbuch/tools/seed.php --scenario=demo

# 2. Re-take every screenshot
ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; \
  node handbuch/tools/shoot.js'

# 3. Remove the demo album again
ddev exec php handbuch/tools/seed.php --restore

# Mechanical checks over the pages themselves — run this before committing
ddev exec php handbuch/tools/check.php
```

**Always finish with `--restore`.** A second `--scenario=demo` refuses while one is seeded, and a
demo album left in the install shows up in the next screenshot of the album tree. The cycle was run
end to end 2026-08-31 and returns the install to its 5 albums / 105 photos.

**A re-shoot is reproducible in content, not byte-for-byte.** The demo album takes a fresh id on
every seed, so any shot carrying an id, a URL or a sort position differs from the committed one -
7 of the 20 did on the 2026-08-31 run. That is expected. Commit a re-shoot only when the screen it
shows actually changed; otherwise revert the churn.

The seed's own tag group (`DEMO_TAG_GROUP` in `seed.php`, for shot 28) is a coloured group. The
picture page offers every coloured tag as a `+` badge (shots 11 and 15) and the admin tag list shows
every tag (shot 12), so its two tags also appear in those three shots of a run. Those three were committed without them (2026-10-13). A re-shoot that changes one
of them for another reason carries the two demo badges; that is demo content and acceptable.

`seed.php` inserts and deletes rows, writes image files and rewrites their metadata. Like the
plugin suites, it refuses to run without the install's `persons_throwaway_install` marker and is
never safe against a production install. `shoot.js` signs in with the persons suite's test
accounts (`local/config/persons-test.env`), never a human's login.

## Never photograph the real gallery

All 105 images in this install are recovered family scans of identifiable private people. Every
screenshot is an **element** screenshot of the generated demo album, never a full page that could
catch a real thumbnail in a sidebar. Blurring or cropping a real scan is not an alternative: it is
manual, repeated on every re-shoot, and unrecoverable if missed once.

## The German strings the handbook shows

Nineteen strings core and the Colored Tags plugin leave in English or French, and five core German
strings replaced on purpose ([decision 0052](../../docs/agents/decisions/0052-named-core-german-strings-are-replaced-on-purpose.md)),
24 keys in all (measured 2026-10-11), are translated in the tracked `local/language/de_DE.lang.php` — a **flat** file, not a directory
(`include/common.inc.php:239` loads it with `'local'=>true`, which resolves to
`<dir><language>.<filename>`). It merges over core and wins.

It needs its own `!` re-include in `.gitignore` under the blanket `/local/*` rule, which is already
there. `$conf['compiled_template_cache_language']` is `false`, so a translation change needs no
`_data/templates_c/` clear — but a change to a prefilter's **code** does (a template it includes does
not, [decision 0036](../../docs/agents/decisions/0036-prefilters-include-plugin-templates.md)).

`plugins/provenance/tests/Unit/GermanOverrideKeyTest.php` guards every key structurally: a renamed
or dropped upstream literal reverts a screen to English with no error anywhere, because `l10n()`
returns the key it was given. Adding a translated string means adding its row there too — the
emitting file, the literal exactly as written, and how many times it occurs.

One string carried no `|translate` filter at all and was fixed in the typetags submodule
(`plugins/typetags/template/tags.tpl`). That is a commit inside the submodule; it must be pushed to
`github.com/christianbaumann/Piwigo-Colored-Tags` or a fresh clone fails. `docs/backlog.md` tracks
what is still unpushed.

## What has no automated oracle

Whether the instructions actually work, whether the German reads naturally, and whether a
screenshot still shows the screen its text describes are hand checks, recorded in the ledger of
`docs/agents/TESTING.md`. `check.php` covers what a machine can decide — reference resolution in
both directions, well-formedness as XML, quoted `admin.php?page=` routes, and forbidden characters.
Do not build a checker that reads the prose looking for words; `.claude/rules/test-design.md` names
that as the apparatus-proving-an-apparatus case.
