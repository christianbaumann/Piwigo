"""Snapshot planning (task 03 fills this module; the remote file row is defined here)."""

from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True)
class RemoteFile:
    path: str
    size: int
    mtime: int
