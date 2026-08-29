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

function history_high_water(Db $db): int
{
    return (int)$db->scalar('SELECT COALESCE(MAX(id), 0) FROM ' . HISTORY_TABLE);
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
    $albumId = (int)$snapshot['album_id'];
    $builder->albumProvenance($albumId, array());
    $builder->clearImageProvenance($builder->photoIdsInAlbum($albumId));

    // History rows are append-only, so a spec that saved or applied leaves a
    // trail behind. Without this the E2E suite would quietly poison every later
    // run of the integration suite, which reads the same table.
    $db->query('DELETE FROM ' . HISTORY_TABLE . ' WHERE id > ' . (int)$snapshot['history_id']);
    $builder->importState($snapshot['state']);
    $builder->restore();
    unlink(SNAPSHOT_FILE);

    echo json_encode(array('restored' => true)), "\n";
    exit(0);
}

// ── Seed ──────────────────────────────────────────────────────────────────

$scenario = $args['scenario'] ?? null;
if (!in_array($scenario, array('no-provenance', 'with-provenance', 'photo-provenance'), true))
{
    fail('--scenario must be one of: no-provenance, with-provenance, photo-provenance');
}

$catId = isset($args['album']) ? (int)$args['album'] : $builder->anyAlbumId();
if ($catId <= 0)
{
    fail('--album must be a positive album id');
}

// Carry forward anything an earlier seed in this test already recorded, so a
// second seed does not overwrite the first one's memory of the original state.
$existing = load_snapshot();
if ($existing !== null)
{
    $builder->importState($existing['state']);
}
else
{
    $builder->recordAllProvenance();
}

$wanted = $scenario === 'no-provenance' ? array() : SEEDED_VALUES;
$actual = $builder->albumProvenance($catId, $wanted);

// photoIdsInAlbum() asserts the 1:1 photo-album assumption the copy-down rests
// on, so a spec can never apply over a photo that belongs to two albums.
$photo_ids = $builder->photoIdsInAlbum($catId);
$builder->clearImageProvenance($photo_ids);

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
    'album_id' => $catId,
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
    'max_short_text_chars' => PROVENANCE_SHORT_TEXT_MAX_CHARS,
    ), JSON_UNESCAPED_UNICODE), "\n";
