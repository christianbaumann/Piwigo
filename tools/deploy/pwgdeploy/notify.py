"""The mail a failed `pwg-backup` run sends. `failure_mail()` is pure; `send()` is the adapter."""

from __future__ import annotations

import smtplib
from email.message import EmailMessage
import json
import re
from urllib.parse import quote, quote_plus, urlsplit

from pwgdeploy.config import DeployConfig, MailConfig

SMTP_TIMEOUT_SECONDS = 30
LOG_TAIL_LINES = 10
REDACTED = "[redacted]"
MIN_SUBSTRING_SECRET_LENGTH = 4


def forms_of(secret: str) -> set[str]:
    """The secret as it appears raw, URL-encoded, JSON-escaped or repr'd."""
    return {
        secret, quote(secret, safe=""), quote_plus(secret),
        json.dumps(secret)[1:-1], repr(secret)[1:-1],
    }


def redact(text: str, secrets: tuple[str, ...]) -> str:
    """Longest forms first. A secret too short to replace inside words goes only as a whole word."""
    for secret in sorted((s for s in secrets if s), key=len, reverse=True):
        for form in sorted(forms_of(secret), key=len, reverse=True):
            if len(secret) < MIN_SUBSTRING_SECRET_LENGTH:
                text = re.sub(rf"(?<!\w){re.escape(form)}(?!\w)", REDACTED, text)
            else:
                text = text.replace(form, REDACTED)
    return text


def secrets_of(config: DeployConfig) -> tuple[str, ...]:
    values = [config.ftp.password, config.mysql.password, config.admin.password]
    if config.mail is not None:
        values.append(config.mail.password)
    return tuple(v for v in values if v)


def recipient_of(config: DeployConfig) -> str:
    notify = config.backup.notify if config.backup is not None else None
    return notify or config.admin.email


def failure_mail(
    mail: MailConfig, recipient: str, site_url: str, kind: str, step: str, error: str,
    log_tail: list[str], secrets: tuple[str, ...] = (),
) -> EmailMessage:
    gallery = urlsplit(site_url).hostname or site_url
    body = (
        f"pwg-backup ({kind}) for {gallery} failed.\n\n"
        f"Step: {step}\nError: {error}\n\n"
        "Last log lines:\n" + "\n".join(log_tail[-LOG_TAIL_LINES:]) + "\n"
    )
    body = redact(body, secrets)
    message = EmailMessage()
    message["Subject"] = f"pwg-backup failed: {gallery} ({step})"
    message["From"] = f"{mail.sender_name} <{mail.sender_email}>" if mail.sender_name else mail.sender_email
    message["To"] = recipient
    message.set_content(body)
    return message


def send(mail: MailConfig, message: EmailMessage, smtp_ssl=smtplib.SMTP_SSL, smtp=smtplib.SMTP) -> None:
    """`ssl` connects encrypted from the start, `tls` upgrades with STARTTLS before logging in."""
    if mail.secure == "ssl":
        connection = smtp_ssl(mail.host, mail.port, timeout=SMTP_TIMEOUT_SECONDS)
    else:
        connection = smtp(mail.host, mail.port, timeout=SMTP_TIMEOUT_SECONDS)
    with connection:
        if mail.secure == "tls":
            connection.starttls()
        connection.login(mail.user, mail.password)
        connection.send_message(message)
