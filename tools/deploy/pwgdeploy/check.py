"""Restore check of the remote backup: does the newest snapshot's dump import, and do its files match?

`run_check()` imports the snapshot's gzipped dump into the scratch database `backup_check` in DDEV,
counts four tables and compares them with the `counts` the dump answered with, then re-hashes every
file against `snapshot.json`. It refuses any other database name: `db` holds the local gallery.
`check_main()` is what `pwg-backup --check` calls (wired in backup_cli.py).
"""

from __future__ import annotations

import gzip
import json
import re
import subprocess
import sys
from pathlib import Path
from typing import Callable, TextIO

from pwgdeploy import snapshot

CHECK_DATABASE = "backup_check"
DUMP_PATH = "db/dump.sql.gz"
DEFAULT_PREFIX = "piwigo_"
COUNTED_TABLES = ("images", "categories", "tags", "users")
MAX_REPORTED_FILES = 5
EXIT_CHECK_FAILED = 1
FORBIDDEN_LINE = re.compile(rb"\s*(USE\s|(CREATE|DROP)\s+(DATABASE|SCHEMA))", re.IGNORECASE)
PREFIX_PATTERN = re.compile(r"[A-Za-z0-9_]{0,32}")
DDEV_MYSQL = ["ddev", "mysql", "-uroot"]
PROJECT_ROOT = Path(__file__).resolve().parents[3]

# (args, gzipped file streamed into stdin or None, cwd) -> (exit code, stdout, stderr)
Runner = Callable[[list[str], "Path | None", "Path | None"], "tuple[int, str, str]"]


class ForeignDatabase(Exception):
    """The dump addresses a database itself; imported as root it could reach `db`."""


class CheckError(Exception):
    """The check cannot even start (wrong database name, bad prefix)."""


def compare(expected: dict[str, int], actual: dict[str, int]) -> list[str]:
    if not expected:
        return ["the snapshot records no row counts to compare with"]
    problems = []
    for table in sorted(expected):
        if table not in actual:
            problems.append(f"{table}: not counted after the import")
        elif actual[table] != expected[table]:
            problems.append(f"{table}: snapshot has {expected[table]} rows, the import has {actual[table]}")
    return problems


def verify_files(folder: Path, files: dict[str, snapshot.Entry]) -> list[str]:
    """Names of files missing, of another size, or with another sha1; the first MAX_REPORTED_FILES."""
    problems: list[str] = []
    total = 0
    for path in sorted(files):
        entry = files[path]
        target = folder / snapshot.FILES_DIR / path
        if not target.is_file():
            reason = "missing"
        elif target.stat().st_size != entry.size:
            reason = "size differs from snapshot.json"
        elif snapshot._sha1(target) != entry.sha1:
            reason = "sha1 differs from snapshot.json"
        else:
            continue
        total += 1
        if len(problems) < MAX_REPORTED_FILES:
            problems.append(f"{path}: {reason}")
    if total > len(problems):
        problems.append(f"... and {total - len(problems)} more files")
    return problems


def _subprocess_runner(args: list[str], gz_stdin: Path | None, cwd: Path | None) -> tuple[int, str, str]:
    if gz_stdin is None:
        done = subprocess.run(args, cwd=cwd, capture_output=True, text=True)
        return done.returncode, done.stdout, done.stderr
    process = subprocess.Popen(
        args, cwd=cwd, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE
    )
    unreadable = ""
    try:
        with gzip.open(gz_stdin, "rb") as source:
            for line in source:
                if FORBIDDEN_LINE.match(line):
                    raise ForeignDatabase(line[:60].decode(errors="replace").strip())
                process.stdin.write(line)
    except ForeignDatabase as error:
        process.kill()
        process.communicate()
        return 1, "", f"the dump selects or creates a database itself ({error}); not imported"
    except (EOFError, OSError) as error:
        unreadable = f"the dump cannot be read to its end: {error}"
    except BrokenPipeError:
        pass
    finally:
        try:
            process.stdin.close()
        except BrokenPipeError:
            pass
        process.stdin = None
    out, err = process.communicate()
    message = (unreadable + "\n" + err.decode(errors="replace")).strip()
    return (process.returncode or (1 if unreadable else 0)), out.decode(errors="replace"), message


def _sql(runner: Runner, cwd: Path | None, statement: str) -> tuple[int, str, str]:
    return runner([*DDEV_MYSQL, "-N", "-B", "-e", statement], None, cwd)


def _count_statement(database: str, prefix: str) -> str:
    return " UNION ALL ".join(
        f"SELECT '{table}', COUNT(*) FROM {database}.{prefix}{table}" for table in COUNTED_TABLES
    )


def _parse_counts(output: str) -> dict[str, int]:
    counts = {}
    for line in output.splitlines():
        name, _, number = line.partition("\t")
        counts[name] = int(number)
    return counts


def import_and_count(
    dump: Path, prefix: str, runner: Runner, cwd: Path | None, database: str = CHECK_DATABASE
) -> tuple[dict[str, int], list[str]]:
    """Import `dump` into a fresh `database` and count the tables; the database is dropped again."""
    if database != CHECK_DATABASE:
        raise CheckError(f"refusing to use database {database!r}: only {CHECK_DATABASE!r} may be touched")
    if not PREFIX_PATTERN.fullmatch(prefix):
        raise CheckError(f"table prefix {prefix!r} is not a plain identifier")
    try:
        code, _, err = _sql(runner, cwd, f"DROP DATABASE IF EXISTS {database}; CREATE DATABASE {database}")
        if code != 0:
            return {}, [f"cannot create {database}: {err.strip()}"]
        code, _, err = runner([*DDEV_MYSQL, database], dump, cwd)
        if code != 0:
            return {}, [f"import of {dump.name} failed: {err.strip()}"]
        code, out, err = _sql(runner, cwd, _count_statement(database, prefix))
        if code != 0:
            return {}, [f"counting rows failed: {err.strip()}"]
        return _parse_counts(out), []
    finally:
        _sql(runner, cwd, f"DROP DATABASE IF EXISTS {database}")


def run_check(
    snapshot_root: Path, runner: Runner = _subprocess_runner, cwd: Path | None = PROJECT_ROOT
) -> list[str]:
    """Problems found in the newest complete snapshot; empty means it restores and its files are intact."""
    found = snapshot.latest(snapshot_root)
    if found is None:
        return [f"no complete snapshot under {snapshot_root}"]
    folder, files = found
    document = _read_meta(folder)
    problems = verify_files(folder, files)
    dump = folder / DUMP_PATH
    if not dump.is_file():
        return problems + [f"{DUMP_PATH}: missing, nothing to import"]
    actual, import_problems = import_and_count(dump, document.get("prefix", DEFAULT_PREFIX), runner, cwd)
    if import_problems:
        return problems + import_problems
    return problems + compare(document.get("counts") or {}, actual)


def _read_meta(folder: Path) -> dict:
    return json.loads((folder / snapshot.SNAPSHOT_JSON).read_text(encoding="utf-8"))


def check_main(snapshot_root: Path, stdout: TextIO = sys.stdout, stderr: TextIO = sys.stderr, **kwargs) -> int:
    """`pwg-backup --check`: 0 when the newest snapshot restores, else EXIT_CHECK_FAILED."""
    try:
        problems = run_check(snapshot_root, **kwargs)
    except CheckError as error:
        problems = [str(error)]
    if problems:
        for line in problems:
            print(f"check failed: {line}", file=stderr)
        return EXIT_CHECK_FAILED
    print("check ok: the newest snapshot imports and its files match", file=stdout)
    return 0
