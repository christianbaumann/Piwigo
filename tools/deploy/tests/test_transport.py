"""The FTPS adapter: the handshake it refuses, and the commands it translates.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA] [DT] [ERR] [ST].

The adapter contains no decisions beyond the ones below, so these tests drive it through a
scripted `ftplib` double rather than a server. Decision table not applicable: no operation
here has more than one condition.
"""

import ftplib

import pytest

from pwgdeploy import transport
from pwgdeploy.errors import InsecureTransportError, TransportError

# The two FEAT bodies that matter: one advertising AUTH TLS, one that does not. Both are
# shaped like a real multi-line FEAT reply so the message-scraping test is not vacuous.
FEAT_WITH_TLS = "211-Features:\n AUTH TLS\n PBSZ\n PROT\n UTF8\n211 End"
FEAT_WITHOUT_TLS = "211-Features:\n PASV\n SIZE\n UTF8\n211 End"

HOST = "ftp.example.test"
USER = "webspace"
PASSWORD = "s3cret"


class ScriptedFtp:
    """A double for `ftplib.FTP_TLS`. Records every call in order, and can be told to
    refuse a command with the permanent error a real server would send."""

    def __init__(self, feat=FEAT_WITH_TLS, refuse=(), **kwargs):
        self.feat = feat
        self.refuse = tuple(refuse)
        self.init_kwargs = kwargs
        self.calls = []
        self.stored = {}

    def _record(self, name, *args):
        self.calls.append((name,) + args)

    def _maybe_refuse(self, command):
        if command.startswith(self.refuse):
            raise ftplib.error_perm(f"550 {command}: permission denied")

    def connect(self, host, port):
        self._record("connect", host, port)

    def sendcmd(self, command):
        self._record("sendcmd", command)
        if command == "FEAT":
            return self.feat
        self._maybe_refuse(command)
        return "200 ok"

    def login(self, user, password):
        self._record("login", user, password)

    def prot_p(self):
        self._record("prot_p")

    def set_pasv(self, value):
        self._record("set_pasv", value)

    def mkd(self, path):
        self._record("mkd", path)
        self._maybe_refuse("MKD " + path)

    def storbinary(self, command, handle, blocksize):
        self._record("storbinary", command, blocksize)
        self.stored[command.split(" ", 1)[1]] = handle.read()

    def delete(self, path):
        self._record("delete", path)

    def size(self, path):
        self._record("size", path)
        self._maybe_refuse("SIZE " + path)
        return 0

    def quit(self):
        self._record("quit")


def connected(**kwargs):
    """A transport already through its handshake, plus the double behind it."""
    double = ScriptedFtp(**kwargs)
    subject = transport.FtplibTransport(
        HOST, USER, PASSWORD, ftp_factory=lambda **_: double
    )
    subject.connect()
    double.calls.clear()
    return subject, double


def names(double):
    return [call[0] for call in double.calls]


def made_dirs(double):
    return [call[1] for call in double.calls if call[0] == "mkd"]


# --- the handshake ------------------------------------------------------------------


def test_feat_without_auth_tls_raises():
    """Logging in anyway would send the password in clear. [NEG]"""
    subject = transport.FtplibTransport(
        HOST, USER, PASSWORD, ftp_factory=lambda **_: ScriptedFtp(feat=FEAT_WITHOUT_TLS)
    )

    with pytest.raises(InsecureTransportError) as raised:
        subject.connect()

    message = str(raised.value)
    assert HOST in message
    assert "PASV" in message and "SIZE" in message, "the advertised features are missing"
    assert transport.AUTH_TLS_FEATURE in message


def test_a_refused_handshake_never_sends_the_password():
    """Anti-vacuity for the test above: the point is what did *not* happen. [NEG]"""
    double = ScriptedFtp(feat=FEAT_WITHOUT_TLS)
    subject = transport.FtplibTransport(
        HOST, USER, PASSWORD, ftp_factory=lambda **_: double
    )

    with pytest.raises(InsecureTransportError):
        subject.connect()

    assert "login" not in names(double)
    assert PASSWORD not in repr(double.calls)


def test_feat_with_auth_tls_logs_in_and_calls_prot_p():
    """The full handshake, in order: connect, FEAT, login, PROT P. [HAPPY] [ST]"""
    double = ScriptedFtp()
    subject = transport.FtplibTransport(
        HOST, USER, PASSWORD, port=2121, ftp_factory=lambda **_: double
    )

    subject.connect()

    assert double.calls == [
        ("connect", HOST, 2121),
        ("sendcmd", "FEAT"),
        ("login", USER, PASSWORD),
        ("prot_p",),
        ("set_pasv", True),
    ]


def test_prot_p_comes_after_login():
    """PROT P before the login would be rejected; assert the order, not just presence. [ST]"""
    double = ScriptedFtp()
    transport.FtplibTransport(
        HOST, USER, PASSWORD, ftp_factory=lambda **_: double
    ).connect()

    order = names(double)
    assert order.index("login") < order.index("prot_p")


def test_the_connection_is_given_an_explicit_timeout():
    """A blocking connect would hang a deploy forever. [ERR]"""
    seen = {}

    def factory(**kwargs):
        seen.update(kwargs)
        return ScriptedFtp()

    transport.FtplibTransport(HOST, USER, PASSWORD, ftp_factory=factory).connect()

    assert seen["timeout"] == transport.CONNECT_TIMEOUT_SECONDS
    assert seen["timeout"] > 0


def test_an_unreachable_host_is_a_transport_error_not_a_traceback():
    """Found by running `python3 -m pwgdeploy.smoke` against an unresolvable host: the
    socket error escaped as a traceback, so the CLI's exit codes never applied. [NEG]"""

    def factory(**_):
        raise OSError(8, "nodename nor servname provided, or not known")

    subject = transport.FtplibTransport(HOST, USER, PASSWORD, port=2121, ftp_factory=factory)

    with pytest.raises(TransportError) as raised:
        subject.connect()

    message = str(raised.value)
    assert HOST in message and "2121" in message
    assert "nodename" in message, "the underlying reason must survive"


def test_a_refused_login_is_a_transport_error():
    """Wrong credentials are the everyday case, and ftplib raises error_perm. [NEG]"""

    class RefusingFtp(ScriptedFtp):
        def login(self, user, password):
            raise ftplib.error_perm("530 Login incorrect")

    subject = transport.FtplibTransport(
        HOST, USER, PASSWORD, ftp_factory=lambda **_: RefusingFtp()
    )

    with pytest.raises(TransportError) as raised:
        subject.connect()

    assert "530" in str(raised.value)


def test_a_cleartext_server_is_still_reported_as_insecure_not_merely_as_a_failure():
    """Anti-vacuity for the wrapping above: it must not swallow the specific type. [NEG]"""
    subject = transport.FtplibTransport(
        HOST, USER, PASSWORD, ftp_factory=lambda **_: ScriptedFtp(feat=FEAT_WITHOUT_TLS)
    )

    with pytest.raises(InsecureTransportError):
        subject.connect()


# --- makedirs -----------------------------------------------------------------------


def test_makedirs_creates_each_segment_once():
    """mkdir -p over FTP is one MKD per segment; a second path reuses what exists. [HAPPY]"""
    subject, double = connected()

    subject.makedirs("/www/htdocs/plugins")
    subject.makedirs("/www/htdocs/themes")

    assert made_dirs(double) == [
        "/www",
        "/www/htdocs",
        "/www/htdocs/plugins",
        "/www/htdocs/themes",
    ]


def test_makedirs_treats_already_exists_as_success():
    """A server answering 550 to MKD on an existing directory is not a failure. [ERR]"""
    subject, double = connected(refuse=("MKD",))

    subject.makedirs("/www/htdocs")

    assert made_dirs(double) == ["/www", "/www/htdocs"]


def test_makedirs_of_the_root_creates_nothing():
    """[BVA]"""
    subject, double = connected()

    subject.makedirs("/")

    assert double.calls == []


def test_makedirs_of_a_relative_path_creates_each_segment():
    """A remote root of "" yields relative paths; they must still be created. [ECP]"""
    subject, double = connected()

    subject.makedirs("plugins/persons")

    assert made_dirs(double) == ["plugins", "plugins/persons"]


# --- put / delete / chmod / exists ---------------------------------------------------


def test_put_stores_the_file_bytes_under_the_remote_path(tmp_path):
    """[HAPPY]"""
    local = tmp_path / "index.php"
    local.write_bytes(b"<?php // piwigo\n")
    subject, double = connected()

    subject.put(local, "/www/index.php")

    assert double.stored == {"/www/index.php": b"<?php // piwigo\n"}
    assert (
        "storbinary",
        "STOR /www/index.php",
        transport.TRANSFER_BLOCKSIZE,
    ) in double.calls


def test_delete_removes_the_remote_path():
    """[HAPPY]"""
    subject, double = connected()

    subject.delete("/www/gone.php")

    assert double.calls == [("delete", "/www/gone.php")]


def test_chmod_sends_site_chmod():
    """[HAPPY]"""
    subject, double = connected()

    assert subject.chmod("/www/_data", "0777") is True
    assert double.calls == [("sendcmd", "SITE CHMOD 0777 /www/_data")]


def test_chmod_returns_false_when_site_chmod_is_refused():
    """SITE CHMOD is an optional extension: a refusal is a warning, not an exception. [NEG]"""
    subject, _double = connected(refuse=("SITE CHMOD",))

    assert subject.chmod("/www/_data", "0777") is False


def test_exists_is_true_for_a_path_the_server_reports():
    """[HAPPY]"""
    subject, _double = connected()

    assert subject.exists("/www/index.php") is True


def test_exists_is_false_when_the_server_refuses_the_path():
    """[NEG]"""
    subject, _double = connected(refuse=("SIZE",))

    assert subject.exists("/www/nothing.php") is False


def test_close_quits_the_session():
    """[HAPPY]"""
    subject, double = connected()

    subject.close()

    assert names(double) == ["quit"]


def test_close_before_connect_is_a_no_op():
    """A failed handshake still runs the caller's cleanup. [NEG] [BVA]"""
    transport.FtplibTransport(HOST, USER, PASSWORD).close()
