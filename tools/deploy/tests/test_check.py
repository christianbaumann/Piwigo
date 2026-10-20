import gzip
import hashlib
import io
import json

import pytest

from pwgdeploy import check, snapshot

COUNTS = {"images": 3, "categories": 2, "tags": 5, "users": 1}


def test_compare_equal_counts_have_no_problem():
    assert check.compare(COUNTS, dict(COUNTS)) == []


def test_compare_names_each_differing_table():
    actual = {**COUNTS, "tags": 4, "users": 0}
    assert check.compare(COUNTS, actual) == [
        "tags: snapshot has 5 rows, the import has 4",
        "users: snapshot has 1 rows, the import has 0",
    ]


def test_compare_a_table_missing_from_the_import_fails():
    assert check.compare(COUNTS, {"images": 3}) == [
        "categories: not counted after the import",
        "tags: not counted after the import",
        "users: not counted after the import",
    ]


def test_compare_empty_expected_counts_is_a_failure():
    assert len(check.compare({}, COUNTS)) == 1


def make_snapshot(root, files, meta=None, dump=b"SELECT 1;"):
    folder = root / "2026-10-20T100000"
    entries = {}
    contents = {**files, check.DUMP_PATH: gzip.compress(dump)}
    for path, data in contents.items():
        target = folder / snapshot.FILES_DIR / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        entries[path] = {"size": len(data), "mtime": 1, "sha1": hashlib.sha1(data).hexdigest()}
    (folder / snapshot.SNAPSHOT_JSON).write_text(json.dumps({"counts": COUNTS, **(meta or {}), "files": entries}))
    return folder


class FakeRunner:
    def __init__(self, counts=COUNTS, import_code=0, import_error=""):
        self.calls, self.counts, self.import_code, self.import_error = [], counts, import_code, import_error

    def __call__(self, args, gz_stdin, cwd):
        self.calls.append((args, gz_stdin))
        if gz_stdin is not None:
            return self.import_code, "", self.import_error
        if "SELECT" in args[-1] and "COUNT" in args[-1]:
            return 0, "".join(f"{k}\t{v}\n" for k, v in self.counts.items()), ""
        return 0, "", ""


def statements(runner):
    return [args[-1] for args, stdin in runner.calls if stdin is None]


def test_a_matching_snapshot_passes_and_the_scratch_database_is_dropped(tmp_path):
    make_snapshot(tmp_path, {"upload/a.jpg": b"abc"})
    runner = FakeRunner()
    assert check.run_check(tmp_path, runner=runner) == []
    assert statements(runner)[-1] == "DROP DATABASE IF EXISTS backup_check"
    assert all("piwigo_images" in s for s in statements(runner) if "COUNT" in s)


def test_the_snapshot_prefix_is_used(tmp_path):
    make_snapshot(tmp_path, {}, meta={"prefix": "pwg_"})
    runner = FakeRunner()
    check.run_check(tmp_path, runner=runner)
    assert any("backup_check.pwg_users" in s for s in statements(runner))


def test_an_import_error_is_named_and_the_database_still_dropped(tmp_path):
    make_snapshot(tmp_path, {})
    runner = FakeRunner(import_code=1, import_error="ERROR 1064 syntax")
    problems = check.run_check(tmp_path, runner=runner)
    assert any("import" in p and "ERROR 1064" in p for p in problems)
    assert statements(runner)[-1] == "DROP DATABASE IF EXISTS backup_check"


def test_a_count_mismatch_fails(tmp_path):
    make_snapshot(tmp_path, {})
    problems = check.run_check(tmp_path, runner=FakeRunner(counts={**COUNTS, "images": 2}))
    assert problems == ["images: snapshot has 3 rows, the import has 2"]


def test_a_changed_file_is_named(tmp_path):
    folder = make_snapshot(tmp_path, {"upload/a.jpg": b"abc"})
    (folder / "files/upload/a.jpg").write_bytes(b"abd")
    assert check.run_check(tmp_path, runner=FakeRunner()) == ["upload/a.jpg: sha1 differs from snapshot.json"]


def test_a_missing_and_a_resized_file_are_named(tmp_path):
    folder = make_snapshot(tmp_path, {"upload/a.jpg": b"abc", "upload/b.jpg": b"abc"})
    (folder / "files/upload/a.jpg").unlink()
    (folder / "files/upload/b.jpg").write_bytes(b"abcd")
    assert check.verify_files(folder, snapshot.latest(tmp_path)[1]) == [
        "upload/a.jpg: missing",
        "upload/b.jpg: size differs from snapshot.json",
    ]


def test_only_the_first_files_are_listed(tmp_path):
    paths = {f"upload/{i}.jpg": b"x" for i in range(check.MAX_REPORTED_FILES + 3)}
    folder = make_snapshot(tmp_path, paths)
    for path in paths:
        (folder / "files" / path).write_bytes(b"y")
    problems = check.verify_files(folder, snapshot.latest(tmp_path)[1])
    assert len(problems) == check.MAX_REPORTED_FILES + 1
    assert problems[-1] == "... and 3 more files"


def test_no_snapshot_fails(tmp_path):
    assert "no complete snapshot" in check.run_check(tmp_path, runner=FakeRunner())[0]


def test_another_database_name_is_refused():
    with pytest.raises(check.CheckError, match="db"):
        check.import_and_count(None, "piwigo_", FakeRunner(), None, database="db")


def test_a_hostile_prefix_is_refused(tmp_path):
    make_snapshot(tmp_path, {}, meta={"prefix": "x; DROP DATABASE db; --"})
    assert check.check_main(tmp_path, stdout=io.StringIO(), stderr=io.StringIO(), runner=FakeRunner()) == 1


def test_check_main_exit_codes(tmp_path):
    make_snapshot(tmp_path, {})
    out, err = io.StringIO(), io.StringIO()
    assert check.check_main(tmp_path, out, err, runner=FakeRunner()) == 0
    assert "check ok" in out.getvalue()
    err = io.StringIO()
    assert check.check_main(tmp_path, io.StringIO(), err, runner=FakeRunner(counts={})) == 1
    assert "check failed" in err.getvalue()
