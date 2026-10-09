"""The tag groups and tags every install carries, from tag-groups.json.

The database never travels (decision 0023), so the groups a photo's tags are coloured by
have to be created on each install. The file is the single record of them: names,
colours, the striped flag, the emoji, and which tag sits in which group.

Applied over ws.php only, which is all the remote offers. Matches by name, adds what is
missing, corrects what differs, and never deletes: a group or tag the file does not name
is left as it is. The re-read afterwards is the proof - an `ok` answer is not.
"""

from __future__ import annotations

import json
import re
import unicodedata
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Mapping

from pwgdeploy import bootstrap
from pwgdeploy.errors import ConfigError, RemoteHttpError

# Not published (decision 0022): the deploy reads it from this checkout.
TAG_GROUPS_PATH = Path(__file__).resolve().parents[1] / "tag-groups.json"

# TYPETAGS_EMOJI_MAX_CODEPOINTS and TYPETAGS_UNICODE_MAX in
# plugins/typetags/include/functions.inc.php.
EMOJI_MAX_CODEPOINTS = 8
UNICODE_MAX = 0x10FFFF
_SURROGATES = range(0xD800, 0xE000)

_COLOR = re.compile(r"#[0-9A-Fa-f]{6}")
# The form typetags_emoji_codepoints() stores: upper-case hex, one space apart. Anything
# else would compare unequal to the stored value and be re-sent on every run.
# No leading zero: it stores sprintf('%X').
_EMOJI = re.compile(r"[1-9A-F][0-9A-F]{0,5}( [1-9A-F][0-9A-F]{0,5})*")


@dataclass(frozen=True)
class Group:
    name: str
    color: str
    striped: bool = False
    emoji: str = ""


@dataclass(frozen=True)
class TagGroups:
    groups: tuple[Group, ...]
    tags: tuple[tuple[str, str], ...]
    """(tag name, group name)"""


@dataclass(frozen=True)
class SeedPlan:
    add_groups: tuple[Group, ...]
    update_groups: tuple[tuple[int, Group], ...]
    """(remote group id, what it should be)"""
    add_tags: tuple[tuple[str, str], ...]
    regroup_tags: tuple[tuple[int, str], ...]
    """(remote tag id, group name)"""

    @property
    def is_empty(self) -> bool:
        return not (self.add_groups or self.update_groups or self.add_tags or self.regroup_tags)


@dataclass(frozen=True)
class SeedResult:
    groups_checked: int
    tags_checked: int
    groups_added: int
    groups_changed: int
    tags_added: int
    tags_regrouped: int


# --- the file ------------------------------------------------------------------------


def load_tag_groups(path: Path = TAG_GROUPS_PATH) -> TagGroups:
    try:
        raw = json.loads(Path(path).read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        raise ConfigError(f"cannot read {path}: {error}") from error
    try:
        return parse_tag_groups(raw)
    except ConfigError as error:
        raise ConfigError(f"{path}: {error}") from error


def parse_tag_groups(raw: Any) -> TagGroups:
    if (
        not isinstance(raw, Mapping)
        or not isinstance(raw.get("groups"), list)
        or not isinstance(raw.get("tags"), list)
    ):
        raise ConfigError("tag-groups needs a 'groups' and a 'tags' list")

    groups = tuple(_group(entry) for entry in raw["groups"])
    _refuse_duplicates("group", [g.name for g in groups])
    known = {g.name for g in groups}

    tags = tuple(_tag(entry) for entry in raw["tags"])
    _refuse_duplicates("tag", [name for name, _ in tags])
    for name, group in tags:
        if group not in known:
            raise ConfigError(f"tag {name!r} names the unknown group {group!r}")
    return TagGroups(groups=groups, tags=tags)


def _group(entry: Any) -> Group:
    if not isinstance(entry, Mapping) or not entry.get("name") or not isinstance(entry["name"], str):
        raise ConfigError(f"a group needs a name: {entry!r}")
    name = entry["name"]
    color = entry.get("color")
    if not isinstance(color, str) or not _COLOR.fullmatch(color):
        raise ConfigError(f"group {name!r}: color must be #RRGGBB, not {color!r}")
    striped = entry.get("striped", False)
    if not isinstance(striped, bool):
        raise ConfigError(f"group {name!r}: striped must be true or false")
    emoji = entry.get("emoji", "")
    if not isinstance(emoji, str) or (emoji and not _valid_emoji(emoji)):
        raise ConfigError(
            f"group {name!r}: emoji must be up to {EMOJI_MAX_CODEPOINTS} upper-case "
            f"code points one space apart, e.g. '1F5BC FE0F', not {emoji!r}"
        )
    return Group(name=name, color=color, striped=striped, emoji=emoji)


def _valid_emoji(emoji: str) -> bool:
    if not _EMOJI.fullmatch(emoji):
        return False
    codepoints = [int(part, 16) for part in emoji.split(" ")]
    return len(codepoints) <= EMOJI_MAX_CODEPOINTS and all(
        0 < c <= UNICODE_MAX and c not in _SURROGATES for c in codepoints
    )


def _tag(entry: Any) -> tuple[str, str]:
    if (
        not isinstance(entry, Mapping)
        or not isinstance(entry.get("name"), str)
        or not entry["name"]
        or not isinstance(entry.get("group"), str)
    ):
        raise ConfigError(f"a tag needs a name and a group: {entry!r}")
    return entry["name"], entry["group"]


def _refuse_duplicates(kind: str, names: list[str]) -> None:
    seen: set[str] = set()
    for name in names:
        if name in seen:
            raise ConfigError(f"the {kind} {name!r} is listed twice")
        seen.add(name)


# --- what the server holds -----------------------------------------------------------


def read_remote(client, base_url: str) -> tuple[list[Mapping], list[Mapping]]:
    groups = bootstrap.ws_call(client, base_url, "typetags.type.list")
    if not isinstance(groups, list):
        raise RemoteHttpError(
            f"typetags.type.list returned an unexpected result: {groups!r:.200}"
        )
    return groups, parse_tag_list(bootstrap.ws_call(client, base_url, "pwg.tags.getAdminList"))


def parse_tag_list(result: Any) -> list[Mapping]:
    """getAdminList's rows. `name_raw` is what is stored, and the only one a name from the
    file can be compared with."""
    tags = result.get("tags") if isinstance(result, Mapping) else None
    if not isinstance(tags, list) or not all(
        isinstance(t, Mapping) and "id" in t and "name_raw" in t and "id_typetags" in t
        for t in tags
    ):
        raise RemoteHttpError(
            f"pwg.tags.getAdminList returned an unexpected result: {result!r:.200}"
        )
    return tags


def collated(name: str) -> str:
    """A name as utf8mb3_general_ci compares it: no case, no accents, no trailing spaces.
    typetags.type.add and core's create_tag look a name up that way, so two names equal
    here are one name to the server, and adding the second is refused."""
    stripped = "".join(
        c for c in unicodedata.normalize("NFKD", name) if not unicodedata.combining(c)
    )
    return stripped.casefold().rstrip(" ")


def plan_seed(wanted: TagGroups, groups: list[Mapping], tags: list[Mapping]) -> SeedPlan:
    remote_groups = {collated(g["name"]): g for g in groups}
    group_ids = {name: int(g["id"]) for name, g in remote_groups.items()}

    add_groups = []
    update_groups = []
    for group in wanted.groups:
        found = remote_groups.get(collated(group.name))
        if found is None:
            add_groups.append(group)
        elif not _same_group(group, found):
            update_groups.append((int(found["id"]), group))

    remote_tags = {collated(t["name_raw"]): t for t in tags}
    add_tags = []
    regroup_tags = []
    for name, group in wanted.tags:
        found = remote_tags.get(collated(name))
        if found is None:
            add_tags.append((name, group))
        elif found["id_typetags"] is None or int(found["id_typetags"]) != group_ids.get(collated(group)):
            regroup_tags.append((int(found["id"]), group))

    return SeedPlan(
        add_groups=tuple(add_groups),
        update_groups=tuple(update_groups),
        add_tags=tuple(add_tags),
        regroup_tags=tuple(regroup_tags),
    )


def _same_group(group: Group, found: Mapping) -> bool:
    return (
        str(found.get("color", "")).upper() == group.color.upper()
        and bool(found.get("striped")) == group.striped
        and found.get("emoji", "") == group.emoji
    )


# --- applying ------------------------------------------------------------------------


def seed_tags(client, base_url: str, token: str, wanted: TagGroups) -> SeedResult:
    groups, tags = read_remote(client, base_url)
    plan = plan_seed(wanted, groups, tags)
    group_ids = {collated(g["name"]): int(g["id"]) for g in groups}

    for group in plan.add_groups:
        added = bootstrap.ws_call(
            client,
            base_url,
            "typetags.type.add",
            {"typetag_name": group.name, **_group_fields(group)},
        )
        group_ids[collated(group.name)] = _answer_id(added, "typetags.type.add")
    for group_id, group in plan.update_groups:
        bootstrap.ws_call(
            client,
            base_url,
            "typetags.type.update",
            {"typetag_id": str(group_id), "pwg_token": token, **_group_fields(group)},
        )

    regroup = list(plan.regroup_tags)
    for name, group in plan.add_tags:
        added = bootstrap.ws_call(client, base_url, "pwg.tags.add", {"name": name})
        regroup.append((_answer_id(added, "pwg.tags.add"), group))
    for tag_id, group in regroup:
        bootstrap.ws_call(
            client,
            base_url,
            "typetags.tags.setType",
            {"tag_id": str(tag_id), "typetag_id": str(group_ids[collated(group)])},
        )

    left = plan_seed(wanted, *read_remote(client, base_url))
    if not left.is_empty:
        raise RemoteHttpError(
            f"the gallery does not hold what tag-groups.json says after seeding: {left}"
        )

    return SeedResult(
        groups_checked=len(wanted.groups),
        tags_checked=len(wanted.tags),
        groups_added=len(plan.add_groups),
        groups_changed=len(plan.update_groups),
        tags_added=len(plan.add_tags),
        tags_regrouped=len(plan.regroup_tags),
    )


def _group_fields(group: Group) -> dict[str, str]:
    """typetags.type.add prefixes the colour with '#' itself; type.update strips one."""
    return {
        "typetag_color": group.color.lstrip("#"),
        "striped": "1" if group.striped else "0",
        "emoji": group.emoji,
    }


def _answer_id(answer: Any, method: str) -> int:
    if not isinstance(answer, Mapping) or "id" not in answer:
        raise RemoteHttpError(f"{method} returned no id: {answer!r:.200}")
    return int(answer["id"])
