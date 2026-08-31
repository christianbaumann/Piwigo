"""The remote-HTTP port, and the one adapter that speaks to a real gallery.

Two operations, no more. The adapter holds no decisions — it turns a call into a
`urllib` request and the answer into a `Response` — so everything that reads a body and
decides what it means lives in `bootstrap`, where a fake client can drive it.

The one thing that is not a translation is the cookie jar: `pwg.session.login` sets the
session cookie that the `admin.php?page=site_update` form POST needs, exactly as
`tools/remote_sync.pl` relies on. One client keeps one jar for its whole run.
"""

from __future__ import annotations

import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from http.cookiejar import CookieJar
from typing import Mapping, Protocol

from pwgdeploy.errors import RemoteHttpError

HTTP_TIMEOUT_SECONDS = 60
# site_update scans every file under galleries/ and reads its metadata; a request budget
# measured in seconds would abort a scan that was working.
SYNC_TIMEOUT_SECONDS = 600

FORM_CONTENT_TYPE = "application/x-www-form-urlencoded"


@dataclass(frozen=True)
class Response:
    url: str
    """The URL that answered — not necessarily the one asked, once a redirect ran."""

    status: int
    body: str


class HttpClient(Protocol):
    """What the bootstrap needs from a gallery."""

    def get(self, url: str, *, timeout: int = HTTP_TIMEOUT_SECONDS) -> Response: ...

    def post(
        self,
        url: str,
        fields: Mapping[str, str],
        *,
        timeout: int = HTTP_TIMEOUT_SECONDS,
    ) -> Response: ...


def build_opener() -> urllib.request.OpenerDirector:
    """An opener with its own cookie jar, so one deploy is one session."""
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(CookieJar())
    )


class UrllibClient:
    """`urllib.request` behind the port, with the session cookie carried across calls."""

    def __init__(self, *, opener_factory=build_opener):
        self._opener = opener_factory()

    def get(self, url: str, *, timeout: int = HTTP_TIMEOUT_SECONDS) -> Response:
        return self._request(url, None, timeout)

    def post(
        self,
        url: str,
        fields: Mapping[str, str],
        *,
        timeout: int = HTTP_TIMEOUT_SECONDS,
    ) -> Response:
        body = urllib.parse.urlencode(dict(fields)).encode("utf-8")
        return self._request(url, body, timeout)

    def _request(self, url: str, data: bytes | None, timeout: int) -> Response:
        request = urllib.request.Request(url, data=data)
        if data is not None:
            request.add_header("Content-Type", FORM_CONTENT_TYPE)
        try:
            with self._opener.open(request, timeout=timeout) as handle:
                # A server's own error page is often in the host's encoding rather than
                # UTF-8. Reporting it garbled beats not reporting it at all.
                body = handle.read().decode("utf-8", "replace")
                return Response(url=handle.geturl(), status=handle.status, body=body)
        except urllib.error.HTTPError as error:
            raise RemoteHttpError(f"{url} returned HTTP {error.code}") from error
        except urllib.error.URLError as error:
            raise RemoteHttpError(f"{url} could not be reached: {error.reason}") from error
