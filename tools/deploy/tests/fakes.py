"""In-memory stand-ins for the two ports, so every decision is tested without a network.

`FakeTransport` is what the whole of the upload suite runs against. It keeps the files it
was given, and a single ordered call log — the order is the point in several tests
(a directory must exist before the file that lands in it).
"""

from __future__ import annotations

import json
from pathlib import Path

from pwgdeploy.errors import TransportError
from pwgdeploy.http import Response


class FakeTransport:
    """Records every operation in order; can be armed to fail on the Nth put.

    `interrupt_on_put` is the Ctrl-C of a real run: a `BaseException`, which `except
    Exception` does not catch and which therefore reaches a `finally` differently from
    `fail_on_put`'s ordinary error. Resuming after one is a criterion of its own.
    """

    def __init__(
        self,
        *,
        fail_on_put: int | None = None,
        interrupt_on_put: int | None = None,
        chmod_supported: bool = True,
    ):
        self.fail_on_put = fail_on_put
        self.interrupt_on_put = interrupt_on_put
        self.chmod_supported = chmod_supported
        # remote path -> bytes, including anything seeded before the run to stand for
        # content this deploy never wrote.
        self.files: dict[str, bytes] = {}
        self.dirs: list[str] = []
        self.calls: list[tuple] = []
        self.connected = False
        self.closed = False
        self._puts = 0

    # --- the port ------------------------------------------------------------------

    def connect(self) -> None:
        self.calls.append(("connect",))
        self.connected = True

    def close(self) -> None:
        self.calls.append(("close",))
        self.closed = True

    def makedirs(self, remote_dir: str) -> None:
        self.calls.append(("makedirs", remote_dir))
        if remote_dir not in self.dirs:
            self.dirs.append(remote_dir)

    def put(self, local: Path, remote_path: str) -> None:
        self._puts += 1
        if self._puts == self.interrupt_on_put:
            self.calls.append(("put-interrupted", remote_path))
            raise KeyboardInterrupt()
        if self._puts == self.fail_on_put:
            self.calls.append(("put-failed", remote_path))
            raise TransportError(f"fake transport refused put #{self._puts}: {remote_path}")
        self.calls.append(("put", remote_path))
        self.files[remote_path] = Path(local).read_bytes()

    def delete(self, remote_path: str) -> None:
        self.calls.append(("delete", remote_path))
        self.files.pop(remote_path, None)

    def chmod(self, remote_path: str, mode: str) -> bool:
        self.calls.append(("chmod", remote_path, mode))
        return self.chmod_supported

    def exists(self, remote_path: str) -> bool:
        self.calls.append(("exists", remote_path))
        return remote_path in self.files

    # --- what tests ask it -----------------------------------------------------------

    def names(self) -> list[str]:
        return [call[0] for call in self.calls]

    def paths(self, operation: str) -> list[str]:
        return [call[1] for call in self.calls if call[0] == operation]


class FakeGallery:
    """The remote gallery as the bootstrap sees it: install marker, ws.php, site_update.

    Stateful on purpose. Every idempotence claim in this suite — an install skipped on a
    second run, an already-active plugin left alone — is a question about what the
    *server* remembers, and a client scripted with canned replies could not be asked it.

    Each endpoint mirrors one real one: the marker install.php:156-165 dies with, the
    JSON envelope include/ws_protocols/json_encoder.php builds, the token and webmaster
    checks of include/ws_functions/pwg.extensions.php:53-88, and the summary markup of
    admin/themes/default/template/site_update.tpl:19-24.
    """

    LOGIN_PAGE = "identification.php"
    TOKEN = "0123456789abcdef"
    # What this fake's gallery is running. A test that needs a *matching* local checkout
    # builds one from this attribute rather than typing the literal a second time.
    VERSION = "17.0.0beta1"
    ALL_PLUGINS = ("typetags", "provenance", "persons")

    def __init__(
        self,
        base_url="https://g.example.test",
        *,
        installed=False,
        plugin_states=None,
        install_errors=(),
        version=VERSION,
        albums_added=4,
        photos_added=106,
        albums_deleted=0,
        photos_deleted=0,
        sync_errors=0,
        admin=("webmaster", "p"),
    ):
        self.base_url = base_url
        self.installed = installed
        # Every plugin the filesystem knows about, whether or not it has a database row.
        self.plugin_states = dict(
            plugin_states or {name: "uninstalled" for name in self.ALL_PLUGINS}
        )
        self.install_errors = list(install_errors)
        # PHPWG_VERSION as pwg.getVersion returns it (pwg.php:125-128). Not typed as a
        # string on purpose: a test hands it an int to prove the caller checks.
        self.version = version
        self.albums_added = albums_added
        self.photos_added = photos_added
        self.albums_deleted = albums_deleted
        self.photos_deleted = photos_deleted
        self.sync_errors = sync_errors
        self.admin = admin
        self.logged_in = False
        self.calls: list[tuple] = []

    # --- the port ------------------------------------------------------------------

    def get(self, url, *, timeout=None) -> Response:
        self.calls.append(("get", url, {}))
        return self._route(url, {})

    def post(self, url, fields, *, timeout=None) -> Response:
        self.calls.append(("post", url, dict(fields)))
        return self._route(url, dict(fields))

    # --- what tests ask it -----------------------------------------------------------

    def methods_called(self) -> list[str]:
        return [call[2]["method"] for call in self.calls if "method" in call[2]]

    def posts_to(self, needle: str) -> list[dict]:
        return [call[2] for call in self.calls if call[0] == "post" and needle in call[1]]

    def urls(self) -> list[str]:
        return [call[1] for call in self.calls]

    # --- the endpoints ---------------------------------------------------------------

    def _route(self, url, fields) -> Response:
        if "install.php" in url:
            return Response(url, 200, self._install(fields))
        if "ws.php" in url:
            return Response(url, 200, self._ws(fields))
        if "site_update" in url:
            if not self.logged_in:
                return Response(
                    f"{self.base_url}/{self.LOGIN_PAGE}", 200, "<form>login</form>"
                )
            return Response(url, 200, self._sync_page())
        return Response(url, 404, "not found")

    def _install(self, fields) -> str:
        if self.installed:
            return "Piwigo is already installed"
        if not fields.get("install"):
            return "<form name='install_form'></form>"
        if self.install_errors:
            items = "".join(f"<li>{error}</li>" for error in self.install_errors)
            return f'<div class="errors"><ul>{items}</ul></div>'
        self.installed = True
        return '<div class="infos"><ul><li>ok</li></ul></div>'

    def _ws(self, fields) -> str:
        method = fields.get("method")
        if method == "pwg.session.login":
            if (fields.get("username"), fields.get("password")) != self.admin:
                return _fail(999, "Invalid username/password")
            self.logged_in = True
            return _ok(True)
        if not self.logged_in:
            return _fail(401, "Access denied")
        # Below the logged_in gate although ws.php:57-62 registers pwg.getVersion with no
        # admin_only: an install with guest access disabled refuses it too, and the tool
        # must work against that one.
        if method == "pwg.getVersion":
            return _ok(self.version)
        if method == "pwg.session.getStatus":
            return _ok(
                {
                    "username": self.admin[0],
                    "status": "webmaster",
                    "pwg_token": self.TOKEN,
                }
            )
        if method == "pwg.plugins.getList":
            return _ok(
                [
                    {"id": name, "name": name, "version": "1.0", "state": state}
                    for name, state in sorted(self.plugin_states.items())
                ]
            )
        if method == "pwg.plugins.performAction":
            if fields.get("pwg_token") != self.TOKEN:
                return _fail(403, "Invalid security token")
            if fields.get("plugin") not in self.plugin_states:
                return _fail(500, f"no such plugin {fields.get('plugin')}")
            self.plugin_states[fields["plugin"]] = "active"
            return _ok(True)
        return _fail(501, f"unknown method {method}")

    def _sync_page(self) -> str:
        """The six summary lines site_update.tpl:19-24 emits, in that order."""
        return (
            "<h3>Resultat</h3><ul>"
            f'<li class="update_summary_new">{self.albums_added} Alben</li>'
            f'<li class="update_summary_new">{self.photos_added} Fotos</li>'
            f'<li class="update_summary_del">{self.albums_deleted} Alben</li>'
            f'<li class="update_summary_del">{self.photos_deleted} Fotos</li>'
            "<li>0 Fotos</li>"
            f'<li class="update_summary_err">{self.sync_errors} Fehler</li>'
            "</ul>"
        )


def _ok(result) -> str:
    return json.dumps({"stat": "ok", "result": result})


def _fail(code, message) -> str:
    return json.dumps({"stat": "fail", "err": code, "message": message})
