"""The credential file: parse, validate, normalise.

Validation happens here and nowhere else. Every rule mirrors one the remote itself
enforces, so a bad value fails locally in milliseconds instead of after a 138 MB upload.
"""

from __future__ import annotations

import json
import re
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Mapping

from pwgdeploy.errors import ConfigError

# install.php:270-273
PREFIX_MAX_LENGTH = 20
PREFIX_ALLOWED = re.compile(r"^[a-zA-Z0-9_$]*$")
PREFIX_LEADING_DIGIT = re.compile(r"^\d")

# install.php:283 — webmaster login can't contain ' or "
ADMIN_NAME_FORBIDDEN = re.compile(r"[\'\"]")

# A local mirror of validate_mail_address(); the server remains the authority.
MAIL_SHAPE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")

PORT_MIN = 1
PORT_MAX = 65535

DEFAULT_PORT = 21
DEFAULT_REMOTE_ROOT = "/"
DEFAULT_PREFIX = "piwigo_"
DEFAULT_LANGUAGE = "de_DE"
DEFAULT_ASSUME_HTTPS = True
DEFAULT_EXIFTOOL_PATH = ""
DEFAULT_MAIL_SENDER_NAME = ""

# functions_mail.inc.php hands PHPMailer these two and silently drops anything else,
# leaving only opportunistic STARTTLS between the SMTP password and the wire.
MAIL_SECURE_VALUES = ("ssl", "tls")

# Core splits smtp_host on ':' for the port, PHPMailer splits it on ';' into a host list.
MAIL_HOST_FORBIDDEN = re.compile(r"[:/;\s]")

SECTIONS = ("ftp", "mysql", "admin", "site")
OPTIONAL_SECTIONS = ("mail",)


@dataclass(frozen=True)
class FtpConfig:
    host: str
    user: str
    password: str
    port: int = DEFAULT_PORT
    remote_root: str = ""


@dataclass(frozen=True)
class MysqlConfig:
    host: str
    user: str
    password: str
    database: str
    prefix: str = DEFAULT_PREFIX


@dataclass(frozen=True)
class AdminConfig:
    username: str
    password: str
    email: str


@dataclass(frozen=True)
class SiteConfig:
    base_url: str
    language: str = DEFAULT_LANGUAGE
    assume_https: bool = DEFAULT_ASSUME_HTTPS
    exiftool_path: str = DEFAULT_EXIFTOOL_PATH


@dataclass(frozen=True)
class MailConfig:
    host: str
    port: int
    secure: str
    user: str
    password: str
    sender_email: str
    sender_name: str = DEFAULT_MAIL_SENDER_NAME


@dataclass(frozen=True)
class DeployConfig:
    ftp: FtpConfig
    mysql: MysqlConfig
    admin: AdminConfig
    site: SiteConfig
    mail: MailConfig | None = None


def load(raw: Mapping[str, Any]) -> DeployConfig:
    """Validate and normalise a parsed credential mapping."""
    if not isinstance(raw, Mapping):
        raise ConfigError("the credential file must hold a JSON object at the top level")

    _reject_unknown_keys(raw, SECTIONS + OPTIONAL_SECTIONS, "top level")
    for name in SECTIONS:
        if name not in raw:
            raise ConfigError(f"missing section '{name}' in the credential file")
    for name in SECTIONS + OPTIONAL_SECTIONS:
        if name in raw and not isinstance(raw[name], Mapping):
            raise ConfigError(f"section '{name}' must be a JSON object")

    return DeployConfig(
        ftp=_ftp(raw["ftp"]),
        mysql=_mysql(raw["mysql"]),
        admin=_admin(raw["admin"]),
        site=_site(raw["site"]),
        mail=_mail(raw["mail"]) if "mail" in raw else None,
    )


def load_file(path: Path) -> DeployConfig:
    try:
        text = Path(path).read_text(encoding="utf-8")
    except OSError as exc:
        raise ConfigError(f"cannot read the credential file {path}: {exc}") from exc
    try:
        raw = json.loads(text)
    except json.JSONDecodeError as exc:
        raise ConfigError(f"{path} is not valid JSON: {exc}") from exc
    return load(raw)


def _ftp(raw: Mapping[str, Any]) -> FtpConfig:
    _reject_unknown_keys(raw, ("host", "user", "password", "port", "remote_root"), "ftp")
    return FtpConfig(
        host=_required_string(raw, "host", "ftp"),
        user=_required_string(raw, "user", "ftp"),
        password=_required_string(raw, "password", "ftp"),
        port=_port(raw.get("port", DEFAULT_PORT), "ftp.port"),
        remote_root=_remote_root(raw.get("remote_root", DEFAULT_REMOTE_ROOT)),
    )


def _mysql(raw: Mapping[str, Any]) -> MysqlConfig:
    _reject_unknown_keys(raw, ("host", "user", "password", "database", "prefix"), "mysql")
    return MysqlConfig(
        host=_required_string(raw, "host", "mysql"),
        user=_required_string(raw, "user", "mysql"),
        password=_required_string(raw, "password", "mysql"),
        database=_required_string(raw, "database", "mysql"),
        prefix=_prefix(raw.get("prefix", DEFAULT_PREFIX)),
    )


def _admin(raw: Mapping[str, Any]) -> AdminConfig:
    _reject_unknown_keys(raw, ("username", "password", "email"), "admin")
    username = _required_string(raw, "username", "admin")
    if ADMIN_NAME_FORBIDDEN.search(username):
        raise ConfigError(
            "admin.username must not contain ' or \" — install.php rejects such a webmaster login"
        )
    email = _required_string(raw, "email", "admin")
    if not MAIL_SHAPE.match(email):
        raise ConfigError(
            f"admin.email {email!r} is not shaped like a mail address (xxx@yyy.eee)"
        )
    return AdminConfig(
        username=username,
        password=_required_string(raw, "password", "admin"),
        email=email,
    )


def _site(raw: Mapping[str, Any]) -> SiteConfig:
    _reject_unknown_keys(
        raw, ("base_url", "language", "assume_https", "exiftool_path"), "site"
    )
    assume_https = raw.get("assume_https", DEFAULT_ASSUME_HTTPS)
    if not isinstance(assume_https, bool):
        raise ConfigError("site.assume_https must be true or false")
    exiftool_path = raw.get("exiftool_path", DEFAULT_EXIFTOOL_PATH)
    if not isinstance(exiftool_path, str):
        raise ConfigError("site.exiftool_path must be a string (empty means: not available)")
    return SiteConfig(
        base_url=_base_url(_required_string(raw, "base_url", "site")),
        language=_required_string(raw, "language", "site")
        if "language" in raw
        else DEFAULT_LANGUAGE,
        assume_https=assume_https,
        exiftool_path=exiftool_path,
    )


def _mail(raw: Mapping[str, Any]) -> MailConfig:
    _reject_unknown_keys(
        raw,
        ("host", "port", "secure", "user", "password", "sender_email", "sender_name"),
        "mail",
    )
    secure = raw.get("secure")
    if secure not in MAIL_SECURE_VALUES:
        raise ConfigError(
            f"mail.secure must be one of {', '.join(MAIL_SECURE_VALUES)}, got {secure!r}"
        )
    sender_email = _required_string(raw, "sender_email", "mail")
    if not MAIL_SHAPE.match(sender_email):
        raise ConfigError(
            f"mail.sender_email {sender_email!r} is not shaped like a mail address (xxx@yyy.eee)"
        )
    sender_name = raw.get("sender_name", DEFAULT_MAIL_SENDER_NAME)
    if not isinstance(sender_name, str):
        raise ConfigError("mail.sender_name must be a string (empty means: the gallery title)")
    if "port" not in raw:
        raise ConfigError("missing 'port' in section 'mail'")
    host = _required_string(raw, "host", "mail")
    if MAIL_HOST_FORBIDDEN.search(host):
        raise ConfigError(
            f"mail.host must be a bare host name without port, scheme or list, got {host!r}"
        )
    return MailConfig(
        host=host,
        port=_port(raw["port"], "mail.port"),
        secure=secure,
        user=_required_string(raw, "user", "mail"),
        password=_required_string(raw, "password", "mail"),
        sender_email=sender_email,
        sender_name=sender_name,
    )


def _reject_unknown_keys(
    raw: Mapping[str, Any], allowed: tuple[str, ...], where: str
) -> None:
    unknown = sorted(set(raw) - set(allowed))
    if unknown:
        raise ConfigError(
            f"unknown key(s) {', '.join(unknown)} at {where}; expected one of {', '.join(allowed)}"
        )


def _required_string(raw: Mapping[str, Any], key: str, section: str) -> str:
    value = raw.get(key)
    if value is None:
        raise ConfigError(f"missing '{key}' in section '{section}'")
    if not isinstance(value, str):
        raise ConfigError(f"{section}.{key} must be a string")
    if not value.strip():
        raise ConfigError(f"{section}.{key} must not be empty")
    return value


def _port(value: Any, field: str) -> int:
    if isinstance(value, bool) or not isinstance(value, int):
        raise ConfigError(f"{field} must be an integer")
    if not PORT_MIN <= value <= PORT_MAX:
        raise ConfigError(f"{field} must be between {PORT_MIN} and {PORT_MAX}, got {value}")
    return value


def _prefix(value: Any) -> str:
    if not isinstance(value, str):
        raise ConfigError("mysql.prefix must be a string")
    if not value:
        raise ConfigError("mysql.prefix must not be empty")
    if len(value) > PREFIX_MAX_LENGTH:
        raise ConfigError(
            f"mysql.prefix must be at most {PREFIX_MAX_LENGTH} characters, got {len(value)}"
        )
    if PREFIX_LEADING_DIGIT.match(value):
        raise ConfigError("mysql.prefix must not start with a digit")
    if not PREFIX_ALLOWED.match(value):
        raise ConfigError("mysql.prefix may only contain letters, digits, '_' and '$'")
    return value


def _base_url(value: str) -> str:
    if not value.startswith(("http://", "https://")):
        raise ConfigError(f"site.base_url must be an absolute http(s) URL, got {value!r}")
    stripped = value.rstrip("/")
    if stripped in ("http:/", "https:/", "http:", "https:"):
        raise ConfigError(f"site.base_url is missing a host: {value!r}")
    return stripped


def _remote_root(value: Any) -> str:
    """"", "/", "piwigo", "/piwigo/" -> "" or "/piwigo"."""
    if not isinstance(value, str):
        raise ConfigError("ftp.remote_root must be a string")
    segments = [s for s in value.split("/") if s not in ("", ".")]
    if ".." in segments:
        raise ConfigError("ftp.remote_root must not contain '..' segments")
    return "/" + "/".join(segments) if segments else ""
