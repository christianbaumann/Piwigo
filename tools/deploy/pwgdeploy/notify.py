"""The mail a failed `pwg-backup` run sends. `failure_mail()` is pure; `send()` is the adapter."""

from __future__ import annotations

import smtplib
from email.message import EmailMessage
from urllib.parse import urlsplit

from pwgdeploy.config import DeployConfig, MailConfig

SMTP_TIMEOUT_SECONDS = 30
LOG_TAIL_LINES = 10
REDACTED = "[redacted]"


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
    for secret in secrets:
        body = body.replace(secret, REDACTED)
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
