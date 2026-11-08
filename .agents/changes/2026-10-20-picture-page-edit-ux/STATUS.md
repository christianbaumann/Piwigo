# Status: picture-page edit UX

Paused 2026-11-08 (session s-e4c1). The task files' frontmatter holds each task's status.

## State

- Done and on `master`: 01, 02, 03, 05, 09, 10, 11.
- Blocked, branches and worktrees kept: 04, 06, 12. Each task file has a *Blocked* section saying what to rerun.
- Not started: 07 (needs 06), 08 (needs 04, 07), 13, 14.

## Why it paused

The host disk filled up (1.3 GiB free of 926 GiB) and Docker Desktop stopped, so no suite can run.

## To resume

1. Free disk space, restart Docker Desktop, `ddev start`.
2. Work through 04, 06, 12 per their *Blocked* sections, then 07, 08, 13, 14.
3. `plugins/typetags` on `master` is two commits ahead of its origin (c48112b, 6419cf9), unpushed. A fresh clone fails until they are pushed. Task 13 also touches this submodule.

## Testing a worktree

DDEV serves only the main checkout. `.agents/worktrees/.tools-s-e4c1/` (git-ignored) holds `wt-setup` and `wt-test`, which serve a worktree with PHP's built-in server in the web container under the suite lock; `BRIEF.md` there explains them. E2E traces make a worktree large: task 12's reached 1.8 GB.

## Notes

- `.agents/worktrees/.tools-s-e4c1/` also holds `VERIFY.md`, the verifier brief.
- After merging a task that changes a prefilter's code (11, 06), the main checkout needs `_data/templates_c` cleared once, and the remote the manual purge.
