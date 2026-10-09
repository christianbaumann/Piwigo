"""Install, config, session, plugins, sync — and the claim that a second run is a no-op.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA] [ST] [DT] [ERR].
Boundary values apply only to the scrapers (zero counts, no summary at all); the rest of
the module has no numeric domain.

Everything runs against `FakeGallery`, which keeps server-side state, so idempotence is
asked of a server rather than asserted about a script. What these tests cannot witness is
a real `install.php` — that is Phase 6's manual step and the hand-check ledger's entry.
"""

import pytest

from pwgdeploy import bootstrap, manifest, seed
from pwgdeploy.config import load
from pwgdeploy.errors import InstallError, RemoteHttpError
from tests.fakes import FakeGallery, FakeTransport

BASE_URL = "https://g.example.test"

RAW = {
    "ftp": {"host": "ftp.example.test", "user": "w1", "password": "p", "remote_root": "/piwigo"},
    "mysql": {
        "host": "localhost",
        "user": "d1",
        "password": "dbsecret",
        "database": "d1",
        "prefix": "pwg_",
    },
    "admin": {"username": "webmaster", "password": "p", "email": "you@example.net"},
    "site": {"base_url": BASE_URL, "language": "de_DE"},
}

# Anti-vacuity: an empty plugin tuple would satisfy every activation assertion below.
MIN_PLUGINS = 4
# Anti-vacuity for the generated PHP: an empty string contains every substring asserted
# of it exactly zero times, and `in` on "" would still be checked below.
MIN_CONFIG_BYTES = 40


MAIL = {
    "host": "smtp.example.test",
    "port": 465,
    "secure": "ssl",
    "user": "noreply@example.test",
    "password": "mailsecret",
    "sender_email": "noreply@example.test",
    "sender_name": "Bilder",
}


def config(**overrides):
    raw = {section: dict(values) for section, values in RAW.items()}
    if "mail" in overrides:
        raw["mail"] = dict(MAIL)
    for section, values in overrides.items():
        raw[section].update(values)
    return load(raw)


@pytest.fixture
def cfg():
    return config()


@pytest.fixture
def gallery():
    return FakeGallery(BASE_URL)


# --- install ------------------------------------------------------------------------


def test_a_fresh_gallery_reports_not_installed(cfg, gallery):
    """[HAPPY][ST] Before anything runs, install.php renders its form."""
    assert bootstrap.is_installed(gallery, cfg.site.base_url) is False


def test_an_installed_gallery_is_recognised_by_the_marker(cfg):
    """[ST] install.php:162 dies with this exact string; nothing else marks an install."""
    gallery = FakeGallery(BASE_URL, installed=True)

    assert bootstrap.is_installed(gallery, cfg.site.base_url) is True
    assert bootstrap.INSTALLED_MARKER == "Piwigo is already installed"


def test_install_posts_every_field_the_form_declares(cfg, gallery):
    """[HAPPY][DT] Ten of the twelve named inputs of install.tpl:203-295."""
    bootstrap.install(gallery, cfg)

    posted = gallery.posts_to("install.php")[-1]
    assert posted == {
        "dbhost": "localhost",
        "dbuser": "d1",
        "dbpasswd": "dbsecret",
        "dbname": "d1",
        "prefix": "pwg_",
        "admin_name": "webmaster",
        "admin_pass1": "p",
        "admin_pass2": "p",
        "admin_mail": "you@example.net",
        "install": "1",
    }


def test_install_omits_the_two_isset_checkboxes(cfg, gallery):
    """[NEG] install.php:147-151 reads both with isset(), so sending them at any value
    — including "0" — subscribes a newsletter and mails the credentials. Omission is the
    only way to say no."""
    bootstrap.install(gallery, cfg)

    posted = gallery.posts_to("install.php")[-1]
    assert "newsletter_subscribe" not in posted
    assert "send_credentials_by_mail" not in posted


def test_install_passes_the_language_as_a_get_parameter(cfg, gallery):
    """[ECP] install.tpl:212's select navigates to install.php?language=…; it is not a
    posted field, so posting it would leave the install in the default locale."""
    bootstrap.install(gallery, cfg)

    posted_urls = [call[1] for call in gallery.calls if call[0] == "post"]
    assert posted_urls  # anti-vacuity: the POST happened at all
    assert posted_urls == [f"{BASE_URL}/install.php?language=de_DE"]
    assert "language" not in gallery.posts_to("install.php")[-1]


def test_install_confirms_by_asking_the_server_again(cfg, gallery):
    """[ST] install.php answers 200 whether it installed or re-rendered its form, so the
    only trustworthy confirmation is a follow-up is_installed()."""
    bootstrap.install(gallery, cfg)

    assert gallery.installed is True
    assert bootstrap.is_installed(gallery, cfg.site.base_url) is True


def test_a_rejected_install_raises_with_the_server_s_own_errors(cfg):
    """[NEG] A wrong database password re-renders the form. The message must carry what
    the server said, or the operator has nothing to act on."""
    gallery = FakeGallery(BASE_URL, install_errors=["Connection to server succeeded, but unable to connect to database"])

    with pytest.raises(InstallError) as raised:
        bootstrap.install(gallery, cfg)

    assert "unable to connect to database" in str(raised.value)


def test_an_install_that_reports_no_error_at_all_still_fails_loudly(cfg):
    """[NEG] A response with neither the marker nor an error list is not a success. The
    failure names the URL rather than silently continuing to a login that cannot work."""
    gallery = FakeGallery(BASE_URL, install_errors=["   "])

    with pytest.raises(InstallError) as raised:
        bootstrap.install(gallery, cfg)

    assert "install.php" in str(raised.value)


def test_scrape_errors_reads_every_error_list_item():
    """[HAPPY] install.tpl:182-190 and :162-179 both use div.errors."""
    html = (
        '<div class="errors"><ul><li>first</li><li>second &amp; last</li></ul></div>'
        "<div class='infos'><ul><li>not an error</li></ul></div>"
    )

    assert bootstrap.scrape_errors(html) == ["first", "second & last"]


def test_scrape_errors_returns_nothing_for_a_page_without_an_error_block():
    """[BVA] The empty case, so a caller can distinguish "no errors" from "errors"."""
    assert bootstrap.scrape_errors("<div class='infos'><ul><li>ok</li></ul></div>") == []


# --- the generated config -----------------------------------------------------------


def test_generated_config_carries_the_three_settings(cfg):
    """[HAPPY] Decision 8: generated from the JSON, never uploaded from the local copy."""
    php = bootstrap.config_php(cfg.site)

    assert len(php) > MIN_CONFIG_BYTES
    assert php.startswith("<?php\n")
    assert "$conf['assume_https'] = true;" in php
    assert "$conf['provenance_exiftool_path'] = '';" in php
    assert "$conf['persons_exiftool_path'] = '';" in php


def test_generated_config_reflects_assume_https_false():
    """[ECP] The other side of the boolean partition — PHP has no `False`."""
    php = bootstrap.config_php(config(site={"assume_https": False}).site)

    assert "$conf['assume_https'] = false;" in php


def test_generated_config_quotes_an_exiftool_path_safely():
    """[NEG] A path holding a quote would otherwise end the PHP string and leave the
    remote with a file that does not parse — a gallery that serves a blank page."""
    php = bootstrap.config_php(config(site={"exiftool_path": "/o'brien/bin/"}).site)

    assert "$conf['persons_exiftool_path'] = '/o\\'brien/bin/';" in php


def test_generated_config_leaves_mail_to_core_without_a_mail_section(cfg):
    """[ECP] No section, no lines: core's own defaults (PHP mail()) stay in force."""
    php = bootstrap.config_php(cfg.site, cfg.mail)

    assert len(php) > MIN_CONFIG_BYTES
    assert "smtp" not in php
    assert "mail_sender" not in php


def test_generated_config_carries_the_smtp_settings():
    """[HAPPY] functions_mail.inc.php splits smtp_host on ':' for the port."""
    cfg = config(mail={})

    php = bootstrap.config_php(cfg.site, cfg.mail)

    assert "$conf['smtp_host'] = 'smtp.example.test:465';" in php
    assert "$conf['smtp_secure'] = 'ssl';" in php
    assert "$conf['smtp_user'] = 'noreply@example.test';" in php
    assert "$conf['smtp_password'] = 'mailsecret';" in php
    assert "$conf['mail_sender_email'] = 'noreply@example.test';" in php
    assert "$conf['mail_sender_name'] = 'Bilder';" in php


def test_generated_config_quotes_an_smtp_password_safely():
    """[NEG] A generated password may well hold ' or \\ — unescaped, the remote config
    would not parse and every page of the gallery would be blank."""
    cfg = config(mail={"password": "a'b\\c"})

    php = bootstrap.config_php(cfg.site, cfg.mail)

    assert "$conf['smtp_password'] = 'a\\'b\\\\c';" in php


def test_adding_a_mail_section_re_uploads_the_config(cfg, tmp_path):
    """[ST] A deploy after filling in `mail` is what puts SMTP live on the remote."""
    bootstrap.upload_config(cfg, tmp_path, FakeTransport())
    second = FakeTransport()

    uploaded = bootstrap.upload_config(config(mail={}), tmp_path, second)

    assert uploaded is True
    assert b"smtp_host" in second.files["/piwigo/local/config/config.inc.php"]


def test_config_upload_lands_at_the_remote_config_path(cfg, tmp_path):
    """[HAPPY] Same Transport, same manifest, same remote root as every other file."""
    transport = FakeTransport()

    uploaded = bootstrap.upload_config(cfg, tmp_path, transport)

    assert uploaded is True
    assert "/piwigo/local/config/config.inc.php" in transport.files
    assert transport.files["/piwigo/local/config/config.inc.php"].startswith(b"<?php")


def test_an_unchanged_config_is_not_uploaded_again(cfg, tmp_path):
    """[ST] The whole point of routing it through the manifest: a second run is silent."""
    bootstrap.upload_config(cfg, tmp_path, FakeTransport())
    second = FakeTransport()

    uploaded = bootstrap.upload_config(cfg, tmp_path, second)

    assert uploaded is False
    assert second.paths("put") == []


def test_a_changed_exiftool_path_re_uploads_the_config(cfg, tmp_path):
    """[ST] The manifest entry is a hash of the generated bytes, so editing the JSON is
    what makes the file pending — nothing has to remember that it changed."""
    bootstrap.upload_config(cfg, tmp_path, FakeTransport())
    changed = config(site={"exiftool_path": "/usr/local/bin/"})
    second = FakeTransport()

    uploaded = bootstrap.upload_config(changed, tmp_path, second)

    assert uploaded is True
    assert second.paths("put") == ["/piwigo/local/config/config.inc.php"]


def test_the_config_entry_joins_the_target_s_own_manifest(cfg, tmp_path):
    """[ST] Not a manifest of its own: a `--dry-run` after a deploy must see this file
    as unchanged like any other, and prune must never consider it removed."""
    bootstrap.upload_config(cfg, tmp_path, FakeTransport())

    entries = manifest.load(
        manifest.manifest_path(tmp_path, cfg.ftp.host, cfg.ftp.remote_root)
    )
    assert "/piwigo/local/config/config.inc.php" in entries


# --- session and plugins ------------------------------------------------------------


def test_login_returns_the_pwg_token(cfg, gallery):
    """[HAPPY] pwg.session.getStatus is where the token comes from (pwg.php:398-407)."""
    token = bootstrap.login(gallery, cfg)

    assert token == FakeGallery.TOKEN
    assert gallery.methods_called() == ["pwg.session.login", "pwg.session.getStatus"]


def test_a_wrong_password_fails_with_the_server_s_message(cfg):
    """[NEG] err 999 from ws_session_login; the operator needs to know it was the login
    and not the network."""
    gallery = FakeGallery(BASE_URL, admin=("webmaster", "other"))

    with pytest.raises(RemoteHttpError) as raised:
        bootstrap.login(gallery, cfg)

    assert "Invalid username/password" in str(raised.value)


def test_activation_installs_all_four_fork_plugins(cfg, gallery):
    """[HAPPY] Decision 6: activate falls through to install
    (admin/include/plugins.class.php:187-219), which is what creates each schema. From a
    gallery where none is active, so photoinfo only passes when provenance went first."""
    assert len(bootstrap.PLUGINS_TO_ACTIVATE) >= MIN_PLUGINS
    token = bootstrap.login(gallery, cfg)

    outcome = bootstrap.activate_plugins(gallery, cfg.site.base_url, token)

    assert outcome == {name: "activated" for name in bootstrap.PLUGINS_TO_ACTIVATE}
    assert all(gallery.plugin_states[name] == "active" for name in bootstrap.PLUGINS_TO_ACTIVATE)


def test_an_already_active_plugin_is_left_alone(cfg):
    """[ST] The idempotence claim: a second run performs no action at all."""
    gallery = FakeGallery(BASE_URL, plugin_states={name: "active" for name in bootstrap.PLUGINS_TO_ACTIVATE})
    token = bootstrap.login(gallery, cfg)

    outcome = bootstrap.activate_plugins(gallery, cfg.site.base_url, token)

    assert outcome == {name: "active" for name in bootstrap.PLUGINS_TO_ACTIVATE}
    assert "pwg.plugins.performAction" not in gallery.methods_called()


def test_an_inactive_plugin_is_activated_while_its_neighbour_is_not(cfg):
    """[DT] The mixed row of the table: state per plugin decides per plugin."""
    gallery = FakeGallery(
        BASE_URL,
        plugin_states={"typetags": "active", "provenance": "inactive", "persons": "uninstalled", "photoinfo": "active"},
    )
    token = bootstrap.login(gallery, cfg)

    outcome = bootstrap.activate_plugins(gallery, cfg.site.base_url, token)

    assert outcome == {"typetags": "active", "provenance": "activated", "persons": "activated", "photoinfo": "active"}
    assert sorted(call["plugin"] for call in gallery.posts_to("ws.php") if call.get("action") == "activate") == [
        "persons",
        "provenance",
    ]


def test_photoinfo_is_activated_after_provenance(cfg, gallery):
    """[NEG] photoinfo refuses to activate without provenance, so the order of
    PLUGINS_TO_ACTIVATE is load-bearing: the reverse order fails on the remote."""
    token = bootstrap.login(gallery, cfg)

    with pytest.raises(RemoteHttpError) as raised:
        bootstrap.activate_plugins(gallery, cfg.site.base_url, token, plugins=("photoinfo", "provenance"))

    assert "requires provenance" in str(raised.value)
    assert bootstrap.PLUGINS_TO_ACTIVATE.index("provenance") < bootstrap.PLUGINS_TO_ACTIVATE.index("photoinfo")


def test_activation_sends_the_token_with_every_action(cfg, gallery):
    """[NEG] ws_plugins_performAction refuses a mismatched token with err 403, so an
    omitted one would fail the whole bootstrap at its last useful step."""
    token = bootstrap.login(gallery, cfg)

    bootstrap.activate_plugins(gallery, cfg.site.base_url, token)

    actions = [call for call in gallery.posts_to("ws.php") if call.get("action") == "activate"]
    assert actions
    assert all(call["pwg_token"] == FakeGallery.TOKEN for call in actions)


def test_a_plugin_the_server_does_not_know_is_reported_as_missing(cfg):
    """[NEG] A plugin directory that never reached the web space is a partial deploy,
    and saying so beats a gallery that merely lacks a feature."""
    gallery = FakeGallery(BASE_URL, plugin_states={"typetags": "active", "provenance": "active"})
    token = bootstrap.login(gallery, cfg)

    with pytest.raises(RemoteHttpError) as raised:
        bootstrap.activate_plugins(gallery, cfg.site.base_url, token)

    assert "persons" in str(raised.value)


# --- sync ---------------------------------------------------------------------------


def test_sync_posts_the_field_set_remote_sync_replays(cfg, gallery):
    """[HAPPY][DT] tools/remote_sync.pl:41-56 verbatim; sync_meta=1 cannot be turned to
    0, it has to be there."""
    bootstrap.login(gallery, cfg)

    bootstrap.sync(gallery, cfg.site.base_url)

    posted = gallery.posts_to("site_update")[-1]
    assert posted == {
        "sync": "files",
        "display_info": "1",
        "add_to_caddie": "1",
        "privacy_level": "0",
        "sync_meta": "1",
        "simulate": "0",
        "subcats-included": "1",
        "submit": "1",
    }
    assert "page=site_update&site=1" in gallery.urls()[-1]


def test_sync_reports_the_counts_the_summary_carries(cfg):
    """[HAPPY] Read by class, not by the German label site_update.tpl renders."""
    gallery = FakeGallery(BASE_URL, albums_added=4, photos_added=106)
    bootstrap.login(gallery, cfg)

    counts = bootstrap.sync(gallery, cfg.site.base_url)

    assert (
        counts.albums_added,
        counts.photos_added,
        counts.albums_deleted,
        counts.photos_deleted,
        counts.errors,
    ) == (4, 106, 0, 0, 0)


def test_a_second_sync_reporting_zero_new_is_a_success(cfg):
    """[BVA] Zero is the expected second-run value, not a failure to parse."""
    gallery = FakeGallery(BASE_URL, albums_added=0, photos_added=0)
    bootstrap.login(gallery, cfg)

    counts = bootstrap.sync(gallery, cfg.site.base_url)

    assert (counts.albums_added, counts.photos_added) == (0, 0)


def test_sync_errors_are_carried_through(cfg):
    """[ECP] The error count is a separate class from the added counts and is reported."""
    gallery = FakeGallery(BASE_URL, sync_errors=2)
    bootstrap.login(gallery, cfg)

    assert bootstrap.sync(gallery, cfg.site.base_url).errors == 2


def test_a_sync_answered_by_the_login_page_fails_loudly(cfg, gallery):
    """[NEG] Without the session cookie admin.php bounces to identification.php and
    returns 200. Parsing that as "0 photos" would report a successful empty gallery."""
    with pytest.raises(RemoteHttpError) as raised:
        bootstrap.sync(gallery, cfg.site.base_url)

    assert "site_update" in str(raised.value)


def test_sync_reports_the_albums_and_photos_the_summary_says_were_deleted(cfg):
    """[HAPPY] An update run genuinely removes rows for photos that are gone; the
    summary carries the two counts and they were being read and discarded."""
    gallery = FakeGallery(BASE_URL, albums_deleted=2, photos_deleted=7)
    bootstrap.login(gallery, cfg)

    counts = bootstrap.sync(gallery, cfg.site.base_url)

    assert (counts.albums_deleted, counts.photos_deleted) == (2, 7)


def test_a_sync_that_deleted_nothing_reports_zero_deletions(cfg):
    """[BVA] Zero must read as "nothing was deleted", never as "field missing"."""
    gallery = FakeGallery(BASE_URL, albums_deleted=0, photos_deleted=0)
    bootstrap.login(gallery, cfg)

    counts = bootstrap.sync(gallery, cfg.site.base_url)

    assert (counts.albums_deleted, counts.photos_deleted) == (0, 0)


def test_the_added_and_deleted_counts_are_not_transposed(cfg):
    """[ERR] Four distinct values pin the field order. Without this a swapped pair —
    added into deleted, or albums into photos — passes every other test here."""
    gallery = FakeGallery(
        BASE_URL, albums_added=1, photos_added=2, albums_deleted=3, photos_deleted=4
    )
    bootstrap.login(gallery, cfg)

    counts = bootstrap.sync(gallery, cfg.site.base_url)

    assert (
        counts.albums_added,
        counts.photos_added,
        counts.albums_deleted,
        counts.photos_deleted,
    ) == (1, 2, 3, 4)


def test_parse_sync_counts_needs_both_deleted_lines():
    """[NEG] Same reason as the added-lines guard: a page shape this scraper does not
    understand must fail rather than report the missing half as zero."""
    with pytest.raises(RemoteHttpError):
        bootstrap.parse_sync_counts(
            '<li class="update_summary_new">4 Alben</li>'
            '<li class="update_summary_new">106 Fotos</li>'
            '<li class="update_summary_del">0 Alben</li>'
        )


def test_parse_sync_counts_needs_both_added_lines():
    """[BVA] One summary line is a page shape this scraper does not understand, and
    guessing the missing half would invent a number."""
    with pytest.raises(RemoteHttpError):
        bootstrap.parse_sync_counts('<li class="update_summary_new">4 Alben</li>')


# --- photoinfo rescan ---------------------------------------------------------------


def active_gallery(**kwargs):
    """Logged in, every fork plugin active: the state the sync leaves behind."""
    gallery = FakeGallery(
        BASE_URL,
        installed=True,
        plugin_states={name: "active" for name in FakeGallery.ALL_PLUGINS},
        **kwargs,
    )
    bootstrap.login(gallery, config())
    return gallery


def test_image_ids_lists_every_photo_the_gallery_holds(cfg):
    """[HAPPY] Every id, each once, whatever album it sits in."""
    gallery = active_gallery(photo_ids=[5, 3, 9])

    assert bootstrap.image_ids(gallery, cfg.site.base_url) == [3, 5, 9]


def test_image_ids_asks_for_the_whole_tree_in_a_stable_order(cfg):
    """[DT] No cat_id is every album; order=id keeps the pages from overlapping, which
    the configured default order does not promise."""
    gallery = active_gallery(photo_ids=[1])

    bootstrap.image_ids(gallery, cfg.site.base_url)

    asked = gallery.posts_to("ws.php")[-1]
    assert asked["method"] == "pwg.categories.getImages"
    assert asked["order"] == "id"
    assert "cat_id" not in asked


@pytest.mark.parametrize(
    ("photos", "pages"),
    [(0, 1), (1, 1), (bootstrap.IMAGE_PAGE_SIZE, 1), (bootstrap.IMAGE_PAGE_SIZE + 1, 2)],
)
def test_image_ids_asks_for_as_many_pages_as_the_total_needs(cfg, photos, pages):
    """[BVA] A full last page must not cost an empty extra request, one photo over must
    not be dropped."""
    gallery = active_gallery(photo_ids=list(range(1, photos + 1)))

    ids = bootstrap.image_ids(gallery, cfg.site.base_url)

    assert ids == list(range(1, photos + 1))
    assert gallery.methods_called().count("pwg.categories.getImages") == pages


def test_the_page_size_is_one_the_server_accepts():
    """[BVA] Within what ws.php serves; it clamps a larger per_page."""
    assert 0 < bootstrap.IMAGE_PAGE_SIZE <= FakeGallery.MAX_PER_PAGE


def test_parse_image_page_reads_the_ids_and_the_total():
    """[HAPPY]"""
    result = {"paging": {"total_count": 7}, "images": [{"id": 4}, {"id": 6}]}

    assert bootstrap.parse_image_page(result) == ([4, 6], 7)


@pytest.mark.parametrize(
    "result",
    [None, [], {"images": []}, {"paging": {}, "images": []}, {"paging": {"total_count": 1}}],
    ids=["none", "list", "no paging", "no total", "no images"],
)
def test_parse_image_page_refuses_a_shape_it_does_not_know(result):
    """[NEG] Read as "no photos", any of these would report a rescan of nothing as done."""
    with pytest.raises(RemoteHttpError):
        bootstrap.parse_image_page(result)


@pytest.mark.parametrize(
    ("count", "sizes"),
    [
        (0, []),
        (1, [1]),
        (bootstrap.RESCAN_CHUNK, [bootstrap.RESCAN_CHUNK]),
        (bootstrap.RESCAN_CHUNK + 1, [bootstrap.RESCAN_CHUNK, 1]),
    ],
)
def test_chunks_never_exceed_what_the_method_accepts(count, sizes):
    """[BVA] pwg.photoinfo.rescan refuses more than its chunk rather than truncating."""
    ids = list(range(1, count + 1))

    chunks = bootstrap.chunks(ids, bootstrap.RESCAN_CHUNK)

    assert [len(chunk) for chunk in chunks] == sizes
    assert [i for chunk in chunks for i in chunk] == ids


def test_the_chunk_is_the_one_the_server_accepts():
    """[BVA] Larger and every request is refused; the server is the authority."""
    assert bootstrap.RESCAN_CHUNK == FakeGallery.MAX_RESCAN_CHUNK


def test_parse_rescan_reads_failures_by_photo_id():
    """[HAPPY] json_encode turns the PHP id-keyed array into an object with string keys."""
    result = bootstrap.parse_rescan(
        {"scanned": 8, "failed": {"3": "gone", "12": "bad"}, "tags_added": 0, "not_in_file": []}
    )

    assert result == bootstrap.RescanResult(scanned=8, failed={3: "gone", 12: "bad"})


def test_parse_rescan_reads_an_empty_failure_list_as_no_failures():
    """[ECP] An empty PHP array encodes as a JSON list, not an object."""
    assert (
        bootstrap.parse_rescan(
            {"scanned": 10, "failed": [], "tags_added": 0, "not_in_file": []}
        ).failed
        == {}
    )


@pytest.mark.parametrize(
    "result",
    [
        None,
        {"failed": []},
        {"scanned": "10", "failed": []},
        {"scanned": 1},
        {"scanned": 1, "failed": [1]},
        {"scanned": 1, "failed": []},
        {"scanned": 1, "failed": [], "tags_added": "2", "not_in_file": []},
        {"scanned": 1, "failed": [], "tags_added": 0},
        {"scanned": 1, "failed": [], "tags_added": 0, "not_in_file": ["Zug"]},
        {"scanned": 1, "failed": [], "tags_added": 0, "not_in_file": {"3": "Zug"}},
    ],
    ids=[
        "none",
        "no count",
        "count as text",
        "no failed",
        "non-empty list",
        "no tag fields",
        "tags added as text",
        "no not_in_file",
        "not_in_file a non-empty list",
        "not_in_file names not a list",
    ],
)
def test_parse_rescan_refuses_a_shape_it_does_not_know(result):
    """[NEG] A misread answer would report photos as read that nothing read."""
    with pytest.raises(RemoteHttpError):
        bootstrap.parse_rescan(result)


def test_parse_rescan_reads_the_tags_added_and_the_names_by_photo_id():
    """[HAPPY] The tags the files added, and per photo the tags its file does not name."""
    result = bootstrap.parse_rescan(
        {"scanned": 2, "failed": [], "tags_added": 3, "not_in_file": {"7": ["Zug", "Kirmes"]}}
    )

    assert result.tags_added == 3
    assert result.not_in_file == {7: ["Zug", "Kirmes"]}


def test_parse_rescan_reads_an_empty_name_list_as_no_photos():
    """[BVA] An empty PHP array encodes as a JSON list, not an object."""
    result = bootstrap.parse_rescan({"scanned": 1, "failed": [], "tags_added": 0, "not_in_file": []})

    assert result.not_in_file == {}


def test_the_chunks_tag_results_are_merged(cfg):
    """[HAPPY] 23 photos in three chunks: the counts add up, the names stay per photo."""
    gallery = active_gallery(
        photo_ids=list(range(1, 24)),
        rescan_tags_added={2: 1, 15: 2, 23: 4},
        not_in_file={3: ["Zug"], 22: ["Kirmes"]},
    )

    result = bootstrap.rescan_photoinfo(gallery, cfg.site.base_url, FakeGallery.TOKEN)

    assert result.tags_added == 7
    assert result.not_in_file == {3: ["Zug"], 22: ["Kirmes"]}


def test_rescan_sends_every_photo_once_with_the_token(cfg):
    """[HAPPY] 23 photos is two full chunks and a partial one."""
    gallery = active_gallery(photo_ids=list(range(1, 24)))

    result = bootstrap.rescan_photoinfo(gallery, cfg.site.base_url, FakeGallery.TOKEN)

    assert sorted(gallery.rescanned) == list(range(1, 24))
    assert len(gallery.rescanned) == 23
    assert result == bootstrap.RescanResult(scanned=23, failed={})


def test_a_failed_photo_is_reported_and_the_others_still_read(cfg):
    """[ECP] One unreadable file must not cost the gallery its rescan."""
    gallery = active_gallery(
        photo_ids=list(range(1, 24)), rescan_failures={4: "gone", 17: "bad"}
    )

    result = bootstrap.rescan_photoinfo(gallery, cfg.site.base_url, FakeGallery.TOKEN)

    assert result.scanned == 21
    assert result.failed == {4: "gone", 17: "bad"}
    assert len(gallery.rescanned) == 23


def test_an_empty_gallery_sends_no_rescan_request(cfg):
    """[BVA] The method refuses an empty id list."""
    gallery = active_gallery(photo_ids=[])

    result = bootstrap.rescan_photoinfo(gallery, cfg.site.base_url, FakeGallery.TOKEN)

    assert result == bootstrap.RescanResult(scanned=0, failed={})
    assert "pwg.photoinfo.rescan" not in gallery.methods_called()


# --- the whole bootstrap ------------------------------------------------------------


def test_a_first_run_installs_activates_and_syncs(cfg, gallery, tmp_path):
    """[HAPPY][ST] The end state this phase exists for."""
    transport = FakeTransport()

    result = bootstrap.run(cfg, tmp_path, transport, gallery)

    assert result.installed is True
    assert result.config_uploaded is True
    assert result.plugins == {name: "activated" for name in bootstrap.PLUGINS_TO_ACTIVATE}
    assert result.sync.photos_added == 106


def test_a_second_run_installs_nothing_and_activates_nothing(cfg, gallery, tmp_path):
    """[ST] The idempotence criterion of the phase, asked of one server twice."""
    bootstrap.run(cfg, tmp_path, FakeTransport(), gallery)

    result = bootstrap.run(cfg, tmp_path, FakeTransport(), gallery)

    assert result.installed is False
    assert result.config_uploaded is False
    assert result.plugins == {name: "active" for name in bootstrap.PLUGINS_TO_ACTIVATE}
    assert result.sync.photos_added == 106


def test_the_config_is_uploaded_after_the_install(cfg, gallery, tmp_path):
    """[ST] install.php writes database.inc.php into the same directory; ordering the two
    settles which run creates local/config/."""
    transport = FakeTransport()

    class Ordered(FakeTransport):
        def put(self, local, remote_path):
            self.order.append(("put", gallery.installed))
            super().put(local, remote_path)

    ordered = Ordered()
    ordered.order = []
    bootstrap.run(cfg, tmp_path, ordered, gallery)

    assert ordered.order  # anti-vacuity: a put happened at all
    assert all(installed for _, installed in ordered.order)
    assert transport.paths("put") == []


def test_the_rescan_follows_the_sync(cfg, gallery, tmp_path):
    """[ST] The sync registers the photos the rescan reads, and the rescan is the only
    request allowed after it."""
    bootstrap.run(cfg, tmp_path, FakeTransport(), gallery)

    urls = gallery.urls()
    last_sync = max(i for i, url in enumerate(urls) if "site_update" in url)
    after = gallery.calls[last_sync + 1 :]
    assert after  # anti-vacuity: something follows the sync at all
    assert {call[2]["method"] for call in after} == {
        "pwg.categories.getImages",
        "pwg.photoinfo.rescan",
    }


def test_a_run_rescans_every_photo_the_sync_registered(cfg, gallery, tmp_path):
    """[HAPPY] The remote's dates and info texts come from the files, never the database."""
    result = bootstrap.run(cfg, tmp_path, FakeTransport(), gallery)

    assert len(gallery.rescanned) == gallery.photos_added
    assert result.rescan == bootstrap.RescanResult(scanned=gallery.photos_added, failed={})


def test_a_run_without_photoinfo_does_not_rescan(cfg, gallery, tmp_path, monkeypatch):
    """[DT] The method exists only while photoinfo is active."""
    monkeypatch.setattr(
        bootstrap,
        "PLUGINS_TO_ACTIVATE",
        tuple(name for name in bootstrap.PLUGINS_TO_ACTIVATE if name != "photoinfo"),
    )

    result = bootstrap.run(cfg, tmp_path, FakeTransport(), gallery)

    assert result.rescan is None
    assert "pwg.photoinfo.rescan" not in gallery.methods_called()
    assert gallery.plugin_states["provenance"] == "active"  # anti-vacuity: the run ran


# --- pruning tags the files do not name ---------------------------------------------


def test_a_run_without_prune_tags_prunes_nothing(cfg, tmp_path):
    """[DT] Removing tags is the operator's call: never without the flag."""
    gallery = FakeGallery(not_in_file={3: ["Zug"]})

    result = bootstrap.run(cfg, tmp_path, FakeTransport(), gallery)

    assert result.rescan.not_in_file == {3: ["Zug"]}  # anti-vacuity: there was something to prune
    assert result.prune is None
    assert "pwg.photoinfo.pruneTags" not in gallery.methods_called()


def test_prune_tags_prunes_only_the_photos_the_rescan_named(cfg, tmp_path):
    """[DT] Photos without such tags are not asked about, and the chunks hold."""
    named = {i: ["Zug"] for i in (2, 5, 8, 11, 14, 17, 20, 23, 26, 29, 32)}
    gallery = FakeGallery(photo_ids=list(range(1, 41)), photos_added=40, not_in_file=named)

    result = bootstrap.run(cfg, tmp_path, FakeTransport(), gallery, prune_tags=True)

    assert sorted(gallery.pruned) == sorted(named)
    prune_calls = [f for f in gallery.posts_to("ws.php") if f.get("method") == "pwg.photoinfo.pruneTags"]
    assert all(len(f["image_ids"].split(",")) <= FakeGallery.MAX_RESCAN_CHUNK for f in prune_calls)
    assert len(prune_calls) == 2
    assert result.prune == bootstrap.PruneResult(removed=named, failed={}, error=None)


def test_prune_tags_with_nothing_to_prune_sends_no_request(cfg, gallery, tmp_path):
    """[BVA] Every file names every tag: nothing is asked."""
    result = bootstrap.run(cfg, tmp_path, FakeTransport(), gallery, prune_tags=True)

    assert result.prune == bootstrap.PruneResult(removed={}, failed={}, error=None)
    assert "pwg.photoinfo.pruneTags" not in gallery.methods_called()


def test_a_failed_prune_does_not_fail_the_run(cfg, tmp_path):
    """[NEG] Upload, install and rescan are done; a prune is repeated by the next run."""
    gallery = FakeGallery(not_in_file={3: ["Zug"]}, prune_error="database gone")

    result = bootstrap.run(cfg, tmp_path, FakeTransport(), gallery, prune_tags=True)

    assert result.prune.removed == {}
    assert "database gone" in result.prune.error
    assert result.rescan is not None  # anti-vacuity: the run got that far


def test_a_prune_that_times_out_does_not_fail_the_run(cfg, tmp_path):
    """[NEG] A read timeout is no RemoteHttpError, and must not end a finished deploy either."""
    gallery = FakeGallery(not_in_file={3: ["Zug"]}, prune_raises=TimeoutError("timed out"))

    result = bootstrap.run(cfg, tmp_path, FakeTransport(), gallery, prune_tags=True)

    assert result.prune.removed == {}
    assert "timed out" in result.prune.error


def test_a_photo_whose_file_cannot_be_read_is_reported_and_the_others_pruned(cfg, tmp_path):
    """[ECP] One unreadable file must not cost the others their prune."""
    gallery = FakeGallery(not_in_file={3: ["Zug"], 4: ["Anna"]}, prune_failures={4: "gone"})

    result = bootstrap.run(cfg, tmp_path, FakeTransport(), gallery, prune_tags=True)

    assert result.prune == bootstrap.PruneResult(removed={3: ["Zug"]}, failed={4: "gone"}, error=None)


@pytest.mark.parametrize(
    "answer",
    [None, {"removed": []}, {"removed": {"3": "Zug"}, "failed": []}, {"removed": [], "failed": [1]}],
    ids=["none", "no failed", "names not a list", "non-empty failed list"],
)
def test_parse_prune_refuses_a_shape_it_does_not_know(answer):
    """[NEG] A misread answer would report tags as removed that nothing removed."""
    with pytest.raises(RemoteHttpError):
        bootstrap.parse_prune(answer)


# --- tag groups ---------------------------------------------------------------------

SEED_METHODS = {"typetags.type.list", "typetags.type.add", "pwg.tags.getAdminList"}


def test_the_seed_runs_after_activation_and_before_the_sync(cfg, gallery, tmp_path):
    """[ST] typetags' methods exist only once it is active, and the photos the sync
    registers should find their groups already there."""
    bootstrap.run(cfg, tmp_path, FakeTransport(), gallery)

    methods = [call[2].get("method") for call in gallery.calls]
    urls = gallery.urls()
    first_seed = min(i for i, m in enumerate(methods) if m in SEED_METHODS)
    last_activation = max(i for i, m in enumerate(methods) if m == "pwg.plugins.performAction")
    first_sync = min(i for i, url in enumerate(urls) if "site_update" in url)
    assert last_activation < first_seed < first_sync


def test_a_run_seeds_the_committed_tag_groups(cfg, gallery, tmp_path):
    """[HAPPY] An empty remote gets every group and tag the file names."""
    wanted = seed.load_tag_groups()

    result = bootstrap.run(cfg, tmp_path, FakeTransport(), gallery)

    assert result.seed.groups_added == len(wanted.groups) >= 1
    assert result.seed.tags_added == len(wanted.tags) >= 1
    assert {g["name"] for g in gallery.tag_groups} == {g.name for g in wanted.groups}


def test_a_second_run_seeds_nothing(cfg, gallery, tmp_path):
    """[ST]"""
    bootstrap.run(cfg, tmp_path, FakeTransport(), gallery)

    result = bootstrap.run(cfg, tmp_path, FakeTransport(), gallery)

    assert result.seed.groups_checked >= 1  # anti-vacuity: the seed ran
    assert (result.seed.groups_added, result.seed.groups_changed) == (0, 0)
    assert (result.seed.tags_added, result.seed.tags_regrouped) == (0, 0)


def test_a_run_without_typetags_does_not_seed(cfg, gallery, tmp_path, monkeypatch):
    """[NEG] The group methods exist only while typetags is active."""
    monkeypatch.setattr(
        bootstrap,
        "PLUGINS_TO_ACTIVATE",
        tuple(name for name in bootstrap.PLUGINS_TO_ACTIVATE if name != "typetags"),
    )

    result = bootstrap.run(cfg, tmp_path, FakeTransport(), gallery)

    assert result.seed is None
    assert not SEED_METHODS & set(gallery.methods_called())
    assert gallery.plugin_states["provenance"] == "active"  # anti-vacuity: the run ran
