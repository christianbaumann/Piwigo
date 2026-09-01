# 0024 — The German handbook lives in `docs/handbuch/` and is photographed from generated content

Date: 2026-08-31
Status: accepted
Supersedes nothing. Records the three decisions taken while planning
[`docs/agents/research/2026-08-31-german-end-user-documentation.md`](../research/2026-08-31-german-end-user-documentation.md).

## Context

The fork needed German end-user documentation for the five workflows it is actually used for:
creating an album with its description, adding photos, adding text to photos, tagging with
tags, tagging persons. Three questions had to be settled before a single page could be written,
and each had a plausible alternative that was rejected for a stated reason.

## Decision 1 — the handbook lives in `docs/handbuch/`, not in `language/de_DE/help/`

Plain HTML pages under `docs/handbuch/`, with their own stylesheet, screenshots and tooling.

**Rejected: `language/de_DE/help/`.** Piwigo already ships a German help system there, and the
pages follow a house shape this handbook borrows. But those files are upstream's. An upstream
merge rewrites them, so a handbook written into that directory is a merge conflict on every
Piwigo release and, worse, is silently reverted by a merge resolved in upstream's favour. The
handbook also covers two fork-local plugins that upstream's help has no place for.

The cost of the decision is that the handbook is not reachable from the gallery's own help menu.
That is accepted: it is a document handed to a user, not an in-app help page.

## Decision 2 — screenshots come from generated demo content, never from the real gallery

`docs/handbuch/tools/seed.php` draws six 1200x800 scenes with ImageMagick, builds an album from
them, and prints the face-box coordinates that `docs/handbuch/tools/shoot.js` drags a person
region over. `--restore` removes everything it created.

**Rejected: photographing the real gallery, with faces blurred or cropped.** All 105 images in
this install are recovered family scans of identifiable private people. Blurring is manual work
repeated on every re-shoot, and it is unrecoverable if missed once — a published screenshot
cannot be un-published. Generated content removes the failure mode instead of mitigating it.

**Rejected: a stock photo set.** It would carry a licence to track and would still have to be
committed. Generated scenes have no licence and are reproducible from one committed command.

The cost is that the demo scenes are obviously drawings rather than photographs. Whether they
read as a plausible gallery is a subjective judgment recorded in the hand-check ledger; the
falsifiable half — that each declared shot exists, and that a face box lands on a drawn face —
is asserted by the tooling.

## Decision 3 — the German is fixed before anything is photographed, not documented as it was

Fourteen strings the handbook screenshots rendered in English or French. They were translated
into the tracked `local/language/de_DE.lang.php` (and, for the one literal that carried no
`|translate` filter, by wrapping it in the typetags submodule) **before** Phase 5 took a single
screenshot.

**Rejected: documenting the half-English UI as it stood.** A German handbook whose screenshots
say `Album updated` teaches the reader to look for a string the handbook does not use, and every
such screenshot would have had to be retaken the day someone translated it anyway.

**Rejected: translating the whole German locale.** Out of proportion to the goal, and it would
mix an unreviewable bulk translation into a documentation change.

The keys are guarded structurally by `plugins/provenance/tests/Unit/GermanOverrideKeyTest.php`,
because a renamed upstream literal reverts a screen to English with no error anywhere:
`l10n()` returns the key it was given.

## Consequences

- The handbook's content and its screenshots are both reproducible by committed commands, so a
  screen that changes is re-photographed rather than described from memory.
- `local/language/de_DE.lang.php` is tracked, which needed its own `!` re-include in
  `.gitignore` under the blanket `/local/*` rule.
- The typetags submodule carries a fork-local template change, so it is now two commits ahead of
  its own origin. Both must be pushed or a fresh clone fails — recorded in `docs/backlog.md`.

## What would reverse this

- Upstream shipping a German help system that covers the fork's plugins would make decision 1
  worth revisiting — it will not, because the plugins are fork-local.
- A gallery whose photos are publishable (a demo install, or content the owner released) would
  make decision 2's generated scenes unnecessary. The seed would still be the reproducible path.
- Upstream translating these strings itself would make the override's entry for that key
  redundant, and `testTheOverrideDoesNotShadowACoreGermanString` fails the moment it happens —
  by design, so the fork drops its copy rather than silently shadowing upstream's wording.
