"""The HTTP adapter: what goes on the wire, and what comes back as a Response.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA] [ST].
Decision table not applicable: one request either succeeds or raises, there is no
condition matrix. What these tests cannot witness is a real socket — the cookie jar is
observed through the opener the adapter was handed, so "the session carries over" is
proven as a wiring fact rather than assumed.
"""

import urllib.error
import urllib.request
from http.cookiejar import CookieJar

import pytest

from pwgdeploy import http
from pwgdeploy.errors import RemoteHttpError

URL = "https://g.example.test/ws.php?format=json"

# Anti-vacuity: a body assertion over an empty body would pass on almost anything.
MIN_BODY_BYTES = 4


class FakeHandle:
    """What `opener.open()` yields: a context manager over the recorded bytes."""

    def __init__(self, body: bytes, status: int, url: str):
        self._body = body
        self.status = status
        self._url = url

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def read(self):
        return self._body

    def geturl(self):
        return self._url


class FakeOpener:
    """Records every request instead of sending it."""

    def __init__(self, body=b'{"stat":"ok"}', status=200, error=None, final_url=None):
        self.body = body
        self.status = status
        self.error = error
        self.final_url = final_url
        self.requests = []
        self.timeouts = []

    def open(self, request, timeout=None):
        self.requests.append(request)
        self.timeouts.append(timeout)
        if self.error is not None:
            raise self.error
        return FakeHandle(self.body, self.status, self.final_url or request.full_url)


def client(opener):
    return http.UrllibClient(opener_factory=lambda: opener)


def test_get_issues_a_get_and_returns_the_decoded_body():
    """[HAPPY] The simplest round trip: no data, body decoded as text."""
    opener = FakeOpener(body=b"Piwigo is already installed")
    assert len(opener.body) > MIN_BODY_BYTES

    response = client(opener).get(URL)

    assert opener.requests[0].get_method() == "GET"
    assert opener.requests[0].data is None
    assert response.status == 200
    assert response.body == "Piwigo is already installed"


def test_post_form_encodes_the_fields_as_a_request_body():
    """[HAPPY] The fields reach the server as an urlencoded body, not as a query string."""
    opener = FakeOpener()

    client(opener).post(URL, {"method": "pwg.session.login", "username": "web master"})

    request = opener.requests[0]
    assert request.get_method() == "POST"
    assert request.full_url == URL
    assert request.data == b"method=pwg.session.login&username=web+master"


def test_post_declares_the_form_content_type():
    """[ECP] Without this header PHP populates none of $_POST."""
    opener = FakeOpener()

    client(opener).post(URL, {"install": "1"})

    assert (
        opener.requests[0].get_header("Content-type")
        == "application/x-www-form-urlencoded"
    )


def test_a_redirect_is_reported_as_the_url_that_answered():
    """[ST] admin.php bounces an unauthenticated POST to identification.php; the caller
    can only notice that if the answering URL, not the requested one, comes back."""
    opener = FakeOpener(final_url="https://g.example.test/identification.php")

    response = client(opener).get("https://g.example.test/admin.php")

    assert response.url == "https://g.example.test/identification.php"


def test_default_timeout_is_applied_and_overridable():
    """[BVA] The sync POST scans the whole gallery and needs the long budget."""
    opener = FakeOpener()

    fetcher = client(opener)
    fetcher.get(URL)
    fetcher.post(URL, {}, timeout=http.SYNC_TIMEOUT_SECONDS)

    assert opener.timeouts == [http.HTTP_TIMEOUT_SECONDS, http.SYNC_TIMEOUT_SECONDS]
    assert http.SYNC_TIMEOUT_SECONDS > http.HTTP_TIMEOUT_SECONDS


def test_the_sync_budget_is_minutes_not_seconds():
    """[BVA] A site_update over 100+ photos reading metadata takes minutes; a one-minute
    ceiling would abort a scan that was working."""
    assert http.SYNC_TIMEOUT_SECONDS >= 600


def test_an_http_error_status_becomes_a_remote_http_error():
    """[NEG] A 500 from the host must name the URL and the status, not surface a
    urllib traceback the exit-code mapping cannot classify."""
    opener = FakeOpener(
        error=urllib.error.HTTPError(URL, 500, "Internal Server Error", {}, None)
    )

    with pytest.raises(RemoteHttpError) as raised:
        client(opener).get(URL)

    assert "500" in str(raised.value)
    assert URL in str(raised.value)


def test_a_connection_failure_becomes_a_remote_http_error():
    """[NEG] An unresolvable host arrives as URLError, and must classify the same way."""
    opener = FakeOpener(error=urllib.error.URLError("nodename nor servname provided"))

    with pytest.raises(RemoteHttpError) as raised:
        client(opener).get(URL)

    assert "nodename" in str(raised.value)


def test_a_body_that_is_not_utf8_is_replaced_rather_than_raising():
    """[NEG] install.php can emit a MySQL error message in the server's own encoding.
    Reporting it garbled beats failing to report it at all."""
    opener = FakeOpener(body=b"Zugriff \xff verweigert")

    response = client(opener).get(URL)

    assert "Zugriff" in response.body


def test_the_real_opener_carries_a_cookie_jar():
    """[ST] The ws.php login sets the session cookie that the admin.php form POST needs.
    Structural guard: nothing else in the suite can witness this, and without it the sync
    would silently be performed as a guest."""
    opener = http.build_opener()

    processors = [
        handler
        for handler in opener.handlers
        if isinstance(handler, urllib.request.HTTPCookieProcessor)
    ]

    assert len(processors) == 1
    assert isinstance(processors[0].cookiejar, CookieJar)


def test_one_client_reuses_one_opener_across_requests():
    """[ST] A fresh opener per call would drop the session cookie between login and sync."""
    opener = FakeOpener()

    fetcher = client(opener)
    fetcher.post(URL, {"method": "pwg.session.login"})
    fetcher.get("https://g.example.test/admin.php")

    assert len(opener.requests) == 2
