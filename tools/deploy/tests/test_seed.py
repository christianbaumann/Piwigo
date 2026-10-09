"""The tag groups and tags every install carries, applied from tag-groups.json over ws.php.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA] [ST].
Decision table not applicable: each planned change depends on one comparison at a time.

The seed runs against `FakeGallery`, which keeps the groups and tags it was given, so
"a second run changes nothing" is asked of a server rather than of a script.
"""

import json

import pytest

from pwgdeploy import seed
from pwgdeploy.errors import ConfigError, RemoteHttpError
from tests.fakes import FakeGallery, php_value

BASE_URL = "https://g.example.test"

# Anti-vacuity: what the committed file held when this was written, 2026-10-09. A file
# that loads as fewer has lost rows, and every "plans nothing" case would pass on it.
MIN_GROUPS = 12
MIN_TAGS = 11

RAW = {
    "groups": [
        {"name": "Personen", "color": "#FFFFB6"},
        {"name": "Name ?", "color": "#D00000", "striped": True},
        {"name": "Ausstellung", "color": "#E4E6E3", "emoji": "1F5BC FE0F"},
    ],
    "tags": [
        {"name": "Personen", "group": "Personen"},
        {"name": "Name ?", "group": "Name ?"},
    ],
}


def wanted(raw=None):
    return seed.parse_tag_groups(raw or RAW)


def with_groups(*groups, tags=None):
    raw = json.loads(json.dumps(RAW))
    raw["groups"] = list(groups)
    if tags is not None:
        raw["tags"] = tags
    return raw


def seeded_gallery(**overrides):
    """A gallery already holding exactly RAW, the way the server answers it."""
    groups = [
        {"id": 1, "name": "Personen", "color": "#FFFFB6", "striped": False, "emoji": ""},
        {"id": 2, "name": "Name ?", "color": "#d00000", "striped": True, "emoji": ""},
        {"id": 3, "name": "Ausstellung", "color": "#E4E6E3", "striped": False, "emoji": "1F5BC FE0F"},
    ]
    tags = [
        {"id": "1", "name": "Personen", "id_typetags": "1"},
        {"id": "5", "name": "Name ?", "id_typetags": "2"},
    ]
    options = {"tag_groups": groups, "tags": tags, "plugin_states": {"typetags": "active"}}
    options.update(overrides)
    gallery = FakeGallery(BASE_URL, **options)
    gallery.logged_in = True
    return gallery


def empty_gallery():
    return seeded_gallery(tag_groups=[], tags=[])


def plan_against(gallery, raw=None):
    groups, tags = seed.read_remote(gallery, BASE_URL)
    return seed.plan_seed(wanted(raw), groups, tags)


# --- the committed file --------------------------------------------------------------


def test_the_committed_file_loads():
    """[HAPPY] The file every deploy reads; a typo in it would stop the deploy."""
    loaded = seed.load_tag_groups(seed.TAG_GROUPS_PATH)
    assert len(loaded.groups) >= MIN_GROUPS
    assert len(loaded.tags) >= MIN_TAGS


def test_the_emoji_limit_is_the_one_typetags_enforces():
    """[HAPPY] Read from the PHP, so the two cannot drift apart."""
    assert seed.EMOJI_MAX_CODEPOINTS == php_value(
        "plugins/typetags/include/functions.inc.php",
        r"define\('TYPETAGS_EMOJI_MAX_CODEPOINTS',\s*(\d+)\);",
    )


# --- loading -------------------------------------------------------------------------


def test_load_reads_groups_and_tags():
    """[HAPPY] Defaults: not striped, no emoji."""
    loaded = wanted()
    assert loaded.groups[0] == seed.Group("Personen", "#FFFFB6", False, "")
    assert loaded.groups[1].striped is True
    assert loaded.groups[2].emoji == "1F5BC FE0F"
    assert loaded.tags == (("Personen", "Personen"), ("Name ?", "Name ?"))


def test_a_duplicate_group_is_refused():
    """[NEG]"""
    raw = with_groups(*RAW["groups"], {"name": "Personen", "color": "#000000"})
    with pytest.raises(ConfigError, match="Personen"):
        wanted(raw)


def test_a_duplicate_tag_is_refused():
    """[NEG]"""
    raw = with_groups(*RAW["groups"], tags=RAW["tags"] + [RAW["tags"][0]])
    with pytest.raises(ConfigError, match="Personen"):
        wanted(raw)


def test_a_tag_in_an_unknown_group_is_refused():
    """[NEG]"""
    raw = with_groups(*RAW["groups"], tags=[{"name": "X", "group": "Nirgends"}])
    with pytest.raises(ConfigError, match="Nirgends"):
        wanted(raw)


@pytest.mark.parametrize("color", ["FFFFB6", "#FFFFB", "#FFFFB66", "#GGGGGG", "", 7])
def test_a_bad_colour_is_refused(color):
    """[NEG][BVA] Six hex digits after a #, nothing else."""
    with pytest.raises(ConfigError, match="color"):
        wanted(with_groups({"name": "A", "color": color}, tags=[]))


@pytest.mark.parametrize(
    "emoji",
    [
        "1f5bc fe0f",  # not the form typetags stores, so it would be re-sent on every run
        "1F5BC  FE0F",
        "🖼️",
        "U+1F5BC",
        "110000",  # one past the last code point
        "D800",  # a surrogate
        "0",
        " ".join(["1F600"] * 9),
        "XYZ",
    ],
)
def test_a_bad_emoji_is_refused(emoji):
    """[NEG][BVA] The canonical code point form only."""
    with pytest.raises(ConfigError, match="emoji"):
        wanted(with_groups({"name": "A", "color": "#000000", "emoji": emoji}, tags=[]))


@pytest.mark.parametrize("emoji", ["10FFFF", " ".join(["1F600"] * 8), "270D FE0F"])
def test_a_valid_emoji_at_the_limits_is_accepted(emoji):
    """[BVA] The last code point, and exactly the maximum count."""
    loaded = wanted(with_groups({"name": "A", "color": "#000000", "emoji": emoji}, tags=[]))
    assert loaded.groups[0].emoji == emoji


@pytest.mark.parametrize(
    "raw",
    [
        {"groups": []},
        {"tags": []},
        {"groups": [{"color": "#000000"}], "tags": []},
        {"groups": [{"name": "A", "color": "#000000", "striped": "yes"}], "tags": []},
        {"groups": [], "tags": [{"name": "A"}]},
        [],
    ],
)
def test_a_malformed_file_is_refused(raw):
    """[NEG]"""
    with pytest.raises(ConfigError):
        seed.parse_tag_groups(raw)


def test_a_missing_file_is_a_config_error(tmp_path):
    """[NEG]"""
    with pytest.raises(ConfigError, match="tag-groups"):
        seed.load_tag_groups(tmp_path / "tag-groups.json")


# --- planning ------------------------------------------------------------------------


def test_an_empty_install_plans_every_group_and_tag():
    """[HAPPY]"""
    plan = plan_against(empty_gallery())
    assert [g.name for g in plan.add_groups] == ["Personen", "Name ?", "Ausstellung"]
    assert plan.add_tags == (("Personen", "Personen"), ("Name ?", "Name ?"))
    assert plan.update_groups == () and plan.regroup_tags == ()


def test_a_seeded_install_plans_nothing():
    """[ST] Colours compare without regard to case: typetags keeps what it was sent."""
    plan = plan_against(seeded_gallery())
    assert plan.is_empty, plan


@pytest.mark.parametrize(
    "field, value",
    [("color", "#000000"), ("striped", False), ("emoji", "270D FE0F")],
)
def test_a_changed_group_is_updated(field, value):
    """[ECP] One partition per field typetags.type.update writes."""
    gallery = seeded_gallery()
    gallery.tag_groups[1][field] = value
    plan = plan_against(gallery)
    assert [(i, g.name) for i, g in plan.update_groups] == [(2, "Name ?")]
    assert plan.add_groups == ()


def test_a_tag_in_another_group_is_regrouped():
    """[ECP]"""
    gallery = seeded_gallery()
    gallery.tags[1]["id_typetags"] = "1"
    assert plan_against(gallery).regroup_tags == ((5, "Name ?"),)


def test_an_ungrouped_tag_is_regrouped():
    """[BVA] No group at all is the other end from the wrong one."""
    gallery = seeded_gallery()
    gallery.tags[1]["id_typetags"] = None
    assert plan_against(gallery).regroup_tags == ((5, "Name ?"),)


def test_a_group_and_a_tag_only_the_remote_has_are_left_alone():
    """[NEG] The seed never deletes, and never touches what the file does not name."""
    gallery = seeded_gallery()
    gallery.tag_groups.append(
        {"id": 9, "name": "Eigene", "color": "#123456", "striped": False, "emoji": ""}
    )
    gallery.tags.append({"id": "9", "name": "Willy Weinandy", "id_typetags": None})
    assert plan_against(gallery).is_empty


# --- applying ------------------------------------------------------------------------


def test_seeding_an_empty_install_creates_everything():
    """[HAPPY]"""
    gallery = empty_gallery()
    result = seed.seed_tags(gallery, BASE_URL, FakeGallery.TOKEN, wanted())

    assert result == seed.SeedResult(
        groups_checked=3, tags_checked=2,
        groups_added=3, groups_changed=0, tags_added=2, tags_regrouped=0,
    )
    by_name = {g["name"]: g for g in gallery.tag_groups}
    assert by_name["Name ?"]["striped"] is True
    assert by_name["Ausstellung"]["emoji"] == "1F5BC FE0F"
    assert by_name["Personen"]["color"].upper() == "#FFFFB6"
    tag = next(t for t in gallery.tags if t["name"] == "Name ?")
    assert int(tag["id_typetags"]) == by_name["Name ?"]["id"]


def test_a_second_seed_changes_nothing():
    """[ST]"""
    gallery = empty_gallery()
    seed.seed_tags(gallery, BASE_URL, FakeGallery.TOKEN, wanted())
    gallery.calls.clear()

    result = seed.seed_tags(gallery, BASE_URL, FakeGallery.TOKEN, wanted())

    assert (result.groups_added, result.groups_changed, result.tags_added, result.tags_regrouped) == (0, 0, 0, 0)
    writes = {"typetags.type.add", "typetags.type.update", "pwg.tags.add", "typetags.tags.setType"}
    assert not writes & set(gallery.methods_called())


def test_a_changed_group_and_a_moved_tag_are_put_back():
    """[ECP]"""
    gallery = seeded_gallery()
    gallery.tag_groups[1]["color"] = "#000000"
    gallery.tags[1]["id_typetags"] = "1"

    result = seed.seed_tags(gallery, BASE_URL, FakeGallery.TOKEN, wanted())

    assert (result.groups_changed, result.tags_regrouped) == (1, 1)
    assert gallery.tag_groups[1]["color"].upper() == "#D00000"
    assert gallery.tags[1]["id_typetags"] == "2"


def test_the_update_carries_the_token():
    """[NEG] typetags.type.update refuses a call without it."""
    gallery = seeded_gallery()
    gallery.tag_groups[1]["emoji"] = "270D FE0F"
    seed.seed_tags(gallery, BASE_URL, FakeGallery.TOKEN, wanted())
    assert [f["pwg_token"] for f in gallery.posts_to("ws.php") if f.get("method") == "typetags.type.update"] == [FakeGallery.TOKEN]


@pytest.mark.parametrize(
    "lost", ["typetags.type.add", "typetags.type.update", "pwg.tags.add", "typetags.tags.setType"]
)
def test_a_write_the_server_did_not_keep_fails_the_seed(lost):
    """[NEG] The re-read is the proof; an ok answer is not."""
    gallery = empty_gallery() if lost in ("typetags.type.add", "pwg.tags.add") else seeded_gallery()
    if lost == "typetags.type.update":
        gallery.tag_groups[1]["color"] = "#000000"
    if lost == "typetags.tags.setType":
        gallery.tags[1]["id_typetags"] = "1"
    gallery.inert_methods.add(lost)

    with pytest.raises(RemoteHttpError, match="tag-groups"):
        seed.seed_tags(gallery, BASE_URL, FakeGallery.TOKEN, wanted())


def test_a_gallery_without_typetags_fails_with_the_server_s_message():
    """[NEG]"""
    gallery = seeded_gallery(plugin_states={"typetags": "inactive"})
    with pytest.raises(RemoteHttpError, match="typetags.type.list"):
        seed.seed_tags(gallery, BASE_URL, FakeGallery.TOKEN, wanted())


@pytest.mark.parametrize(
    "answer",
    [{"tags": [{"id": "1"}]}, {"tags": None}, [], None],
)
def test_an_unexpected_tag_list_is_refused(answer, monkeypatch):
    """[NEG] Read as "no tags", it would plan every tag as new."""
    with pytest.raises(RemoteHttpError, match="getAdminList"):
        seed.parse_tag_list(answer)
