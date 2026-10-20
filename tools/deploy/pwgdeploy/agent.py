"""The temporary remote script of pwg-backup: its rendering and the client that talks to it.

`render()` fills `agent.php` with the sha256 of a one-time token, an expiry and the dump
directory; the token itself never reaches the file. `AgentClient` is a thin translation of the
JSON protocol in the change's design.md; it decides nothing.
"""

from __future__ import annotations

import hashlib
import json
from pathlib import Path

from pwgdeploy.bootstrap import _php_string
from pwgdeploy.errors import RemoteHttpError
from pwgdeploy.http import HttpClient
from pwgdeploy.snapshot import RemoteFile

TEMPLATE = Path(__file__).with_name("agent.php")
DEFAULT_MYSQLDUMP = "mysqldump"
DUMP_TIMEOUT_SECONDS = 1800
LIST_TIMEOUT_SECONDS = 600


class AgentError(RemoteHttpError):
    """The temporary script refused or failed, or answered something that is not its protocol."""


def token_hash(token: str) -> str:
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


def render(
    token: str, expiry: int, dump_dir: str, *, mysqldump: str = DEFAULT_MYSQLDUMP
) -> str:
    """The script's source. `expiry` is a Unix time; `dump_dir` is relative to the web root or absolute."""
    source = TEMPLATE.read_text(encoding="utf-8")
    literals = {
        "TOKEN_SHA256": _php_string(token_hash(token)),
        "EXPIRES": str(int(expiry)),
        "DUMP_DIR": _php_string(dump_dir),
        "MYSQLDUMP": _php_string(mysqldump),
    }
    for name, literal in literals.items():
        source = source.replace(f"'@@{name}@@'", literal)
    return source


class AgentClient:
    def __init__(self, client: HttpClient, url: str, token: str):
        self._client = client
        self._url = url
        self._token = token

    def dump(self) -> dict:
        return self._call("dump", timeout=DUMP_TIMEOUT_SECONDS)

    def list_files(self) -> tuple[list[RemoteFile], list[str]]:
        answer = self._call("list", timeout=LIST_TIMEOUT_SECONDS)
        files = [RemoteFile(f["path"], f["size"], f["mtime"]) for f in answer["files"]]
        return files, list(answer["db_paths"])

    def import_dump(self, path: str) -> int:
        return self._call("import", {"path": path}, timeout=DUMP_TIMEOUT_SECONDS)["statements"]

    def cleanup(self) -> list[str]:
        return self._call("cleanup")["removed"]

    def _call(self, action: str, extra: dict | None = None, *, timeout: int = 60) -> dict:
        fields = {"action": action, "token": self._token, **(extra or {})}
        response = self._client.post(self._url, fields, timeout=timeout)
        try:
            answer = json.loads(response.body)
        except ValueError as error:
            raise AgentError(f"{self._url} {action}: answer is not JSON") from error
        if response.status != 200 or not isinstance(answer, dict):
            message = answer.get("error", "") if isinstance(answer, dict) else ""
            raise AgentError(f"{self._url} {action} returned HTTP {response.status}: {message}")
        try:
            self._check(action, answer)
        except (KeyError, TypeError) as error:
            raise AgentError(f"{self._url} {action}: unexpected answer") from error
        return answer

    @staticmethod
    def _check(action: str, answer: dict) -> None:
        required = {
            "dump": ("path", "size", "method", "counts"),
            "list": ("files", "db_paths"),
            "import": ("statements",),
            "cleanup": ("removed",),
        }[action]
        for key in required:
            answer[key]
