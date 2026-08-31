"""The FTPS hand check's orchestration.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA] [ST].
Boundary values not applicable beyond the empty remote root covered here: the module has
no numeric domain of its own. What these tests cannot witness is the part that needs a
server — that is the point of the module, and it is recorded in the hand-check ledger.
"""

import pytest

from pwgdeploy import smoke
from pwgdeploy.config import load
from pwgdeploy.errors import RemoteHttpError
from tests.fakes import FakeTransport

TOKEN = "deadbeefdeadbeef"

RAW = {
    "ftp": {"host": "ftp.example.test", "user": "w1", "password": "p", "remote_root": "/piwigo"},
    "mysql": {"host": "localhost", "user": "d1", "password": "p", "database": "d1"},
    "admin": {"username": "webmaster", "password": "p", "email": "you@example.net"},
    "site": {"base_url": "https://g.example.test"},
}

EXPECTED_REMOTE = f"/piwigo/pwg-deploy-smoke-{TOKEN}.txt"
EXPECTED_URL = f"https://g.example.test/pwg-deploy-smoke-{TOKEN}.txt"


def config(**site_overrides):
    raw = {section: dict(values) for section, values in RAW.items()}
    raw["ftp"].update(site_overrides.pop("ftp", {}))
    return load(raw)


class Web:
    """The document root as HTTP sees it. Backed by the fake transport's own files, so
    "uploaded over FTP" and "served over HTTP" cannot drift apart in the test itself."""

    def __init__(self, transport, remote_of_url, *, serve_stale=None):
        self.transport = transport
        self.remote_of_url = remote_of_url
        self.serve_stale = serve_stale
        self.fetched = []

    def __call__(self, url):
        self.fetched.append(url)
        if self.serve_stale is not None:
            return self.serve_stale
        return self.transport.files.get(self.remote_of_url)


def probe_body(token=TOKEN):
    return smoke.PROBE_BODY_TEMPLATE.format(token=token).encode("utf-8")


# --- the happy path ------------------------------------------------------------------


def test_the_probe_is_uploaded_read_back_and_deleted():
    """The whole check, in order. [HAPPY] [ST]"""
    transport = FakeTransport()
    web = Web(transport, EXPECTED_REMOTE)

    result = smoke.run(config(), transport, web, token=TOKEN)

    assert result == smoke.SmokeResult(
        remote=EXPECTED_REMOTE, url=EXPECTED_URL, body_matched=True, gone_after_delete=True
    )
    assert transport.names() == ["connect", "put", "delete", "close"]


def test_the_probe_body_carries_the_token():
    """A stale file or a caching proxy must not be able to satisfy the check. [HAPPY]"""
    transport = FakeTransport()
    web = Web(transport, EXPECTED_REMOTE)

    smoke.run(config(), transport, web, token=TOKEN)

    assert TOKEN.encode() in probe_body()
    assert transport.files == {} or web.fetched  # the file is gone by the end
    assert web.fetched == [EXPECTED_URL, EXPECTED_URL]


def test_an_empty_remote_root_uploads_beside_the_login_directory():
    """A web space whose document root is the login directory. [ECP] [BVA]"""
    conf = config(ftp={"remote_root": ""})
    expected = f"pwg-deploy-smoke-{TOKEN}.txt"
    transport = FakeTransport()

    result = smoke.run(conf, transport, Web(transport, expected), token=TOKEN)

    assert result.remote == expected
    assert result.url == EXPECTED_URL


# --- what it refuses -----------------------------------------------------------------


def test_bytes_that_do_not_match_are_a_failure_naming_both_paths():
    """The FTP root and the document root being different directories is the whole
    reason this check exists; the message has to say so. [NEG]"""
    transport = FakeTransport()
    web = Web(transport, EXPECTED_REMOTE, serve_stale=b"someone else's file\n")

    with pytest.raises(RemoteHttpError) as raised:
        smoke.run(config(), transport, web, token=TOKEN)

    message = str(raised.value)
    assert EXPECTED_URL in message
    assert EXPECTED_REMOTE in message


def test_a_missing_file_is_a_failure_not_a_silent_pass():
    """fetch returning None must not compare equal to the uploaded body. [NEG]"""
    transport = FakeTransport()
    web = Web(transport, "/somewhere/else.txt")

    with pytest.raises(RemoteHttpError):
        smoke.run(config(), transport, web, token=TOKEN)


def test_a_probe_still_served_after_delete_is_reported_not_raised():
    """Deletion failing is worth knowing, but it does not invalidate the upload. [NEG]"""
    transport = FakeTransport()
    web = Web(transport, EXPECTED_REMOTE)
    web.serve_stale = None

    def fetch(url):
        web.fetched.append(url)
        return probe_body()

    result = smoke.run(config(), transport, fetch, token=TOKEN)

    assert result.gone_after_delete is False


def test_the_session_is_closed_even_when_the_check_fails():
    """A left-open FTPS session holds a connection slot on a shared web space. [NEG]"""
    transport = FakeTransport()
    web = Web(transport, EXPECTED_REMOTE, serve_stale=b"wrong\n")

    with pytest.raises(RemoteHttpError):
        smoke.run(config(), transport, web, token=TOKEN)

    assert transport.closed is True


def test_each_run_uses_a_fresh_token():
    """Two runs must not collide on one remote name. [ST]"""
    remotes = set()
    for _ in range(2):
        transport = FakeTransport()
        captured = {}

        def fetch(url, transport=transport, captured=captured):
            captured["remote"] = next(iter(transport.files), None)
            return transport.files.get(captured["remote"])

        remotes.add(smoke.run(config(), transport, fetch).remote)

    assert len(remotes) == 2


# --- the entry point -----------------------------------------------------------------


def test_main_without_a_credential_file_exits_two(capsys):
    """[NEG]"""
    assert smoke.main([]) == 2
    assert "usage:" in capsys.readouterr().err


def test_main_reports_a_bad_credential_file_with_the_config_exit_code(tmp_path, capsys):
    """No connection is attempted for a file that cannot be a valid target. [NEG]"""
    bad = tmp_path / "deploy.local.json"
    bad.write_text("{}", encoding="utf-8")

    from pwgdeploy.errors import ConfigError

    assert smoke.main([str(bad)]) == ConfigError.exit_code
    assert "ConfigError" in capsys.readouterr().err
