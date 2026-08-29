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
 *   php tests/e2e/support/seed.php --restore
 *
 * Both forms print one JSON object on stdout. Errors go to stderr with exit 1.
 *
 * This mutates the database. It is not safe against a production install.
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

const SNAPSHOT_FILE = __DIR__ . '/../.state/snapshot.json';

/** The values 'with-provenance' writes. A spec reads them back off this script's output. */
const SEEDED_VALUES = array(
    'provenance_physical_album' => 'Oma Müllers Fotoalbum',
    'provenance_owner'          => 'Anna Müller',
    'provenance_scanned_on'     => '2026-08-29',
    'provenance_note'           => 'geliehen im August',
    );

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

function load_snapshot(): ?array
{
    if (!file_exists(SNAPSHOT_FILE))
    {
        return null;
    }

    $decoded = json_decode(file_get_contents(SNAPSHOT_FILE), true);
    if (!is_array($decoded))
    {
        fail('snapshot file is not valid JSON: ' . SNAPSHOT_FILE);
    }
    return $decoded;
}

function save_snapshot(array $state): void
{
    $dir = dirname(SNAPSHOT_FILE);
    if (!is_dir($dir) and !mkdir($dir, 0777, true) and !is_dir($dir))
    {
        fail("could not create snapshot directory $dir");
    }
    file_put_contents(SNAPSHOT_FILE, json_encode($state, JSON_PRETTY_PRINT));
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

    $builder->importState($snapshot);
    $builder->restore();
    unlink(SNAPSHOT_FILE);

    echo json_encode(array('restored' => true)), "\n";
    exit(0);
}

// ── Seed ──────────────────────────────────────────────────────────────────

$scenario = $args['scenario'] ?? null;
if (!in_array($scenario, array('no-provenance', 'with-provenance'), true))
{
    fail('--scenario must be one of: no-provenance, with-provenance');
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
    $builder->importState($existing);
}
else
{
    $builder->recordAllProvenance();
}

$wanted = $scenario === 'with-provenance' ? SEEDED_VALUES : array();
$actual = $builder->albumProvenance($catId, $wanted);

save_snapshot($builder->exportState());

echo json_encode(array(
    'scenario' => $scenario,
    'album_id' => $catId,
    'values' => $actual,
    'max_short_text_chars' => PROVENANCE_SHORT_TEXT_MAX_CHARS,
    ), JSON_UNESCAPED_UNICODE), "\n";
