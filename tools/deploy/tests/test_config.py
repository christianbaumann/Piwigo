"""Config loading and validation.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA].
Decision table not applicable: each rule is one condition with one outcome.
"""

import json
from pathlib import Path

import pytest

from pwgdeploy import config
from pwgdeploy.errors import ConfigError

EXAMPLE_FILE = Path(__file__).resolve().parents[1] / "deploy.example.json"


def valid() -> dict:
    """The committed example, re-read per test so a mutation cannot leak between tests."""
    return json.loads(EXAMPLE_FILE.read_text(encoding="utf-8"))


# --- happy path ------------------------------------------------------------


def test_loads_the_example_file():
    """[HAPPY] Structural guard: the committed example cannot drift from the loader."""
    assert EXAMPLE_FILE.stat().st_size > 100, "the example file is suspiciously small"
    cfg = config.load_file(EXAMPLE_FILE)
    assert cfg.ftp.host == "ftp.example.net"
    assert cfg.mysql.prefix == "piwigo_"
    assert cfg.admin.username == "webmaster"
    assert cfg.site.base_url == "https://gallery.example.net"
    assert cfg.ftp.remote_root == "/piwigo"


def test_defaults_are_applied():
    """[HAPPY] Every optional key omitted yields the documented default."""
    raw = valid()
    del raw["ftp"]["port"]
    del raw["ftp"]["remote_root"]
    del raw["mysql"]["prefix"]
    del raw["site"]["language"]
    del raw["site"]["assume_https"]
    del raw["site"]["exiftool_path"]

    cfg = config.load(raw)

    assert cfg.ftp.port == config.DEFAULT_PORT
    assert cfg.ftp.remote_root == ""
    assert cfg.mysql.prefix == config.DEFAULT_PREFIX
    assert cfg.site.language == config.DEFAULT_LANGUAGE
    assert cfg.site.assume_https is config.DEFAULT_ASSUME_HTTPS
    assert cfg.site.exiftool_path == config.DEFAULT_EXIFTOOL_PATH


# --- negative --------------------------------------------------------------


def test_missing_section_names_the_section():
    """[NEG]"""
    raw = valid()
    del raw["mysql"]
    with pytest.raises(ConfigError, match="mysql"):
        config.load(raw)


def test_unknown_top_level_key_is_rejected():
    """[NEG] A typo'd section fails rather than being silently ignored."""
    raw = valid()
    raw["mysqlx"] = {}
    with pytest.raises(ConfigError, match="mysqlx"):
        config.load(raw)


def test_unknown_key_inside_a_section_is_rejected():
    """[NEG]"""
    raw = valid()
    raw["ftp"]["hostname"] = "typo"
    with pytest.raises(ConfigError, match="hostname"):
        config.load(raw)


@pytest.mark.parametrize(
    ("section", "key"),
    [
        ("ftp", "password"),
        ("mysql", "password"),
        ("admin", "password"),
    ],
)
def test_empty_password_is_rejected(section, key):
    """[NEG] [ECP]"""
    raw = valid()
    raw[section][key] = ""
    with pytest.raises(ConfigError, match=f"{section}.{key}"):
        config.load(raw)


def test_missing_required_string_names_the_key():
    """[NEG]"""
    raw = valid()
    del raw["ftp"]["host"]
    with pytest.raises(ConfigError, match="host"):
        config.load(raw)


def test_admin_username_with_a_quote_is_rejected():
    """[NEG] Mirrors install.php:283."""
    raw = valid()
    raw["admin"]["username"] = "web'master"
    with pytest.raises(ConfigError, match="admin.username"):
        config.load(raw)


def test_admin_username_with_a_double_quote_is_rejected():
    """[NEG] Mirrors install.php:283 — the second forbidden character."""
    raw = valid()
    raw["admin"]["username"] = 'web"master'
    with pytest.raises(ConfigError, match="admin.username"):
        config.load(raw)


@pytest.mark.parametrize("bad", ["nobody", "no@host", "no@host.", "@example.net", "a b@c.de"])
def test_malformed_admin_email_is_rejected(bad):
    """[NEG] [ECP]"""
    raw = valid()
    raw["admin"]["email"] = bad
    with pytest.raises(ConfigError, match="admin.email"):
        config.load(raw)


def test_relative_base_url_is_rejected():
    """[NEG]"""
    raw = valid()
    raw["site"]["base_url"] = "gallery.example.net"
    with pytest.raises(ConfigError, match="base_url"):
        config.load(raw)


def test_remote_root_with_dotdot_is_rejected():
    """[NEG]"""
    raw = valid()
    raw["ftp"]["remote_root"] = "/piwigo/../etc"
    with pytest.raises(ConfigError, match=r"\.\."):
        config.load(raw)


def test_assume_https_must_be_a_boolean():
    """[NEG] A JSON string "true" is not a boolean and must not pass for one."""
    raw = valid()
    raw["site"]["assume_https"] = "true"
    with pytest.raises(ConfigError, match="assume_https"):
        config.load(raw)


def test_a_non_object_top_level_is_rejected():
    """[NEG]"""
    with pytest.raises(ConfigError, match="JSON object"):
        config.load([])  # type: ignore[arg-type]


def test_load_file_of_broken_json_names_the_file(tmp_path):
    """[NEG]"""
    path = tmp_path / "deploy.local.json"
    path.write_text("{not json", encoding="utf-8")
    with pytest.raises(ConfigError, match="deploy.local.json"):
        config.load_file(path)


def test_load_file_of_a_missing_file_names_the_file(tmp_path):
    """[NEG]"""
    with pytest.raises(ConfigError, match="nope.json"):
        config.load_file(tmp_path / "nope.json")


# --- boundaries ------------------------------------------------------------


@pytest.mark.parametrize(
    ("length", "accepted"),
    [(0, False), (1, True), (config.PREFIX_MAX_LENGTH, True), (config.PREFIX_MAX_LENGTH + 1, False)],
)
def test_prefix_length(length, accepted):
    """[BVA] install.php:270 caps the prefix at 20; an empty prefix is rejected here."""
    raw = valid()
    raw["mysql"]["prefix"] = "p" * length
    if accepted:
        assert config.load(raw).mysql.prefix == "p" * length
    else:
        with pytest.raises(ConfigError, match="prefix"):
            config.load(raw)


def test_prefix_leading_digit_is_rejected():
    """[NEG] install.php:271."""
    raw = valid()
    raw["mysql"]["prefix"] = "9foo"
    with pytest.raises(ConfigError, match="digit"):
        config.load(raw)


@pytest.mark.parametrize("prefix", ["pwg_", "pwg$", "PWG9_$"])
def test_prefix_underscore_and_dollar_accepted(prefix):
    """[ECP] install.php:272 allows [a-zA-Z0-9_$]."""
    raw = valid()
    raw["mysql"]["prefix"] = prefix
    assert config.load(raw).mysql.prefix == prefix


@pytest.mark.parametrize("prefix", ["pwg-", "pwg tables", "pwg.x"])
def test_prefix_with_a_forbidden_character_is_rejected(prefix):
    """[ECP]"""
    raw = valid()
    raw["mysql"]["prefix"] = prefix
    with pytest.raises(ConfigError, match="prefix"):
        config.load(raw)


@pytest.mark.parametrize(
    ("port", "accepted"),
    [(0, False), (1, True), (65535, True), (65536, False)],
)
def test_port_bounds(port, accepted):
    """[BVA]"""
    raw = valid()
    raw["ftp"]["port"] = port
    if accepted:
        assert config.load(raw).ftp.port == port
    else:
        with pytest.raises(ConfigError, match="port"):
            config.load(raw)


def test_port_must_not_be_a_string():
    """[NEG] "21" would work by accident in ftplib and fail nowhere useful."""
    raw = valid()
    raw["ftp"]["port"] = "21"
    with pytest.raises(ConfigError, match="port"):
        config.load(raw)


def test_base_url_trailing_slash_is_stripped():
    """[ECP] Every later join assumes no trailing slash."""
    raw = valid()
    raw["site"]["base_url"] = "https://gallery.example.net/"
    assert config.load(raw).site.base_url == "https://gallery.example.net"


def test_base_url_keeps_a_subdirectory():
    """[ECP] The gallery may live under a path, not only at a host root."""
    raw = valid()
    raw["site"]["base_url"] = "https://example.net/piwigo/"
    assert config.load(raw).site.base_url == "https://example.net/piwigo"


@pytest.mark.parametrize(
    ("given", "expected"),
    [("", ""), ("/", ""), ("piwigo", "/piwigo"), ("/piwigo/", "/piwigo"),
     ("/a/b", "/a/b"), ("//a//b//", "/a/b"), ("./piwigo", "/piwigo")],
)
def test_remote_root_is_normalised(given, expected):
    """[ECP] One normal form: leading slash, no trailing slash, empty means the FTP home."""
    raw = valid()
    raw["ftp"]["remote_root"] = given
    assert config.load(raw).ftp.remote_root == expected
