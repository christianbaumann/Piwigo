"""agent.render() and AgentClient. Techniques: [HAPPY] [NEG] [ECP].

The PHP half (token gate, dump, list, import, cleanup) is witnessed by
tests/integration/test_agent_ddev.py; here only what can be decided without a server.
"""

import hashlib
import json
import re
import shutil
import subprocess

import pytest

from pwgdeploy import agent
from pwgdeploy.errors import RemoteHttpError
from pwgdeploy.http import Response
from pwgdeploy.snapshot import RemoteFile

TOKEN = "s3cret-token-value"
MIN_SOURCE_BYTES = 1000
URL = "https://g.example.test/abc.php"


def test_the_token_is_hashed_and_never_written():
    source = agent.render(TOKEN, 1_800_000_000, "bk-dir")
    assert len(source) > MIN_SOURCE_BYTES
    assert hashlib.sha256(TOKEN.encode()).hexdigest() in source
    assert TOKEN not in source


def test_expiry_and_dump_dir_are_php_literals():
    source = agent.render(TOKEN, 1_800_000_000, "bk-dir")
    assert "define('AGENT_EXPIRES', 1800000000);" in source
    assert "define('AGENT_DUMP_DIR', 'bk-dir');" in source
    assert "@@" not in source


@pytest.mark.parametrize("dump_dir", ["it's", "a\\'b", "x'); die('y", "back\\"])
def test_a_value_with_a_quote_cannot_break_out_of_its_literal(dump_dir):
    source = agent.render(TOKEN, 1, dump_dir)
    line = next(l for l in source.splitlines() if l.startswith("define('AGENT_DUMP_DIR'"))
    literal = line[len("define('AGENT_DUMP_DIR', "):-2]
    assert re.fullmatch(r"'(?:[^'\\]|\\.)*'", literal), literal
    if shutil.which("php"):
        probe = subprocess.run(
            ["php", "-r", f"echo {literal};"], capture_output=True, text=True, check=True
        )
        assert probe.stdout == dump_dir


def test_the_rendered_script_is_valid_php():
    php = shutil.which("php")
    if php is None:
        pytest.skip("php not installed")
    source = agent.render(TOKEN, 1, "d")
    result = subprocess.run([php, "-l"], input=source, capture_output=True, text=True)
    assert result.returncode == 0, result.stdout + result.stderr


class FakeClient:
    def __init__(self, status, body):
        self.response = Response(url=URL, status=status, body=body)
        self.posts = []

    def post(self, url, fields, *, timeout=60):
        self.posts.append((url, dict(fields)))
        return self.response


def client(status, payload):
    fake = FakeClient(status, payload if isinstance(payload, str) else json.dumps(payload))
    return agent.AgentClient(fake, URL, TOKEN), fake


def test_list_files_returns_remote_files_and_db_paths():
    c, fake = client(200, {"files": [{"path": "upload/a.jpg", "size": 3, "mtime": 9}], "db_paths": ["upload/a.jpg"]})
    files, db_paths = c.list_files()
    assert files == [RemoteFile("upload/a.jpg", 3, 9)]
    assert db_paths == ["upload/a.jpg"]
    assert fake.posts == [(URL, {"action": "list", "token": TOKEN})]


def test_dump_import_and_cleanup_send_their_action():
    dump = {"path": "d/dump.sql.gz", "size": 1, "method": "php", "counts": {"images": 1}}
    c, fake = client(200, dump)
    assert c.dump() == dump
    c, fake = client(200, {"statements": 7})
    assert c.import_dump("d/dump.sql.gz") == 7
    assert fake.posts[0][1] == {"action": "import", "token": TOKEN, "path": "d/dump.sql.gz"}
    c, fake = client(200, {"removed": ["agent.php"]})
    assert c.cleanup() == ["agent.php"]


def test_a_non_200_answer_raises_with_the_servers_message():
    c, _ = client(403, {"error": "wrong token"})
    with pytest.raises(agent.AgentError, match="403.*wrong token"):
        c.cleanup()
    assert issubclass(agent.AgentError, RemoteHttpError)


@pytest.mark.parametrize("body", ["<html>", "[]", '{"nothing": 1}'])
def test_an_answer_outside_the_protocol_raises(body):
    c, _ = client(200, body)
    with pytest.raises(agent.AgentError):
        c.dump()


def test_the_script_never_displays_php_errors():
    source = agent.render(TOKEN, 1, "d")
    assert len(source) > MIN_SOURCE_BYTES
    assert source.index("ini_set('display_errors', '0')") < source.index("define('AGENT_TOKEN_SHA256'")
