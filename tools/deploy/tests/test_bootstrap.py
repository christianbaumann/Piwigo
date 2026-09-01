"""Install, config, session, plugins, sync — and the claim that a second run is a no-op.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA] [ST] [DT] [ERR].
Boundary values apply only to the scrapers (zero counts, no summary at all); the rest of
the module has no numeric domain.

Everything runs against `FakeGallery`, which keeps server-side state, so idempotence is
asked of a server rather than asserted about a script. What these tests cannot witness is
a real `install.php` — that is Phase 6's manual step and the hand-check ledger's entry.
"""

import pytest

from pwgdeploy import bootstrap, manifest
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
MIN_PLUGINS = 3
# Anti-vacuity for the generated PHP: an empty string contains every substring asserted
# of it exactly zero times, and `in` on "" would still be checked below.
MIN_CONFIG_BYTES = 40


def config(**overrides):
    raw = {section: dict(values) for section, values in RAW.items()}
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


def test_activation_installs_all_three_fork_plugins(cfg, gallery):
    """[HAPPY] Decision 6: activate falls through to install
    (admin/include/plugins.class.php:187-219), which is what creates each schema."""
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
        plugin_states={"typetags": "active", "provenance": "inactive", "persons": "uninstalled"},
    )
    token = bootstrap.login(gallery, cfg)

    outcome = bootstrap.activate_plugins(gallery, cfg.site.base_url, token)

    assert outcome == {"typetags": "active", "provenance": "activated", "persons": "activated"}
    assert sorted(call["plugin"] for call in gallery.posts_to("ws.php") if call.get("action") == "activate") == [
        "persons",
        "provenance",
    ]


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


def test_the_sync_runs_last(cfg, gallery, tmp_path):
    """[ST] It scans the galleries/ tree the upload placed, so nothing may follow it."""
    bootstrap.run(cfg, tmp_path, FakeTransport(), gallery)

    assert "site_update" in gallery.urls()[-1]
