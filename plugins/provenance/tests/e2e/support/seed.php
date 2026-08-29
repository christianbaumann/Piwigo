<?php
/**
 * Scenario seeding CLI for the provenance E2E suite.
 *
 * Setup-before, not cleanup-after: each scenario forces one album's provenance
 * columns, FixtureBuilder asserts the write took effect, and this script prints
 * the state it achieved so a spec asserts against what is really there.
 *
 * PHPUnit fixtures live and die inside one process. Playwright seeds from a
 * separate short-lived process, so the original state is exported to a snapshot
 * file and re-imported by --restore, which then delegates to the same
 * FixtureBuilder::restore() the integration suite uses.
 *
 * Usage:
 *   php tests/e2e/support/seed.php --scenario=no-provenance
 *   php tests/e2e/support/seed.php --scenario=with-provenance --album=12
 *   php tests/e2e/support/seed.php --scenario=photo-provenance
 *   php tests/e2e/support/seed.php --scenario=writeback
 *   php tests/e2e/support/seed.php --scenario=move
 *   php tests/e2e/support/seed.php --restore
 *
 * Both forms print one JSON object on stdout. Errors go to stderr with exit 1.
 *
 * This mutates the database. It is not safe against a production install.
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

const SNAPSHOT_FILE = __DIR__ . '/../.state/snapshot.json';

/** The audit trail. Named here rather than read from the plugin constant, which needs Piwigo booted. */
const HISTORY_TABLE = 'piwigo_provenance_history';

/** The l10n key the public row's <dt> carries. */
const PROVENANCE_ROW_LABEL_KEY = 'Provenance';

function history_high_water(Db $db): int
{
    return (int)$db->scalar('SELECT COALESCE(MAX(id), 0) FROM ' . HISTORY_TABLE);
}

/**
 * The row's label as the browsing account will really see it.
 *
 * Resolved from the account's own language rather than assumed: the install
 * runs in German, so a spec asserting the English key would pass only by
 * accident of l10n() echoing back an untranslated key. The language file is
 * included rather than parsed - it assigns $lang and does nothing else.
 */
function row_label(Db $db, string $username): string
{
    $locale = $db->scalar(
        "SELECT ui.language FROM piwigo_user_infos ui" .
        " JOIN piwigo_users u ON u.id = ui.user_id" .
        " WHERE u.username = '" . $db->escape($username) . "'"
    );
    if ($locale === null)
    {
        fail("no account named '$username' to resolve the row label for");
    }

    $file = dirname(__DIR__, 3) . '/language/' . $locale . '/plugin.lang.php';
    if (!is_file($file))
    {
        fail("the plugin carries no translation for $locale: $file");
    }

    $lang = array();
    include $file;

    if (empty($lang[PROVENANCE_ROW_LABEL_KEY]))
    {
        fail('the ' . $locale . ' translation has no ' . PROVENANCE_ROW_LABEL_KEY . ' entry');
    }

    return $lang[PROVENANCE_ROW_LABEL_KEY];
}

/**
 * The move prompt's title and its per-mode labels, in the language the given
 * account browses in.
 *
 * Both halves are read out of production rather than typed here: the strings and
 * their mode come from the template that renders them, the translations from the
 * locale file the request would use. A spec carrying its own copy of either
 * would assert this install's language, and would go stale the day only one of
 * the two copies is edited.
 *
 * @return array title plus one entry per mode value
 */
function move_mode_labels(Db $db, string $username): array
{
    $locale = user_locale($db, $username);
    $lang = plugin_lang($locale);

    $tpl = (string)file_get_contents(PROVENANCE_PATH . 'template/batch_move_provenance.tpl');
    if (strlen($tpl) < 200)
    {
        fail('the move-prompt template is too short to have been read');
    }

    $labels = array();

    if (!preg_match("/provenance-move-mode-title\">\{'([^']+)'\|\@translate\}/", $tpl, $m))
    {
        fail('could not find the move-prompt title in its template');
    }
    $labels['title'] = translate($lang, $m[1], $locale);

    if (!preg_match_all("/value=\"([a-z]+)\"[^>]*>\s*\{'((?:[^'\\\\]|\\\\.)+)'\|\@translate\}/", $tpl, $ms, PREG_SET_ORDER))
    {
        fail('could not find the move-mode radios in their template');
    }
    foreach ($ms as $match)
    {
        $labels[$match[1]] = translate($lang, str_replace("\\'", "'", $match[2]), $locale);
    }

    if (count($labels) !== count(provenance_move_modes()) + 1)
    {
        fail('the template does not offer one labelled radio per move mode');
    }

    return $labels;
}

function user_locale(Db $db, string $username): string
{
    $locale = $db->scalar(
        "SELECT ui.language FROM piwigo_user_infos ui" .
        " JOIN piwigo_users u ON u.id = ui.user_id" .
        " WHERE u.username = '" . $db->escape($username) . "'"
    );
    if ($locale === null)
    {
        fail("no account named '$username' to resolve labels for");
    }

    return (string)$locale;
}

function plugin_lang(string $locale): array
{
    $file = dirname(__DIR__, 3) . '/language/' . $locale . '/plugin.lang.php';
    if (!is_file($file))
    {
        fail("the plugin carries no translation for $locale: $file");
    }

    $lang = array();
    include $file;

    return $lang;
}

function translate(array $lang, string $key, string $locale): string
{
    if (empty($lang[$key]))
    {
        fail("the $locale translation has no '$key' entry");
    }

    return $lang[$key];
}

function read_display_info(Db $db): string
{
    $value = $db->scalar(
        "SELECT value FROM piwigo_config WHERE param = '" . PROVENANCE_DISPLAY_INFO_PARAM . "'"
    );
    if ($value === null)
    {
        fail('this install has no ' . PROVENANCE_DISPLAY_INFO_PARAM . ' for the public row to hang off');
    }
    return (string)$value;
}

function write_display_info(Db $db, string $serialized): void
{
    $db->query(
        "UPDATE piwigo_config SET value = '" . $db->escape($serialized) .
        "' WHERE param = '" . PROVENANCE_DISPLAY_INFO_PARAM . "'"
    );
}

/**
 * Forces the public row visible and asserts it took effect.
 *
 * The key is seeded by the plugin's install(), which an install predating the
 * public row has never run - so a spec pointed at such a database would find no
 * row and report the feature broken. Setup-before, not hope.
 */
function force_display_info(Db $db): void
{
    $map = unserialize(read_display_info($db));
    if (!is_array($map))
    {
        fail(PROVENANCE_DISPLAY_INFO_PARAM . ' is not a serialized array');
    }

    $map[PROVENANCE_DISPLAY_INFO_KEY] = true;
    write_display_info($db, serialize($map));

    $actual = unserialize(read_display_info($db));
    if (empty($actual[PROVENANCE_DISPLAY_INFO_KEY]))
    {
        fail('could not switch the public provenance row on');
    }
}

/** The values 'with-provenance' writes. A spec reads them back off this script's output. */
const SEEDED_VALUES = array(
    'provenance_physical_album' => 'Oma Müllers Fotoalbum',
    'provenance_owner'          => 'Anna Müller',
    'provenance_scanned_on'     => '2026-08-29',
    'provenance_note'           => 'geliehen im August',
    );

/**
 * The photo's own note 'photo-provenance' writes.
 *
 * Deliberately unlike any album value: a spec asserting the photo screen must
 * be able to tell the photo's own note apart from the album's, which is the
 * whole reason the schema carries two note columns.
 */
const SEEDED_PHOTO_NOTE = 'auf der Rückseite: Sommer 1972';

/**
 * The destination album's values for the 'move' scenario.
 *
 * Every field differs from SEEDED_VALUES, because the whole question the move
 * prompt answers is *which album's* values a moved photo ends up with. A
 * destination sharing even one value with the source would let a spec pass
 * while the choice was ignored.
 */
const MOVE_DESTINATION_VALUES = array(
    'provenance_physical_album' => 'Opa Schmidts Fotokiste',
    'provenance_owner'          => 'Berta Schmidt',
    'provenance_scanned_on'     => '2026-07-01',
    'provenance_note'           => 'Kiste vom Dachboden',
    );

/**
 * How many photos the 'writeback' scenario puts in its own album.
 *
 * Four, because the client halves the album and caps the chunk: four photos are
 * sent as two requests, which is what makes the chunking assertion mean
 * something. More would only make the run slower - each photo is a real
 * exiftool invocation.
 */
const WRITEBACK_PHOTO_COUNT = 4;

function fail(string $message): never
{
    fwrite(STDERR, "seed.php: $message\n");
    exit(1);
}

function parse_args(array $argv): array
{
    $args = array();
    foreach (array_slice($argv, 1) as $arg)
    {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m))
        {
            $args[$m[1]] = $m[2] ?? true;
        }
        else
        {
            fail("unrecognised argument '$arg'");
        }
    }
    return $args;
}

/**
 * The snapshot carries the recorded values *and* the album whose photos a seed
 * emptied. A photo that held no provenance before is not in the recorded state
 * at all - readAll() only remembers rows worth putting back - so without the
 * album id, an apply run by a spec would leave its values behind for good.
 */
function load_snapshot(): ?array
{
    if (!file_exists(SNAPSHOT_FILE))
    {
        return null;
    }

    $decoded = json_decode(file_get_contents(SNAPSHOT_FILE), true);
    if (!is_array($decoded) or !isset($decoded['state']))
    {
        fail('snapshot file is not valid JSON: ' . SNAPSHOT_FILE);
    }
    return $decoded;
}

function save_snapshot(array $snapshot): void
{
    $dir = dirname(SNAPSHOT_FILE);
    if (!is_dir($dir) and !mkdir($dir, 0777, true) and !is_dir($dir))
    {
        fail("could not create snapshot directory $dir");
    }
    file_put_contents(SNAPSHOT_FILE, json_encode($snapshot, JSON_PRETTY_PRINT));
}

$args = parse_args($argv);
$db = new Db();
$builder = new FixtureBuilder($db);

// ── Restore ───────────────────────────────────────────────────────────────

if (isset($args['restore']))
{
    $snapshot = load_snapshot();
    if ($snapshot === null)
    {
        // Nothing was seeded, so there is nothing to put back. Not an error:
        // afterEach runs even when beforeEach failed before it seeded.
        echo json_encode(array('restored' => false, 'reason' => 'no snapshot')), "\n";
        exit(0);
    }

    // Clear first, restore second. A row that carried no provenance before is
    // not in the recorded state at all - readAll() only remembers rows worth
    // putting back - so restore() alone would leave a seeded album and every
    // applied photo behind for good.
    // An album this suite created holds nothing but copies, so it is deleted
    // outright - files, image rows, links and the album - rather than reset.
    $builder->importTestObjects($snapshot['test_objects'] ?? array());
    $createdAlbums = !empty(($snapshot['test_objects'] ?? array())['albums']);
    $builder->destroyTestImages();
    $builder->destroyTestAlbums();

    $albumId = (int)$snapshot['album_id'];
    if (!$createdAlbums)
    {
        $builder->albumProvenance($albumId, array());
        $builder->clearImageProvenance($builder->photoIdsInAlbum($albumId));
    }

    // History rows are append-only, so a spec that saved or applied leaves a
    // trail behind. Without this the E2E suite would quietly poison every later
    // run of the integration suite, which reads the same table.
    $db->query('DELETE FROM ' . HISTORY_TABLE . ' WHERE id > ' . (int)$snapshot['history_id']);
    if (isset($snapshot['display_info']))
    {
        write_display_info($db, (string)$snapshot['display_info']);
    }
    $builder->importState($snapshot['state']);
    $builder->restore();
    unlink(SNAPSHOT_FILE);

    echo json_encode(array('restored' => true)), "\n";
    exit(0);
}

// ── Read back ─────────────────────────────────────────────────────────────

/*
 * What one photo's provenance columns hold right now.
 *
 * The move spec needs the *outcome* of a real move, and the browser cannot show
 * it: whether a moved photo kept, cleared or replaced its provenance is a fact
 * about five database columns, and the picture page composes them into one
 * sentence that cannot distinguish a cleared field from an absent one. Same
 * reasoning as the write-back spec reading the files on disk instead of
 * trusting the page's own summary.
 */
if (isset($args['read-photo']))
{
    $photoId = (int)$args['read-photo'];
    if ($photoId <= 0)
    {
        fail('--read-photo must be a positive photo id');
    }

    echo json_encode($builder->readImageProvenance($photoId), JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

// ── Seed ──────────────────────────────────────────────────────────────────

$scenario = $args['scenario'] ?? null;
if (!in_array($scenario, array('no-provenance', 'with-provenance', 'photo-provenance', 'writeback', 'move'), true))
{
    fail('--scenario must be one of: no-provenance, with-provenance, photo-provenance, writeback, move');
}

// Carry forward anything an earlier seed in this test already recorded, so a
// second seed does not overwrite the first one's memory of the original state.
$existing = load_snapshot();
if ($existing !== null)
{
    $builder->importState($existing['state']);
    $builder->importTestObjects($existing['test_objects'] ?? array());
}
else
{
    $builder->recordAllProvenance();
}

// Carried forward like the recorded provenance is: a second seed in the same
// test must not remember the state the first one already changed.
$originalDisplayInfo = $existing['display_info'] ?? read_display_info($db);
force_display_info($db);

$destination = null;
if ($scenario === 'writeback')
{
    // The write-back writes every photo of the album it is started from, so it
    // is never pointed at an album holding real scans. This album holds copies
    // and nothing else, and --restore deletes it whole.
    $catId = $builder->createTestAlbum('provenance E2E write-back');

    for ($i = 0; $i < WRITEBACK_PHOTO_COUNT; $i++)
    {
        $builder->attachImage($builder->createTestImage()['id'], $catId);
    }
}
elseif ($scenario === 'move')
{
    // Two throwaway albums, because a move needs somewhere to come from and
    // somewhere to go, and a spec that moved a real scan out of a real album
    // would leave the collection rearranged. One photo, so "the moved photo"
    // is unambiguous and the batch manager's select-all is exact.
    $catId = $builder->createTestAlbum('provenance E2E move source');
    $destinationId = $builder->createTestAlbum('provenance E2E move destination');

    $builder->attachImage($builder->createTestImage()['id'], $catId);

    $destination = array(
        'album_id' => $destinationId,
        'album_name' => 'provenance E2E move destination',
        'values' => $builder->albumProvenance($destinationId, MOVE_DESTINATION_VALUES),
        );
}
else
{
    $catId = isset($args['album']) ? (int)$args['album'] : $builder->anyAlbumId();
}

if ($catId <= 0)
{
    fail('--album must be a positive album id');
}

$wanted = $scenario === 'no-provenance' ? array() : SEEDED_VALUES;
$actual = $builder->albumProvenance($catId, $wanted);

// photoIdsInAlbum() asserts the 1:1 photo-album assumption the copy-down rests
// on, so a spec can never apply over a photo that belongs to two albums.
$photo_ids = $builder->photoIdsInAlbum($catId);
$builder->clearImageProvenance($photo_ids);

// 'writeback' puts every photo in the state the copy-down would leave it in, so
// a spec of the write-back button is not also a test of the apply.
if ($scenario === 'writeback')
{
    foreach ($photo_ids as $photo_id)
    {
        $copied = array('provenance_note' => SEEDED_PHOTO_NOTE);
        foreach (provenance_copy_down_map() as $album_column => $image_column)
        {
            $copied[$image_column] = $actual[$album_column];
        }
        $builder->imageProvenance($photo_id, $copied);
    }
}

// 'move' puts its one photo in the state the copy-down would leave it in, so the
// spec starts from a photo that really carries the *source* album's provenance -
// otherwise 'keep' and 'clear' would be indistinguishable and 'replace' would
// have nothing to replace.
if ($scenario === 'move')
{
    $copied = array();
    foreach (provenance_copy_down_map() as $album_column => $image_column)
    {
        $copied[$image_column] = $actual[$album_column];
    }
    foreach ($photo_ids as $photo_id)
    {
        $builder->imageProvenance($photo_id, $copied);
    }
}

// 'photo-provenance' puts one photo in the state the copy-down would leave it
// in - the four album-sourced values plus a note of its own - without running
// the apply, so a photo-screen spec is not also a test of the apply.
$photo = null;
if ($scenario === 'photo-provenance')
{
    $copied = array('provenance_note' => SEEDED_PHOTO_NOTE);
    foreach (provenance_copy_down_map() as $album_column => $image_column)
    {
        $copied[$image_column] = $actual[$album_column];
    }
    $photo = array(
        'id' => $photo_ids[0],
        'values' => $builder->imageProvenance($photo_ids[0], $copied),
        );
}

save_snapshot(array(
    'state' => $builder->exportState(),
    'test_objects' => $builder->exportTestObjects(),
    'album_id' => $catId,
    'display_info' => $originalDisplayInfo,
    // Carried forward, so a second seed in the same test does not move the mark
    // past rows the first one's spec already wrote.
    'history_id' => $existing['history_id'] ?? history_high_water($db),
    ));

echo json_encode(array(
    'scenario' => $scenario,
    'album_id' => $catId,
    'values' => $actual,
    'photo_ids' => $photo_ids,
    'photo_count' => count($photo_ids),
    'photo' => $photo,
    'photo_note' => $scenario === 'writeback' ? SEEDED_PHOTO_NOTE : null,
    // The 'move' scenario's second album: where the photo is moved to, and the
    // values 'replace' must leave on it.
    'destination' => $destination,
    // Absolute paths, so a spec can read back what the browser's click really
    // wrote into the files rather than trusting the page's own summary.
    'photo_files' => array_column($builder->exportTestObjects()['images'], 'file'),
    'row_label' => row_label($db, Config::username()),
    'move_mode_labels' => move_mode_labels($db, Config::username()),
    'max_short_text_chars' => PROVENANCE_SHORT_TEXT_MAX_CHARS,
    'writeback_max_chunk' => PROVENANCE_WRITEBACK_MAX_CHUNK,
    ), JSON_UNESCAPED_UNICODE), "\n";
