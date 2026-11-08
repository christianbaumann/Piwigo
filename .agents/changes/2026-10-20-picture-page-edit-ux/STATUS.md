# Status: picture-page edit UX

Paused 2026-11-03. Repo `master` at `a5446959b` when paused.

## Done

- Brainstorm finished, Konzept B chosen. The user answered every open question; see `design.md`, including *Implementation details (agreed while splitting tasks)*.
- `design.md`: the problem, sources, data flow and all key decisions.
- `prototype/picture-edit-concepts.html`: clickable mockups of A, B and C. Konzept B includes the role switch and the collapsed change list.
  - Published privately at https://claude.ai/artifact/GAwQasqYbQw96PrUJLesfa.
  - The prototype differs from the design in two ways: it saves each date field separately, and it puts the × on the boxes in the photo. **`design.md` wins on both.**
- `tasks/01`–`14` were written, and the user confirmed the split.

## Not done

- **Nothing is committed.** The whole change folder is untracked. Commit it first, on `master` (the user's working rule).
- No task has started. No production or test code has changed for this change.

## Next steps

1. Commit `.agents/changes/2026-10-20-picture-page-edit-ux/`.
2. Start wave 1, which can run in parallel: tasks 01, 09, 10, 11, 12.
3. Wave 2: 02, 03, 04, 05, 06. Then 07, then 08, then 13, then 14. The `dependencies` frontmatter in each task file is the source of truth.

## Notes for the next agent

- Decision numbers 0088–0090 were taken by other work after the tasks were written. Tasks 01, 07 and 14 take the next free number when they commit: check `ls docs/agents/decisions | tail`.
- Tasks 11 and 13 change the `plugins/typetags` submodule. Commit inside it, push to `christianbaumann/Piwigo-Colored-Tags`, then bump the pointer.
- When several agents share the install, take the suite lock around integration and E2E runs: `flock /tmp/pwg-suite.lock`.
- Since decision 0088 the remote is no longer a sandbox. That does not affect this change, but never point plugin suites or the handbook seed at the remote.
