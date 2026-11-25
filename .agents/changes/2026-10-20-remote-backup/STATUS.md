# Status: remote backup

Implemented and merged on `master` 2026-10-20 (tasks 01-10). The code is complete; what is left
are checks against the real remote, one open question about its photos, and operating notes.

## Open

- **Hand checks.** The rows dated 2026-10-20 in the ledger of `docs/agents/TESTING.md` say exactly
  what each check asks; this list only says where each stands (2026-11-25):
  - predeploy snapshot (task 08): closed 2026-11-23.
  - first `pwg-backup` run (task 06): partly answered by that predeploy run. Open: a second run
    fetching only changed files, with unchanged photos sharing their inode across both snapshots,
    and an FTP `ls` of the web root showing no `*.php` agent and no `dump-*` directory.
  - failure mail and launchd schedule (task 07): not run.
  - restore against the remote (task 09): not run.
- **105 tracked scans missing on the remote, not yet decided.** `uv run pwg-deploy --audit
  deploy.local.json` (2026-11-23) lists every tracked file under `galleries/` except `index.php`
  as missing on the server, while the deploy manifest records them as uploaded, so no deploy sends
  them again. Not caused by the 2026-11-23 deploy (0 removed): the host probe of 2026-10-21 already
  found 4 files in `galleries/`, the remote database has no rows for them (snapshot drift
  `db_without_file` is 0), and three exiftool `*_original` sidecars remain there, which points to
  the photos having been deleted through Piwigo on the remote. Ask the user whether that was
  intended. If not: drop those entries from `tools/deploy/.state/<host>_root.json` and deploy
  again; the sync re-creates the photos and the persons and photoinfo rescans restore their
  regions, dates and info texts from the files.

## Operating notes

- `tools/deploy/deploy.local.json` has a `backup` section since 2026-11-23:
  `{"dir": "~/Backups/piwigo-remote"}`, no `dump_dir` (all-inkl's FTP login cannot reach `../`).
  Without it every deploy stops with a `ConfigError` before connecting.
- The first snapshot, `~/Backups/piwigo-remote/2026-11-23T183422-predeploy`, holds 5592 files,
  19.7 GB, and took 54 minutes; the whole deploy took 75 (2026-11-23). How much faster an
  incremental run is has not been measured yet (the second-run check above).
- One FTP read timeout ended a snapshot after 3.1 GB with exit 14. Re-running the same command
  resumed the `.partial` folder. If timeouts repeat, look at `CONNECT_TIMEOUT_SECONDS` in
  `pwgdeploy/transport.py`, which is also the socket's read timeout.
- Bug fixed 2026-11-23 (`15bc3f8b7`): the agent read `$conf['db_pw']`, core writes `db_password`.
  The DDEV integration test had the same wrong key in its fixture, so it never noticed.
