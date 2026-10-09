# 0048 — a typetags group's emoji is stored as code points, and the tables keep utf8mb3

Date: 2026-10-09
Status: accepted

## Context

Two new groups carry an emoji in front of the tag name: `Ausstellung` the framed picture
(U+1F5BC U+FE0F) and `Freitext` the writing hand (U+270D U+FE0F). Piwigo connects with
`DB_CHARSET` `utf8` (written by `install.php`, here in `local/config/database.inc.php`), and
`piwigo_typetags`, `piwigo_tags` and `piwigo_images` are `utf8mb3_general_ci` (measured
2026-10-09). utf8mb3 holds at most three bytes per character, so U+1F5BC cannot be stored as
text. U+270D could, but one group would then be stored differently from the other.

## Decision

- The `emoji` column (`VARCHAR(64) NOT NULL DEFAULT ''`, added by `install()` in
  `plugins/typetags/maintain.class.php`) holds upper-case hex code points one space apart, e.g.
  `1F5BC FE0F`; `''` for none. Every group is stored this way, whatever the character's width.
- `typetags_emoji_codepoints()` (`include/functions.inc.php`) accepts a pasted emoji, typed hex,
  or `U+`-prefixed hex, and refuses more than `TYPETAGS_EMOJI_MAX_CODEPOINTS` (8) code points,
  surrogates, anything above U+10FFFF and ASCII other than digits, `#` and `*`. The admin form,
  `typetags.type.add` and `typetags.type.update` all go through it. The deploy's seed
  (`tools/deploy/pwgdeploy/seed.py`) refuses any other form in `tag-groups.json`.
- Rendering turns the code points into character references (`typetags_emoji_html()`) or a CSS
  `content:` escape (`typetags_emoji_css()`), so the character never passes through the database
  connection.
- No charset migration. The connection is `utf8` as well, so converting a column alone would not
  let a four-byte character through, and converting the connection and core's tables is a change
  to core's schema this fork does not make.

## Consequences

- The stored value is unreadable in a database shell; the plugin page shows the rendered emoji.
- Tag names still cannot hold a character above U+FFFF. `typetags.image.addNewTag` refuses such a
  name (`typetags_typed_tag_name_is_valid()`) instead of failing in the database.
- If core or upstream ever moves to utf8mb4, the column can stay as it is; the code points render
  the same.
- `EmojiCodepointsTest` and `EmojiRenderTest` in `plugins/typetags/tests/Unit/` pin the accepted
  forms and both renderings.
