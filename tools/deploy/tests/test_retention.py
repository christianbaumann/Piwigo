from datetime import datetime, timedelta

import pytest

from pwgdeploy import retention
from pwgdeploy.retention import prune, select_for_deletion
from pwgdeploy.snapshot import parse_name, snapshot_name

NOW = datetime(2026, 10, 20, 10, 0, 0)  # a Tuesday, ISO week 43
ONE_SECOND = timedelta(seconds=1)
MIN_SNAPSHOTS = 400
MIN_OLD_BUCKETS = 30  # measured 32 on 2026-10-20: weeks beyond 90 days, then months


def at(age_days, kind="daily", now=NOW):
    return snapshot_name(now - timedelta(days=age_days), kind)


def stamp(year, month, day, hour=10, minute=0, second=0, kind="daily"):
    return snapshot_name(datetime(year, month, day, hour, minute, second), kind)


NEWEST = at(1)


def deleted(*names, now=NOW):
    return select_for_deletion(list(names), now)


# [BVA] daily/manual window: 14 days. The monday sibling shares ISO week 41 with the limit.
@pytest.mark.parametrize("kind", ["daily", "manual"])
def test_the_14_day_window_ends_at_exactly_14_days(kind):
    at_limit = stamp(2026, 10, 6, kind=kind)
    past_limit = stamp(2026, 10, 6, 9, 59, 59, kind=kind)
    monday = stamp(2026, 10, 5)
    assert at(14, kind) == at_limit
    assert deleted(NEWEST, at_limit, past_limit, monday) == [monday]


# [BVA] predeploy/prerestore window: 90 days; the monday sibling shares ISO week 30.
@pytest.mark.parametrize("kind", ["predeploy", "prerestore"])
def test_the_90_day_window_ends_at_exactly_90_days(kind):
    at_limit = stamp(2026, 7, 22, kind=kind)
    past_limit = stamp(2026, 7, 22, 9, 59, 59, kind=kind)
    monday = stamp(2026, 7, 20)
    assert at(90, kind) == at_limit
    assert deleted(NEWEST, at_limit, past_limit, monday) == sorted([past_limit, monday])


@pytest.mark.parametrize("kind", ["predeploy", "prerestore"])
def test_a_snapshot_at_90_days_is_kept_by_its_window_not_by_being_the_weeks_newest(kind):
    at_limit = stamp(2026, 7, 22, kind=kind)
    newer_in_week = stamp(2026, 7, 22, 11)
    assert deleted(NEWEST, at_limit, newer_in_week) == []


@pytest.mark.parametrize("kind", ["predeploy", "prerestore"])
def test_a_predeploy_like_snapshot_past_90_days_is_thinned_like_a_daily(kind):
    past_limit = stamp(2026, 7, 22, 9, 59, 59, kind=kind)
    newer_in_week = stamp(2026, 7, 22, 11)
    assert deleted(NEWEST, past_limit, newer_in_week) == [past_limit]


# [ECP] kind x age zones
def test_a_daily_at_15_days_is_thinned_but_a_predeploy_at_15_days_is_kept():
    same_week_pair = [stamp(2026, 10, 5), stamp(2026, 10, 5, 9, kind="predeploy")]
    # the daily is the week's newest (10:00); the older predeploy is inside its 90 days
    assert deleted(NEWEST, *same_week_pair) == []


def test_dailies_inside_14_days_are_never_thinned():
    names = [at(i) for i in range(1, 15)]
    assert deleted(*names) == []


def test_predeploys_inside_90_days_are_never_thinned():
    names = [NEWEST] + [at(30 + i, "predeploy") for i in range(3)]
    assert deleted(*names) == []


# [BVA] weekly zone: one per ISO week, the newest
def test_one_snapshot_per_iso_week_between_14_days_and_6_months_the_newest():
    mon, tue, wed = stamp(2026, 8, 3, 3), stamp(2026, 8, 4, 3), stamp(2026, 8, 5, 3)
    assert deleted(NEWEST, mon, tue, wed) == [mon, tue]


def test_sunday_and_monday_are_different_iso_weeks():
    assert deleted(NEWEST, stamp(2026, 8, 2, 3), stamp(2026, 8, 3, 3)) == []


def test_an_iso_week_spans_the_new_year():
    now = datetime(2027, 2, 15, 10)
    thursday, friday = stamp(2026, 12, 31, 3), stamp(2027, 1, 1, 3)  # both ISO 2026-W53
    assert deleted(snapshot_name(now), thursday, friday, now=now) == [thursday]


# [BVA] monthly zone and the 183-day boundary (2026-04-20 10:00)
def test_beyond_6_months_one_per_calendar_month_the_newest():
    now = datetime(2027, 6, 1, 10)
    first, last = stamp(2026, 9, 2, 3), stamp(2026, 9, 28, 3)
    october = stamp(2026, 10, 2, 3)
    assert deleted(snapshot_name(now), first, last, october, now=now) == [first]


def test_at_exactly_183_days_a_snapshot_is_still_weekly():
    at_limit = stamp(2026, 4, 20)  # Monday, 183 days old
    earlier_week = stamp(2026, 4, 12)  # Sunday, ISO week 15, monthly zone
    assert at(retention.WEEKLY_DAYS) == at_limit
    assert deleted(NEWEST, at_limit, earlier_week) == []


def test_one_second_past_183_days_a_snapshot_is_monthly():
    past_limit = stamp(2026, 4, 20, 9, 59, 59)
    earlier_week = stamp(2026, 4, 12)
    assert deleted(NEWEST, past_limit, earlier_week) == [earlier_week]


# the newest complete snapshot
def test_a_lone_snapshot_older_than_every_limit_is_kept():
    assert deleted(at(2000, "predeploy")) == []


def test_the_newest_is_kept_even_when_a_thinning_bucket_would_drop_it():
    # newest is a daily 800 days old; the same month holds a newer predeploy that is not newest overall
    old_daily = stamp(2024, 8, 1)
    old_predeploy = stamp(2024, 8, 20, kind="predeploy")
    assert deleted(old_daily, old_predeploy) == [old_daily]
    assert deleted(NEWEST, old_daily, old_predeploy) == [old_daily]


def test_the_newest_is_by_time_not_by_name_order():
    # '-predeploy' sorts after the bare stamp of the same second; time decides first
    older_predeploy = stamp(2024, 8, 1, 0, 0, 0, kind="predeploy")
    newer_daily = stamp(2024, 8, 1, 0, 0, 1)
    assert deleted(older_predeploy, newer_daily) == [older_predeploy]


@pytest.mark.parametrize(
    "junk", ["backup.log", "photos", stamp(2026, 7, 1) + ".partial", "2026-07-01T100000-bogus", "x", ""]
)
def test_names_the_parser_rejects_are_neither_deleted_nor_counted(junk):
    assert parse_name(junk) is None
    old_a, old_b = stamp(2024, 8, 1), stamp(2024, 8, 2)
    assert deleted(junk, old_a, old_b) == [old_a]


def test_no_snapshots():
    assert deleted() == []


def test_two_years_of_dailies_and_thirty_predeploys_match_the_rule_case_by_case():
    names = [at(i) for i in range(1, 731)] + [at(i * 20 + 3, "predeploy") for i in range(30)]
    assert len(names) >= MIN_SNAPSHOTS
    gone = set(select_for_deletion(names, NOW))
    kept = [n for n in names if n not in gone]
    assert gone and kept

    def when(n):
        return parse_name(n)[0]

    def age(n):
        return NOW - when(n)

    def zone_key(n):
        return ("week", *when(n).isocalendar()[:2]) if age(n) <= timedelta(days=retention.WEEKLY_DAYS) \
            else ("month", when(n).year, when(n).month)

    for n in names:
        window = timedelta(days=retention.WINDOW_DAYS[parse_name(n)[1]])
        if age(n) <= window:
            assert n in kept, f"{n} is inside its window"
        elif age(n) > timedelta(days=retention.DAILY_DAYS):
            newest_of_bucket = max(
                (m for m in names if age(m) > timedelta(days=retention.DAILY_DAYS) and zone_key(m) == zone_key(n)),
                key=lambda m: (when(m), m),
            )
            assert (n in kept) == (n == newest_of_bucket), f"{n}: kept only as its bucket's newest"
    kept_old = [n for n in kept if age(n) > timedelta(days=retention.PREDEPLOY_DAYS)]
    keys = [zone_key(n) for n in kept_old]
    assert len(keys) == len(set(keys)) >= MIN_OLD_BUCKETS


def make_snapshot(root, name):
    (root / name / "files").mkdir(parents=True)
    (root / name / "snapshot.json").write_text("{}")


def test_prune_returns_the_deleted_names_and_removes_exactly_those(tmp_path):
    mon, tue = stamp(2026, 8, 3, 3), stamp(2026, 8, 4, 3)
    for name in (NEWEST, mon, tue):
        make_snapshot(tmp_path, name)
    assert prune(tmp_path, NOW) == [mon]
    assert not (tmp_path / mon).exists()
    assert (tmp_path / tue).is_dir() and (tmp_path / NEWEST).is_dir()


def test_prune_leaves_partial_foreign_folders_and_files(tmp_path):
    mon, tue = stamp(2026, 8, 3, 3), stamp(2026, 8, 4, 3)
    for name in (NEWEST, mon, tue):
        make_snapshot(tmp_path, name)
    partial = tmp_path / (stamp(2024, 1, 1) + ".partial")
    (partial / "files").mkdir(parents=True)
    (tmp_path / "photos").mkdir()
    (tmp_path / "photos" / "a.jpg").write_text("x")
    (tmp_path / "backup.log").write_text("log")
    assert prune(tmp_path, NOW) == [mon]
    assert partial.is_dir()
    assert (tmp_path / "photos" / "a.jpg").read_text() == "x"
    assert (tmp_path / "backup.log").read_text() == "log"


def test_prune_never_deletes_a_file_named_like_a_snapshot(tmp_path):
    make_snapshot(tmp_path, NEWEST)
    lookalike = tmp_path / stamp(2024, 8, 1)
    lookalike.write_text("not a folder")
    other = stamp(2024, 8, 2)
    make_snapshot(tmp_path, other)
    assert prune(tmp_path, NOW) == []
    assert lookalike.read_text() == "not a folder"


def test_prune_keeps_a_lone_snapshot_older_than_every_limit(tmp_path):
    lone = at(2000, "predeploy")
    make_snapshot(tmp_path, lone)
    assert prune(tmp_path, NOW) == []
    assert (tmp_path / lone).is_dir()


def test_prune_on_a_missing_root_deletes_nothing(tmp_path):
    assert prune(tmp_path / "nope", NOW) == []
