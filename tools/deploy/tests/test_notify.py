"""The failure mail and its SMTP adapter. Techniques: [HAPPY] [ECP] [NEG] [BVA].

`send()` runs against a fake SMTP class; no socket is opened.
"""

import pytest

from pwgdeploy import config, notify

RAW = {
    "ftp": {"host": "ftp.example.test", "user": "w1", "password": "ftpsecret", "remote_root": "/"},
    "mysql": {"host": "localhost", "user": "d1", "password": "dbsecret", "database": "d1"},
    "admin": {"username": "webmaster", "password": "adminsecret", "email": "you@example.net"},
    "site": {"base_url": "https://bilder.example.test"},
    "mail": {
        "host": "smtp.example.test", "port": 465, "secure": "ssl", "user": "u", "password": "mailsecret",
        "sender_email": "noreply@example.test",
    },
}
SECRETS = ("ftpsecret", "dbsecret", "adminsecret", "mailsecret")


def make(**backup):
    return config.load({**RAW, "backup": {"dir": "/tmp/pwg-bk-test", **backup}})


def build(cfg, error="boom", log_tail=("a", "b"), secrets=()):
    return notify.failure_mail(
        cfg.mail, notify.recipient_of(cfg), cfg.site.base_url, "daily", "TransportError", error,
        list(log_tail), secrets,
    )


def test_the_subject_names_the_gallery_and_the_failed_step():  # [HAPPY]
    message = build(make())
    assert message["Subject"] == "pwg-backup failed: bilder.example.test (TransportError)"
    assert message["From"] == "noreply@example.test"
    assert message["To"] == "you@example.net"


def test_the_body_holds_the_error_and_the_last_log_lines():  # [HAPPY]
    tail = [f"line{i}" for i in range(notify.LOG_TAIL_LINES + 5)]
    body = build(make(), error="login refused", log_tail=tail).get_content()
    assert "login refused" in body
    assert f"line{len(tail) - 1}" in body
    assert "line4\n" not in body
    assert "line5" in body


def test_notify_overrides_the_admin_address():  # [ECP]
    assert build(make(notify="ops@example.net"))["To"] == "ops@example.net"


def test_a_notify_that_is_not_an_address_is_refused():  # [NEG]
    with pytest.raises(config.ConfigError, match="backup.notify"):
        make(notify="nobody")


def test_no_configured_password_survives_into_the_mail():  # [NEG]
    cfg = make()
    assert len(notify.secrets_of(cfg)) == len(SECRETS)
    error = " ".join(f"x{s}x" for s in SECRETS)
    message = build(cfg, error=error, log_tail=[SECRETS[0]], secrets=notify.secrets_of(cfg))
    text = message.as_string()
    for secret in SECRETS:
        assert secret not in text


class FakeSmtp:
    instances = []

    def __init__(self, host, port, timeout=None):
        self.args, self.timeout, self.calls = (host, port), timeout, []
        type(self).instances.append(self)

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def starttls(self):
        self.calls.append("starttls")

    def login(self, user, password):
        self.calls.append(("login", user, password))

    def send_message(self, message):
        self.calls.append(("send", message["To"]))


class FakePlain(FakeSmtp):
    instances = []


@pytest.fixture(autouse=True)
def reset():
    FakeSmtp.instances.clear()
    FakePlain.instances.clear()


def test_ssl_connects_encrypted_without_starttls():  # [HAPPY]
    cfg = make()
    notify.send(cfg.mail, build(cfg), smtp_ssl=FakeSmtp, smtp=FakePlain)

    (conn,) = FakeSmtp.instances
    assert FakePlain.instances == []
    assert conn.args == ("smtp.example.test", 465)
    assert conn.timeout == notify.SMTP_TIMEOUT_SECONDS
    assert conn.calls == [("login", "u", "mailsecret"), ("send", "you@example.net")]


def test_tls_upgrades_with_starttls_before_login():  # [ECP]
    raw = {**RAW, "mail": {**RAW["mail"], "secure": "tls", "port": 587}, "backup": {"dir": "/tmp/pwg-bk-test"}}
    cfg = config.load(raw)
    notify.send(cfg.mail, build(cfg), smtp_ssl=FakeSmtp, smtp=FakePlain)

    (conn,) = FakePlain.instances
    assert FakeSmtp.instances == []
    assert conn.args == ("smtp.example.test", 587)
    assert conn.timeout == notify.SMTP_TIMEOUT_SECONDS
    assert conn.calls[0] == "starttls"
    assert conn.calls[1][0] == "login"
