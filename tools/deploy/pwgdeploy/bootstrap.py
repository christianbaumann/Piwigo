"""Turning an uploaded tree into an installed gallery.

Five steps — install, config, session, plugins, sync — each of which asks the server what
state it is in before changing it, so running the whole thing twice is safe by
construction rather than by the operator remembering.

Every step is port-typed: the HTTP side arrives as an `HttpClient`, the one file this
module uploads goes through the same `Transport` and the same manifest as the rest of the
deploy. Nothing here opens a socket itself, which is why all of it is unit-tested.
"""

from __future__ import annotations

import html
import json
import re
import tempfile
import urllib.parse
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable, Mapping

from pwgdeploy import fileset, manifest
from pwgdeploy.config import DeployConfig, SiteConfig
from pwgdeploy.errors import InstallError, RemoteHttpError
from pwgdeploy.http import SYNC_TIMEOUT_SECONDS
from pwgdeploy.urls import remote_path, site_url

# install.php:162 — the only marker there is. install.php:156-165 decides it from
# local/config/database.inc.php alone, so asking the page is asking the same question the
# server asks itself.
INSTALLED_MARKER = "Piwigo is already installed"

INSTALL_PATH = "install.php"
WS_PATH = "ws.php?format=json"
SYNC_PATH = "admin.php?page=site_update&site=1"

# Named in fileset because the prune needs the same string; a second literal here would
# rot the day only one of the two moved.
CONFIG_RELATIVE_PATH = fileset.GENERATED_CONFIG_PATH

PLUGINS_TO_ACTIVATE = ("typetags", "provenance", "persons")
ACTIVE_STATE = "active"
ACTIVATE_ACTION = "activate"

# tools/remote_sync.pl:41-56. sync_meta has to be present: the page reads it with isset,
# so setting it to 0 is not the same as leaving it out.
SYNC_FIELDS = {
    "sync": "files",
    "display_info": "1",
    "add_to_caddie": "1",
    "privacy_level": "0",
    "sync_meta": "1",
    "simulate": "0",
    "subcats-included": "1",
    "submit": "1",
}

# admin/themes/default/template/site_update.tpl:19-24. Matched by class rather than by
# the label beside it: the install this deploys runs in German.
_SUMMARY_ITEM = re.compile(
    r'<li class="(update_summary_new|update_summary_del|update_summary_err)">\s*(\d+)'
)
_ERROR_BLOCK = re.compile(r'<div class=["\']errors["\']>(.*?)</div>', re.DOTALL)
_LIST_ITEM = re.compile(r"<li[^>]*>(.*?)</li>", re.DOTALL)
_TAG = re.compile(r"<[^>]+>")

MIN_SUMMARY_ADDED_LINES = 2
# site_update.tpl:21-22 renders both, always — an albums line and a photos line.
MIN_SUMMARY_DELETED_LINES = 2
MAX_REPORTED_ERRORS = 10


@dataclass(frozen=True)
class SyncCounts:
    albums_added: int
    photos_added: int
    albums_deleted: int
    photos_deleted: int
    errors: int


@dataclass(frozen=True)
class BootstrapResult:
    installed: bool
    """True when *this* run installed; False when it found the gallery already installed."""

    config_uploaded: bool
    plugins: dict[str, str]
    sync: SyncCounts


# --- install --------------------------------------------------------------------------


def is_installed(client, base_url: str) -> bool:
    return INSTALLED_MARKER in client.get(site_url(base_url, INSTALL_PATH)).body


def install_url(config: DeployConfig) -> str:
    """install.tpl:212's select navigates to install.php?language=…; it is a GET
    parameter the form reads (install.php:172-175), never a posted field."""
    query = urllib.parse.urlencode({"language": config.site.language})
    return site_url(config.site.base_url, f"{INSTALL_PATH}?{query}")


def install_fields(config: DeployConfig) -> dict[str, str]:
    """Ten of the twelve named inputs of install.tpl:203-295.

    `newsletter_subscribe` and `send_credentials_by_mail` are deliberately absent:
    install.php:147-151 reads both with isset(), so any value at all — "0" included —
    subscribes a newsletter and mails the credentials. Omission is the only "no".
    """
    return {
        "dbhost": config.mysql.host,
        "dbuser": config.mysql.user,
        "dbpasswd": config.mysql.password,
        "dbname": config.mysql.database,
        "prefix": config.mysql.prefix,
        "admin_name": config.admin.username,
        "admin_pass1": config.admin.password,
        "admin_pass2": config.admin.password,
        "admin_mail": config.admin.email,
        "install": "1",
    }


def install(client, config: DeployConfig) -> None:
    """POST the install form, then confirm by asking the server again.

    install.php answers 200 whether it installed or re-rendered its form with an error
    list, so the response body is evidence and the follow-up marker is the proof.
    """
    url = install_url(config)
    response = client.post(url, install_fields(config))
    if is_installed(client, config.site.base_url):
        return

    errors = scrape_errors(response.body)
    detail = "; ".join(errors[:MAX_REPORTED_ERRORS]) if errors else "no error list rendered"
    raise InstallError(f"{url} did not install the gallery: {detail}")


def scrape_errors(body: str) -> list[str]:
    """The `<li>` texts of every `div.errors` block install.tpl can render."""
    found = []
    for block in _ERROR_BLOCK.findall(body):
        for item in _LIST_ITEM.findall(block):
            text = html.unescape(_TAG.sub("", item)).strip()
            if text:
                found.append(" ".join(text.split()))
    return found


# --- the generated config -------------------------------------------------------------


def config_php(site: SiteConfig) -> str:
    """Decision 8: generated from the credential JSON, never uploaded from the local copy.

    `assume_https` is written even though nothing in core reads it — it is what the local
    install carries, and dropping it is a separate decision from this tool.
    """
    return (
        "<?php\n"
        "// Generated by tools/deploy - do not edit on the server, "
        "it is overwritten on deploy.\n"
        f"$conf['assume_https'] = {'true' if site.assume_https else 'false'};\n"
        f"$conf['provenance_exiftool_path'] = {_php_string(site.exiftool_path)};\n"
        f"$conf['persons_exiftool_path'] = {_php_string(site.exiftool_path)};\n"
    )


def _php_string(value: str) -> str:
    """A single-quoted PHP literal: only \\ and ' mean anything inside one."""
    escaped = value.replace("\\", "\\\\").replace("'", "\\'")
    return f"'{escaped}'"


def upload_config(config: DeployConfig, state_dir: Path, transport) -> bool:
    """Publish the generated config, through the target's own manifest.

    Routing it through the manifest is what makes it behave like every other file: a
    changed exiftool_path in the JSON re-uploads it, an unchanged one does not, and a
    later `--dry-run` sees it as unchanged rather than as a stranger to be pruned.
    """
    remote = remote_path(config.ftp.remote_root, CONFIG_RELATIVE_PATH)
    state_path = manifest.manifest_path(state_dir, config.ftp.host, config.ftp.remote_root)
    entries = manifest.load(state_path)

    with tempfile.TemporaryDirectory() as scratch:
        local = Path(scratch) / "config.inc.php"
        local.write_text(config_php(config.site), encoding="utf-8")
        digest = manifest.file_hash(local)
        if entries.get(remote) == digest:
            return False

        transport.connect()
        try:
            transport.makedirs(remote.rsplit("/", 1)[0])
            transport.put(local, remote)
        finally:
            transport.close()

    entries[remote] = digest
    manifest.save(state_path, entries)
    return True


# --- session and plugins --------------------------------------------------------------


def ws_call(
    client, base_url: str, method: str, fields: Mapping[str, str] | None = None
) -> Any:
    """One ws.php call, with the JSON envelope unwrapped.

    A `stat: fail` carries the server's own message; passing it through unchanged is what
    makes a wrong password distinguishable from a refused connection.
    """
    url = site_url(base_url, WS_PATH)
    payload = {"method": method, **dict(fields or {})}
    body = client.post(url, payload).body
    try:
        document = json.loads(body)
    except ValueError as error:
        raise RemoteHttpError(
            f"{url} did not answer {method} with JSON: {body[:200]!r}"
        ) from error
    if not isinstance(document, dict) or "stat" not in document:
        raise RemoteHttpError(f"{url} answered {method} with an unexpected document")
    if document["stat"] != "ok":
        raise RemoteHttpError(
            f"{method} failed: {document.get('message', document)} "
            f"(err {document.get('err')})"
        )
    return document.get("result")


def login(client, config: DeployConfig) -> str:
    """Log in and take the pwg_token from pwg.session.getStatus (pwg.php:398-407)."""
    base_url = config.site.base_url
    ws_call(
        client,
        base_url,
        "pwg.session.login",
        {"username": config.admin.username, "password": config.admin.password},
    )
    status = ws_call(client, base_url, "pwg.session.getStatus")
    token = (status or {}).get("pwg_token") if isinstance(status, Mapping) else None
    if not token:
        raise RemoteHttpError("pwg.session.getStatus returned no pwg_token")
    return token


def plugin_states(client, base_url: str) -> dict[str, str]:
    """Plugin id -> state, as pwg.plugins.getList reports it."""
    listed = ws_call(client, base_url, "pwg.plugins.getList")
    if not isinstance(listed, list):
        raise RemoteHttpError("pwg.plugins.getList did not return a list")
    return {entry["id"]: entry["state"] for entry in listed}


def activate_plugins(
    client, base_url: str, token: str, plugins: Iterable[str] = PLUGINS_TO_ACTIVATE
) -> dict[str, str]:
    """Activate what is not active yet; report what each plugin ended up as.

    Decision 6: activation routes through activate -> install
    (admin/include/plugins.class.php:187-219), which is what creates each plugin's
    tables. Inserting a piwigo_plugins row directly would skip install() and leave a
    plugin whose schema does not exist.
    """
    states = plugin_states(client, base_url)
    outcome: dict[str, str] = {}
    for name in plugins:
        if name not in states:
            raise RemoteHttpError(
                f"the remote gallery does not know a plugin called {name!r} — "
                f"plugins/{name}/ did not reach the web space. It reported: "
                f"{', '.join(sorted(states)) or 'no plugins at all'}"
            )
        if states[name] == ACTIVE_STATE:
            outcome[name] = ACTIVE_STATE
            continue
        ws_call(
            client,
            base_url,
            "pwg.plugins.performAction",
            {"action": ACTIVATE_ACTION, "plugin": name, "pwg_token": token},
        )
        outcome[name] = "activated"
    return outcome


# --- sync -----------------------------------------------------------------------------


def sync(client, base_url: str) -> SyncCounts:
    """Replay the site_update form, and read its summary back.

    Site id 1 is the row install.php:390-394 creates with galleries_url './galleries/'.
    Needs the admin session cookie login() established: without it admin.php answers 200
    with the login page, which parse_sync_counts refuses rather than reads as zeroes.
    """
    url = site_url(base_url, SYNC_PATH)
    response = client.post(url, SYNC_FIELDS, timeout=SYNC_TIMEOUT_SECONDS)
    try:
        return parse_sync_counts(response.body)
    except RemoteHttpError as error:
        raise RemoteHttpError(
            f"{url} did not return a site_update summary (answered by "
            f"{response.url}): {error}"
        ) from error


def parse_sync_counts(body: str) -> SyncCounts:
    """The summary list, read by class rather than by its localised label."""
    added = []
    deleted = []
    errors = 0
    for css_class, value in _SUMMARY_ITEM.findall(body):
        if css_class == "update_summary_new":
            added.append(int(value))
        elif css_class == "update_summary_del":
            deleted.append(int(value))
        elif css_class == "update_summary_err":
            errors = int(value)
    if len(added) < MIN_SUMMARY_ADDED_LINES:
        raise RemoteHttpError(
            f"expected {MIN_SUMMARY_ADDED_LINES} 'added' summary lines, found {len(added)}"
        )
    if len(deleted) < MIN_SUMMARY_DELETED_LINES:
        raise RemoteHttpError(
            f"expected {MIN_SUMMARY_DELETED_LINES} 'deleted' summary lines, "
            f"found {len(deleted)}"
        )
    # Both buckets are albums first, photos second (site_update.tpl:19-22).
    return SyncCounts(
        albums_added=added[0],
        photos_added=added[1],
        albums_deleted=deleted[0],
        photos_deleted=deleted[1],
        errors=errors,
    )


# --- the whole bootstrap --------------------------------------------------------------


def run(config: DeployConfig, state_dir: Path, transport, client) -> BootstrapResult:
    """Install if needed, publish the config, log in, activate, then scan."""
    installed_now = False
    if not is_installed(client, config.site.base_url):
        install(client, config)
        installed_now = True

    config_uploaded = upload_config(config, state_dir, transport)

    token = login(client, config)
    plugins = activate_plugins(client, config.site.base_url, token)
    counts = sync(client, config.site.base_url)

    return BootstrapResult(
        installed=installed_now,
        config_uploaded=config_uploaded,
        plugins=plugins,
        sync=counts,
    )
