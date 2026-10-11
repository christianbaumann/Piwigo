# 0052 — named core German strings are replaced on purpose

Date: 2026-10-11
Status: accepted

## Context

`local/language/de_DE.lang.php` existed only for strings core leaves untranslated, and
`GermanOverrideKeyTest::testTheOverrideDoesNotShadowACoreGermanString` enforced that: a key core's
de_DE files also define would silently change a screen that was already German.

The handbook's third pass (German reading, 2026-10-11) found core translations that read badly to
the handbook's readers: *Lösche diese* / *Behalte diese* (du-imperatives, the question about tags
no photo uses), *Tag-Name* (English beside *Schlagwort* everywhere else), *(kopieren)* (a verb
where a noun belongs; also used by the user-group screen) and *Unteralben miteinbeziehen*. The
owner chose to replace them locally (2026-10-11).

## Decision

- The override may replace a core German string when it is named in
  `GermanOverrideKeyTest::REPLACED_CORE`, together with the wording core ships. Today: `Delete them`
  → *Löschen*, `Keep them` → *Behalten*, `' (copy)'` → *' (Kopie)'*, `Tag name` →
  *Name des Schlagworts*, `include child albums` → *Unteralben einbeziehen*.
- `testAReplacedCoreStringIsStillTheOneReplaced` goes red when core changes one of those wordings,
  so a replacement is reconsidered instead of kept over a wording nobody has read.
- Every other key keeps the old rule: the override never shadows a core translation.
- Not replaced: *Dateien hier hin ziehen.*, which comes from the vendored uploader's
  `themes/default/js/plugins/plupload/i18n/de.js`, not from a PHP language file.

## Consequences

- An upstream merge that improves one of these translations shows up as a red unit test.
- The strings are not offered upstream by this change; that would go through Piwigo's
  translation platform.
