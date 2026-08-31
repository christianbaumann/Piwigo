"""Joining remote paths and site URLs.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA].
State transition and decision table not applicable: two pure functions, no state, and one
condition each (is the root empty).
"""

import pytest

from pwgdeploy.urls import remote_path, site_url

# The two shapes config.py normalises a remote root into, plus the un-normalised forms a
# hand-edited call might still pass.
ABSOLUTE_ROOT = "/piwigo"
EMPTY_ROOT = ""


@pytest.mark.parametrize(
    "root,rel,expected",
    [
        (ABSOLUTE_ROOT, "index.php", "/piwigo/index.php"),
        (ABSOLUTE_ROOT, "plugins/persons/main.inc.php", "/piwigo/plugins/persons/main.inc.php"),
        (EMPTY_ROOT, "index.php", "index.php"),
        (EMPTY_ROOT, "plugins/persons/main.inc.php", "plugins/persons/main.inc.php"),
        ("/", "index.php", "index.php"),
    ],
)
def test_remote_path_joins_with_forward_slashes(root, rel, expected):
    """A root of "" and a root of "/" both mean "where the login lands". [ECP]"""
    assert remote_path(root, rel) == expected


@pytest.mark.parametrize(
    "root,rel",
    [
        ("/piwigo/", "index.php"),
        ("/piwigo", "/index.php"),
        ("/piwigo/", "/index.php"),
    ],
)
def test_remote_path_never_doubles_a_slash(root, rel):
    """[BVA]"""
    assert remote_path(root, rel) == "/piwigo/index.php"


def test_remote_path_of_an_empty_relative_path_is_the_root_itself():
    """makedirs of a top-level file's parent asks for exactly this. [BVA]"""
    assert remote_path(ABSOLUTE_ROOT, "") == ABSOLUTE_ROOT
    assert remote_path(EMPTY_ROOT, "") == "/"


def test_remote_path_uses_forward_slashes_whatever_the_host_os_is():
    """Anti-vacuity for the whole module: no os.path.join may creep in. [NEG]"""
    joined = remote_path(ABSOLUTE_ROOT, "themes/modus/theme.css")

    assert "\\" not in joined
    assert joined.count("/") == 4  # root + three path segments


def test_site_url_joins_base_and_path():
    """[HAPPY]"""
    assert site_url("https://g.example.net", "install.php") == (
        "https://g.example.net/install.php"
    )


@pytest.mark.parametrize(
    "base,path",
    [
        ("https://g.example.net/", "install.php"),
        ("https://g.example.net", "/install.php"),
        ("https://g.example.net/", "/install.php"),
    ],
)
def test_site_url_never_doubles_a_slash(base, path):
    """[BVA]"""
    assert site_url(base, path) == "https://g.example.net/install.php"


def test_site_url_keeps_the_scheme_separator():
    """`https://` is two slashes that must survive every strip. [NEG]"""
    assert site_url("https://g.example.net", "ws.php").startswith("https://")
