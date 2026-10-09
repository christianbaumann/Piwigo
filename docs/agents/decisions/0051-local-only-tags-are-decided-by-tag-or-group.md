# 0051 — a tag is local-only by its own name or by its group's

Date: 2026-10-09
Status: accepted
Supersedes, in [0046](0046-a-photos-tags-travel-in-its-file.md), the sentence "The rule looks at
the tag's own name, not its group" and the consequence that a tag added later to the
`Ausstellung` group under another name is written.

## Context

Decision 0046 kept `Ausstellung` and every name containing `?` out of the file, the rescan and the
prune, judged by the tag's name alone. The seeded groups `Ausstellung`, `Kategorie ?` and `Name ?`
each hold one tag of the same name, so the rule covered them. A tag created later in one of those
groups under another name (an exhibition, say `Vernissage 1987`) was written into the file and so
reached the remote. The owner decided against that (plan 2026-10-09, follow-up Q20).

## Decision

- A tag is local-only when its own name **or** its typetags group's name is in
  `PHOTOINFO_LOCAL_ONLY_TAGS` (`Ausstellung`) or contains `?`
  (`photoinfo_tag_is_local_only($name, $group)` in `plugins/photoinfo/include/functions.inc.php`).
  Both names are compared after control characters become spaces and surrounding whitespace is
  trimmed, and regardless of letter case, as the database's `utf8mb3_general_ci` compares names
  (owner decision Q25): `ausstellung` and `AUSSTELLUNG` are local-only like `Ausstellung`. Its
  accent folding is not reproduced. A group named `Ausstellungen` is still not local-only.
- All three callers pass the group: `photoinfo_file_keywords()` (the write),
  `photoinfo_rescan_tags()` for the file's entries (the group the hierarchy entry names) and for the
  photo's database tags (the group `photoinfo_image_tags()` joins in). Prune reuses the rescan's
  `not_in_file`, so it follows without a change of its own.
- The name rule stays as it was: `Ausstellung` or a name with `?` is local-only in any group or
  none.

## Consequences

- Moving a tag into `Ausstellung`, `Kategorie ?` or `Name ?` takes it out of the files of its
  photos on their next tag write; `typetags_tags_regrouped` triggers that write (decision 0046).
  Moving it out puts it back in the same way.
- A file written before this decision may still name such a tag flat, in `XMP-dc:Subject` without
  a hierarchy entry. The rescan sees no group for that entry and links the tag if the photo lacks
  it. It is never pruned, since its database row carries the group. The next tag write of that
  photo removes it from the file.
- `LocalOnlyRuleTest` no longer checks that every seeded tag of a local-only group is local-only by
  name; with the group rule that holds by construction. It now checks that every name in
  `PHOTOINFO_LOCAL_ONLY_TAGS` is a group `tools/deploy/tag-groups.json` seeds, so a renamed group
  cannot silently switch the group rule off.
- The same widening applies to decision 0050's `use_iptc` consequence: core's import would also
  drop every tag of these groups from the photo, whatever its name.
- Mutants L1-L15, K11, R13 and R14 in `docs/agents/TESTING.md`.
