---
id: 07
dependencies:
- 06
---

# Task 07: Failure mail and daily schedule

A daily `launchd` job runs `pwg-backup`, and a failed run sends a mail as well as writing the log.

## References

* `design.md#schedule-and-failure-reporting`
* `tools/deploy/pwgdeploy/config.py` (`MailConfig`)
* `tools/deploy/pwgdeploy/backup_cli.py` (task 06)

## Work

* [x] `tools/deploy/pwgdeploy/notify.py`: `failure_mail(mail, error, log_tail) -> EmailMessage` (pure; subject names the gallery and the failed step, body holds the error and the last log lines, no password or token) and `send(mail, message)` over `smtplib` (`SMTP_SSL` for `ssl`, `starttls()` for `tls`, timeout set)
* [x] Recipient: a `backup.notify` address in the config section (task 05's section, one more key), defaulting to `admin.email`
* [x] `pwg-backup` sends the mail on any failure exit; a failed send is logged and does not change the exit code
* [x] A config without a `mail` section logs that no mail can be sent
* [x] `tools/deploy/launchd/de.chriss-baumann.pwg-backup.plist.example` (daily `StartCalendarInterval`, `--kind daily`, stdout/stderr into the backup folder) and its how-to in the README
* [x] Unit tests for `failure_mail()` and for `send()` against a fake SMTP class

## Verification
* [ ] (manual testing required) A forced failure (wrong FTP password) writes a log line and delivers a mail to the configured address (hand check, recorded in `docs/agents/TESTING.md`)
  * **Note:** The log line and the mail content are covered by `test_backup_cli.py` and `test_notify.py` with a fake mail sender; real delivery is the ledger row of 2026-10-20.
* [x] The mail body never contains the FTP, DB or admin password
  * **Note:** Verified via `test_notify.py::test_no_configured_password_survives_into_the_mail` (ftp, mysql, admin, mail passwords scrubbed from error and log tail); verify added `redact()` covering URL-encoded, JSON and repr forms, longest-first, and whole-word-only for secrets under 4 chars (4 new tests).
* [ ] (manual testing required) `launchctl load` of the example plist runs the backup at the set time (hand check, recorded)
  * **Note:** `plutil -lint` passes; load/run is a ledger row in `docs/agents/TESTING.md`.
* [x] `cd tools/deploy && uv run pytest` passes
  * **Note:** 831 collected, all pass except the known `test_seed.py` emoji test (submodule absent in worktrees).

## Deviations

* `failure_mail()` takes `(mail, recipient, site_url, kind, step, error, log_tail, secrets)` instead of `(mail, error, log_tail)`: the subject needs gallery and step, and the body scrubs the configured passwords (`secrets_of(config)`).
* Ctrl-C (exit 130) sends no mail; every `DeployError` exit and a failing `--check` do.
* A mail problem is logged as a backup.log line with result `mail-failed` or `no-mail-section` (and printed to stderr).
* `backup.notify` is validated for address shape and added to `deploy.example.json`.
* The plist runs `uv run --directory <tools/deploy> pwg-backup --kind daily <credential file>` daily at 03:30; `launchd.out.log` / `launchd.err.log` go into the backup folder, which must exist.
* Documented test count 831 (README, deployment.md); tasks 08/09 will need to re-measure after merging.

## Verification review

Approved after fixes. Agent token never appears in error text (it travels in POST fields, not the URL). A DB password echoed by the remote agent's own error message cannot be known locally and is not scrubbed. `launchctl load`/`start` in the README replaced by `bootstrap gui/$(id -u)` / `kickstart`.
