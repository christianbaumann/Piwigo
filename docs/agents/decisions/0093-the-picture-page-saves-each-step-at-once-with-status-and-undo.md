# 0093 — The picture page saves each step at once, with visible status and undo

Date: 2026-11-11
Status: accepted
Change: `.agents/changes/2026-10-20-picture-page-edit-ux` (design.md, *Save model: Konzept B*)

## Context

The picture page had three ways to change a photo. Tags and persons saved at once; Datum and Info
opened an editor with Speichern and Abbrechen and reloaded the page; persons had a start/stop mode
that reloaded the page on leaving. Users could not tell what was saved, and nothing could be taken
back.

## Decision

Each step is complete on its own and saves when it is made: a tag click, a date when focus leaves
the date group or on Fertig, the info text when its field is left or on Fertig, a named box, a
deleted name. Every row shows its own status (Speichert ..., ✓ Gespeichert · Rückgängig,
↶ Rückgängig gemacht, a red Nicht gespeichert: ... that stays until a save works) and the page
never reloads. Uniform wording: ✎ ändern, Fertig, Rückgängig. Marking a person is one action per
press of + Person markieren; Esc cancels; the × sits on the name in the Personen row. A collapsed
list, Letzte Änderungen an diesem Foto, shows who changed what and offers Rückgängig (rights:
[decision 0092](0092-undo-rights-own-changes-for-everyone-all-for-administrators.md)).

There is no timed autosave: the info text saves on leaving the field, and a leave warning is active
only while it is unsaved.

## Rejected

- **A, one page-wide edit mode with one Speichern.** A large change, work lost when the page is
  left, and a failed save spans several parts.
- **C, Bearbeiten and Speichern per row.** Two more clicks for every tag.

## Consequences

- More file writes than A or C (each step writes the image file), and a misclick on a tag is a real
  write; Rückgängig is the answer to both.
- Display formatting (formatted date, info HTML) is part of the ws answers, so rows update in place.
- The handbook's pages on Datum, Info, Schlagworte and Personen describe this flow; the
  screenshots 11, 15, 17, 23, 24 and 25 were re-taken for it.
