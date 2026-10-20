---
id: 01
dependencies: []
---

# Task 01: Successor decisions and host probe

The repository records that the remote is the real gallery and how its data leaves the host, and
the host's answers that the agent depends on are known and dated.

## References

* `design.md#why-now`
* `design.md#before-building-one-host-probe-by-hand`
* `docs/agents/decisions/0021-remote-instance-is-a-sandbox.md`
* `docs/agents/decisions/0023-no-database-transfer-to-the-remote.md`
* `docs/agents/decisions/0026-tracked-gallery-photos-are-prune-eligible.md`
* `.claude/rules/deployment.md#the-manual-host-probes`
* `.claude/rules/backpressure.md#decision-log`

## Work

* [x] Write a new decision superseding 0021: the remote holds state the repository cannot rebuild, so it is backed up; list what still stays destructive (prune, generated config) and why the pre-deploy backup makes that acceptable
* [x] Write a new decision superseding 0023 for the export direction: DB export and restore through a temporary, token-gated agent script; name the accepted risk and its mitigations
* [x] Revisit 0026 against its *What would reverse this*: record in a new decision whether the gallery-deletion report line stays (a snapshot now holds the pruned scan)
* [x] Add a *Superseded by* line to 0021 and 0023 (the only edit to an old decision; nothing that was decided gets erased)
* [ ] (pending user approval: live host probe) Run the probe once by hand, like the pre-install probe (random name, own `FtplibTransport`, delete in a `finally`, re-check the URL answers 404): `mysqldump` on `PATH` and runnable under `exec()`, a PHP-writable directory outside the web root reachable over FTP, `max_execution_time`, `memory_limit`, DB size, size of `upload/` + `galleries/` + `_data/photoedit/originals/`
* [ ] (pending user approval: live host probe) Record the dated answers in `docs/agents/TESTING.md`'s ledger and in `design.md` if one changes a decision (e.g. no directory outside the web root)

## Verification

* [x] The new decision files exist, are numbered after 0087, and cite the decisions they supersede
  **Note:** Verified via ls: 0088, 0089, 0090 exist; each cites its superseded decision in a Supersedes/Revisits line. Re-verified in worktree backup-01 (4c704c9f8): no number collision with master (last is 0087); all relative links resolve (the design.md link resolves only once the change folder is committed to the branch, it is untracked in the main checkout); content matches design.md; no AI vocabulary, no emojis; format matches 0085-0087.
* [x] 0021 and 0023 point to their successors
  **Note:** Verified via head of both files: a Superseded by line follows Status; `git diff 1521e4e3b` shows exactly one added line in each.
* [ ] (pending user approval: live host probe) The probe left no file on the host: its URL answers 404 and an FTP listing of the root shows no probe file
* [ ] (pending user approval: live host probe) `TESTING.md` holds every probe answer with the date
