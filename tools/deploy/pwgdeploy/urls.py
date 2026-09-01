"""Joining a remote root to a path, and a base URL to a path.

Pure, and separate from both adapters: a path-joining bug that only shows up over FTPS is
the most expensive kind to find. `config` has already normalised both inputs — a remote
root is `""` or `/piwigo`, a base URL carries no trailing slash — so these two functions
only have to avoid doubling a separator.
"""

from __future__ import annotations


def remote_path(remote_root: str, rel_path: str) -> str:
    """The absolute-or-login-relative path a repository path lands on.

    An empty root means "wherever the FTP login lands", which is what a web space whose
    document root *is* the login directory needs.
    """
    root = remote_root.rstrip("/")
    rel = rel_path.strip("/")
    if not rel:
        return root or "/"
    return f"{root}/{rel}" if root else rel


def site_url(base_url: str, path: str) -> str:
    """https://gallery.example.net + install.php -> https://gallery.example.net/install.php"""
    return f"{base_url.rstrip('/')}/{path.lstrip('/')}"
