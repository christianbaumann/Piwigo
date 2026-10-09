"""In-memory stand-ins for the two ports, so every decision is tested without a network.

`FakeTransport` is what the whole of the upload suite runs against. It keeps the files it
was given, and a single ordered call log — the order is the point in several tests
(a directory must exist before the file that lands in it).
"""

from __future__ import annotations

import json
import re
from pathlib import Path

from pwgdeploy.errors import TransportError
from pwgdeploy.http import Response
from pwgdeploy.transport import RemoteEntry

REPO_ROOT = Path(__file__).resolve().parents[3]


def php_value(relative_path: str, pattern: str) -> int:
    """One integer literal out of a PHP source file; fails loudly when the line moved."""
    match = re.search(pattern, (REPO_ROOT / relative_path).read_text(encoding="utf-8"))
    if match is None:
        raise AssertionError(f"{pattern!r} not found in {relative_path}")
    return int(match.group(1))


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

    def list_dir(self, remote_dir: str) -> list[RemoteEntry]:
        """The tree `self.files` implies, one directory at a time.

        Directories are synthesised from the path segments rather than tracked
        separately, so anything seeded into `files` — including content this deploy never
        wrote — is listed exactly as a server would list it. Recorded in `calls` like
        every other operation, which is what lets an audit test assert that no `delete`
        was ever issued.
        """
        self.calls.append(("list_dir", remote_dir))
        prefix = remote_dir.rstrip("/")
        prefix = f"{prefix}/" if prefix else ""
        entries: dict[str, bool] = {}
        for path in self.files:
            if not path.startswith(prefix):
                continue
            name, separator, _rest = path[len(prefix) :].partition("/")
            if name:
                entries[name] = bool(separator)
        return [
            RemoteEntry(name=name, is_dir=is_dir)
            for name, is_dir in sorted(entries.items())
        ]

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
    checks of include/ws_functions/pwg.extensions.php:53-88, the summary markup of
    admin/themes/default/template/site_update.tpl:19-24, the paging of
    pwg.categories.getImages (include/ws_functions/pwg.categories.php:19-232), the
    chunk limit and answer of pwg.photoinfo.rescan (plugins/photoinfo/include/), the tag
    rows of pwg.tags.getAdminList/add (include/ws_functions/pwg.tags.php) and typetags'
    group methods (plugins/typetags/main.inc.php).
    """

    LOGIN_PAGE = "identification.php"
    TOKEN = "0123456789abcdef"
    # What this fake's gallery is running. A test that needs a *matching* local checkout
    # builds one from this attribute rather than typing the literal a second time.
    VERSION = "17.0.0beta1"
    ALL_PLUGINS = ("typetags", "provenance", "persons", "photoinfo")
    # plugin -> the plugin its activate() refuses to run without, as photoinfo's
    # maintain.class.php does.
    REQUIRES = {"photoinfo": "provenance"}
    # Read from the PHP that enforces them, so a change there reaches these tests. ws.php
    # clamps per_page to the first; pwg.photoinfo.rescan refuses a longer id list.
    MAX_PER_PAGE = php_value(
        "include/config_default.inc.php", r"\$conf\['ws_max_images_per_page'\]\s*=\s*(\d+);"
    )
    MAX_RESCAN_CHUNK = php_value(
        "plugins/photoinfo/include/functions.inc.php",
        r"define\('PHOTOINFO_RESCAN_MAX_CHUNK',\s*(\d+)\);",
    )

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
        photo_ids=None,
        rescan_failures=None,
        rescan_tags_added=None,
        not_in_file=None,
        prune_error=None,
        prune_failures=None,
        prune_raises=None,
        tag_groups=None,
        tags=None,
        inert_methods=(),
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
        # The photos the sync registered, as getImages lists them.
        self.photo_ids = list(
            range(1, photos_added + 1) if photo_ids is None else photo_ids
        )
        # photo id -> the reason pwg.photoinfo.rescan gives for it.
        self.rescan_failures = dict(rescan_failures or {})
        self.rescanned: list[int] = []
        # photo id -> tags the rescan links from its file, and the tags it has that its
        # file does not name, as pwg.photoinfo.rescan reports them.
        self.rescan_tags_added = dict(rescan_tags_added or {})
        self.not_in_file = {i: list(names) for i, names in (not_in_file or {}).items()}
        # A message pwg.photoinfo.pruneTags fails every call with; None answers.
        self.prune_error = prune_error
        # photo id -> the reason pwg.photoinfo.pruneTags gives for not reading its file.
        self.prune_failures = dict(prune_failures or {})
        # An exception the connection raises on a prune request, as a read timeout would.
        self.prune_raises = prune_raises
        self.pruned: list[int] = []
        # typetags' groups as typetags.type.list answers them, and core's tag rows as
        # pwg.tags.getAdminList does: ids as strings, id_typetags a string or None.
        self.tag_groups = [dict(group) for group in tag_groups or []]
        self.tags = [dict(tag) for tag in tags or []]
        # Methods that answer ok and change nothing: a server that lost a write.
        self.inert_methods = set(inert_methods)
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
            required = self.REQUIRES.get(fields["plugin"])
            if required and self.plugin_states.get(required) != "active":
                return _fail(500, f"{fields['plugin']} requires {required}")
            self.plugin_states[fields["plugin"]] = "active"
            return _ok(True)
        if method == "pwg.categories.getImages":
            return self._images_page(fields)
        if method == "pwg.photoinfo.rescan":
            return self._rescan(fields)
        if method == "pwg.photoinfo.pruneTags":
            return self._prune(fields)
        if method == "pwg.tags.getAdminList":
            return _ok({"tags": [dict(tag, name_raw=tag["name"]) for tag in self.tags]})
        if method == "pwg.tags.add":
            return self._tags_add(fields)
        if method.startswith("typetags."):
            return self._typetags(method, fields)
        return _fail(501, f"unknown method {method}")

    def _tags_add(self, fields) -> str:
        name = fields.get("name", "")
        if any(_collated(tag["name"]) == _collated(name) for tag in self.tags):
            return _fail(1003, f'Tag "{name}" already exists')
        tag_id = str(1 + max((int(tag["id"]) for tag in self.tags), default=0))
        if "pwg.tags.add" not in self.inert_methods:
            self.tags.append({"id": tag_id, "name": name, "id_typetags": None})
        return _ok({"id": int(tag_id), "name": name})

    def _typetags(self, method, fields) -> str:
        if self.plugin_states.get("typetags") != "active":
            return _fail(501, f"unknown method {method}")
        inert = method in self.inert_methods
        if method == "typetags.type.list":
            return _ok([dict(group) for group in self.tag_groups])
        if method == "typetags.type.add":
            if any(
                _collated(g["name"]) == _collated(fields["typetag_name"])
                for g in self.tag_groups
            ):
                return _fail(1003, "This name is already used")
            group = {
                "id": 1 + max((g["id"] for g in self.tag_groups), default=0),
                "name": fields["typetag_name"],
                "color": "#" + fields["typetag_color"],
                "striped": fields.get("striped") == "1",
                "emoji": fields.get("emoji", ""),
            }
            if not inert:
                self.tag_groups.append(group)
            return _ok(dict(group))
        if method == "typetags.type.update":
            if fields.get("pwg_token") != self.TOKEN:
                return _fail(403, "Invalid security token")
            group = next(
                (g for g in self.tag_groups if g["id"] == int(fields["typetag_id"])), None
            )
            if group is None:
                return _fail(404, "Tag color not found")
            answer = dict(
                group,
                color="#" + fields["typetag_color"].lstrip("#"),
                striped=fields["striped"] == "1",
                emoji=fields["emoji"],
            )
            if not inert:
                group.update(answer)
            return _ok(answer)
        if method == "typetags.tags.setType":
            for tag in [] if inert else self.tags:
                if tag["id"] == fields["tag_id"]:
                    tag["id_typetags"] = fields["typetag_id"]
            return _ok(None)
        return _fail(501, f"unknown method {method}")

    def _images_page(self, fields) -> str:
        per_page = int(fields.get("per_page", 100))
        page = int(fields.get("page", 0))
        per_page = min(per_page, self.MAX_PER_PAGE)
        ordered = sorted(self.photo_ids) if fields.get("order") == "id" else self.photo_ids
        listed = ordered[page * per_page : (page + 1) * per_page]
        return _ok(
            {
                "paging": {
                    "page": page,
                    "per_page": per_page,
                    "count": len(listed),
                    "total_count": len(self.photo_ids),
                },
                "images": [{"id": photo_id, "file": f"{photo_id}.png"} for photo_id in listed],
            }
        )

    def _rescan(self, fields) -> str:
        if self.plugin_states.get("photoinfo") != "active":
            return _fail(501, "unknown method pwg.photoinfo.rescan")
        if fields.get("pwg_token") != self.TOKEN:
            return _fail(403, "Invalid security token")
        ids = [int(part) for part in fields.get("image_ids", "").split(",") if part]
        if not 0 < len(ids) <= self.MAX_RESCAN_CHUNK:
            return _fail(1003, f"image_ids must be 1 to {self.MAX_RESCAN_CHUNK} photo ids")
        self.rescanned.extend(ids)
        failed = {i: self.rescan_failures[i] for i in ids if i in self.rescan_failures}
        # PHP's json_encode: an empty array is a list, an id-keyed one an object with
        # string keys.
        return _ok(
            {
                "scanned": len(ids) - len(failed),
                "failed": {str(i): reason for i, reason in failed.items()} or [],
                "tags_added": sum(self.rescan_tags_added.get(i, 0) for i in ids),
                "not_in_file": {
                    str(i): self.not_in_file[i] for i in ids if self.not_in_file.get(i)
                }
                or [],
            }
        )

    def _prune(self, fields) -> str:
        if self.plugin_states.get("photoinfo") != "active":
            return _fail(501, "unknown method pwg.photoinfo.pruneTags")
        if fields.get("pwg_token") != self.TOKEN:
            return _fail(403, "Invalid security token")
        ids = [int(part) for part in fields.get("image_ids", "").split(",") if part]
        if not 0 < len(ids) <= self.MAX_RESCAN_CHUNK:
            return _fail(1003, f"image_ids must be 1 to {self.MAX_RESCAN_CHUNK} photo ids")
        if self.prune_raises is not None:
            raise self.prune_raises
        if self.prune_error is not None:
            return _fail(500, self.prune_error)
        self.pruned.extend(ids)
        failed = {i: self.prune_failures[i] for i in ids if i in self.prune_failures}
        removed = {
            i: self.not_in_file.pop(i) for i in ids if self.not_in_file.get(i) and i not in failed
        }
        return _ok(
            {
                "removed": {str(i): names for i, names in removed.items()} or [],
                "failed": {str(i): reason for i, reason in failed.items()} or [],
            }
        )

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


def _collated(name: str) -> str:
    """How utf8mb3_general_ci compares two names: no case, no accents, no trailing spaces.
    Written independently of seed.py's own folding, so a test is not the code agreeing
    with itself."""
    import unicodedata

    stripped = "".join(
        c for c in unicodedata.normalize("NFKD", name) if not unicodedata.combining(c)
    )
    return stripped.lower().rstrip(" ")


def _ok(result) -> str:
    return json.dumps({"stat": "ok", "result": result})


def _fail(code, message) -> str:
    return json.dumps({"stat": "fail", "err": code, "message": message})
