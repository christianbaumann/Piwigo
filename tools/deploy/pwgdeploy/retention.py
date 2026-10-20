"""Retention of the remote backup: which snapshot folders to delete.

Kept: every snapshot younger than its kind's window, the newest of each ISO week up to
WEEKLY_DAYS old, the newest of each calendar month beyond that, and always the newest snapshot.
Thinning looks at every snapshot older than DAILY_DAYS, whatever its kind, so a pre-deploy
snapshot past its 90 days is thinned like the rest. Folder names are parsed by `snapshot.parse_name`.
"""

from __future__ import annotations

import shutil
from datetime import datetime, timedelta
from pathlib import Path

from pwgdeploy.snapshot import parse_name

DAILY_DAYS = 14
PREDEPLOY_DAYS = 90
WEEKLY_DAYS = 183
WINDOW_DAYS = {
    "daily": DAILY_DAYS,
    "manual": DAILY_DAYS,
    "predeploy": PREDEPLOY_DAYS,
    "prerestore": PREDEPLOY_DAYS,
}


def _bucket(when: datetime, age: timedelta) -> tuple:
    if age <= timedelta(days=WEEKLY_DAYS):
        iso = when.isocalendar()
        return ("week", iso[0], iso[1])
    return ("month", when.year, when.month)


def select_for_deletion(names: list[str], now: datetime) -> list[str]:
    """Names to delete, sorted. Names `parse_name` rejects are ignored, never selected."""
    parsed = [(name, *info) for name in names if (info := parse_name(name))]
    if not parsed:
        return []
    keep = {max(parsed, key=lambda item: (item[1], item[0]))[0]}
    newest_in_bucket: dict[tuple, tuple[datetime, str]] = {}
    for name, when, kind in parsed:
        age = now - when
        if age <= timedelta(days=WINDOW_DAYS[kind]):
            keep.add(name)
        if age > timedelta(days=DAILY_DAYS):
            bucket = _bucket(when, age)
            if bucket not in newest_in_bucket or (when, name) > newest_in_bucket[bucket]:
                newest_in_bucket[bucket] = (when, name)
    keep.update(name for _, name in newest_in_bucket.values())
    return sorted(name for name, _, _ in parsed if name not in keep)


def prune(root: Path, now: datetime) -> list[str]:
    """Delete the snapshot folders `select_for_deletion` picks; touch nothing else in `root`."""
    root = Path(root)
    if not root.is_dir():
        return []
    names = [child.name for child in root.iterdir() if child.is_dir() and parse_name(child.name)]
    deleted = select_for_deletion(names, now)
    for name in deleted:
        shutil.rmtree(root / name)
    return deleted
