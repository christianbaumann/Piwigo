<?php
// +-----------------------------------------------------------------------+
// | Piwigo - a PHP based photo gallery                                    |
// +-----------------------------------------------------------------------+
// | This file is part of a fork-local handbook toolchain and is not part   |
// | of upstream Piwigo.                                                    |
// +-----------------------------------------------------------------------+

/**
 * Mechanical checks over handbuch/.
 *
 * Handbook prose has no automated oracle. What a machine can decide is
 * checked here and nothing else; the German wording and whether the
 * instructions actually work stay in the hand-check ledger of
 * docs/agents/TESTING.md.
 *
 *   ddev exec php handbuch/tools/check.php
 *
 * Exits 0 when every check passes, 1 on the first failure, naming the page,
 * the reference and the file it did not resolve to.
 */

const HANDBUCH_DIR = __DIR__ . '/..';
const SHOT_DIR = HANDBUCH_DIR . '/assets/screenshots';
const ROOT = __DIR__ . '/../..';

/**
 * Anti-vacuity floors.
 *
 * A checker that reads nothing reports no problem, which is the failure mode
 * these guards exist to make loud. The numbers are lower bounds on what the
 * handbook must contain, not a count of what it happens to contain today.
 */
const MIN_PAGES = 6;
const MIN_REFERENCES = 20;
const MIN_ADMIN_URLS = 5;
const MIN_BYTES_SCANNED = 20000;

/**
 * Characters the house style forbids in documentation.
 *
 * Em- and en-dashes and emoji are the tell of unedited generated prose. The
 * rule is in the project instructions; this is the half of it a machine can
 * decide. Whether the wording reads naturally stays in the hand-check ledger.
 */
const FORBIDDEN_CHARACTERS = array(
    "\xE2\x80\x94" => 'em-dash',
    "\xE2\x80\x93" => 'en-dash',
    );

$failures = array();

function fail($message)
{
    global $failures;
    $failures[] = $message;
}

// ------------------------------------------------------------------- pages

$pages = glob(HANDBUCH_DIR . '/*.html');
sort($pages);

if (count($pages) < MIN_PAGES)
{
    fail('only ' . count($pages) . ' HTML pages found in handbuch/, expected at least ' . MIN_PAGES);
}

$referencedShots = array();
$references = 0;
$adminUrls = 0;
$bytesScanned = 0;

foreach ($pages as $page)
{
    $name = basename($page);
    $html = file_get_contents($page);

    if ($html === false or strlen($html) === 0)
    {
        fail("$name is empty");
        continue;
    }

    $bytesScanned += strlen($html);

    foreach (FORBIDDEN_CHARACTERS as $character => $label)
    {
        if (strpos($html, $character) !== false)
        {
            fail("$name contains " . substr_count($html, $character) . " $label(s)");
        }
    }

    foreach (emojiIn($html) as $emoji)
    {
        fail("$name contains the emoji $emoji");
    }

    // Well-formedness, checked as XML rather than as HTML. PHP's HTML parser
    // repairs an unclosed tag silently and reports nothing, so it cannot
    // witness the realistic defect in a hand-written page. The pages are
    // therefore written so that they are valid HTML5 and well-formed XML at
    // once: void elements are self-closed and no named entity beyond the five
    // XML predefines is used.
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $dom = new DOMDocument();
    $parsed = $dom->loadXML($html);
    foreach (libxml_get_errors() as $error)
    {
        fail("$name is not well formed at line " . $error->line . ': ' . trim($error->message));
    }
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$parsed)
    {
        continue;
    }

    // Every src and href resolves.
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//*[@src] | //*[@href]') as $node)
    {
        $target = $node->hasAttribute('src') ? $node->getAttribute('src') : $node->getAttribute('href');

        if ($target === '' or $target[0] === '#' or preg_match('#^(https?:|mailto:)#', $target))
        {
            continue;
        }

        $references++;
        $path = HANDBUCH_DIR . '/' . preg_replace('/[?#].*$/', '', $target);

        if (!file_exists($path))
        {
            fail("$name references $target, which does not exist");
            continue;
        }

        if (strpos(realpath($path), realpath(SHOT_DIR)) === 0)
        {
            $referencedShots[basename($path)] = true;
        }
    }

    // Every admin.php?page= route quoted in the text is a real route. Read from
    // the rendered text rather than the markup, so the tag that closes a <code>
    // element is not swallowed into the route it contains.
    $text = $dom->textContent;
    if (preg_match_all('/admin\.php\?page=([A-Za-z0-9_<>=&-]+)/', $text, $matches))
    {
        foreach ($matches[1] as $route)
        {
            $adminUrls++;
            $problem = adminRouteProblem($route);
            if ($problem !== null)
            {
                fail("$name quotes admin.php?page=$route: $problem");
            }
        }
    }
}

/**
 * Every emoji in a page, by codepoint.
 *
 * Ranges rather than a list: an allow-list of the emoji seen so far would pass
 * the first one nobody thought of. Typographic and currency symbols below
 * U+2190 are left alone, so an arrow or a section sign in running text is not
 * reported as an emoji.
 *
 * @param string $html
 * @return array<string> the offending characters, empty when there are none
 */
function emojiIn($html)
{
    $found = array();

    foreach (preg_split('//u', $html, -1, PREG_SPLIT_NO_EMPTY) as $character)
    {
        $codepoint = mb_ord($character, 'UTF-8');

        if ($codepoint >= 0x2190 and $codepoint <= 0x2BFF)
        {
            $found[$character] = true;
        }

        if ($codepoint >= 0x1F000)
        {
            $found[$character] = true;
        }
    }

    return array_keys($found);
}

/**
 * Resolves one ?page= value the way admin.php:129-176 does.
 *
 * @param string $route the value as written in the handbook
 * @return string|null null when the route resolves, else what went wrong
 */
function adminRouteProblem($route)
{
    $route = preg_replace('/&(amp;)?.*$/', '', $route);

    if (preg_match('/^plugin-([^-]*)(?:-(.*))?$/', $route, $matches))
    {
        $file = ROOT . '/plugins/' . $matches[1] . '/admin.php';
        return file_exists($file) ? null : 'no plugin admin page at plugins/' . $matches[1] . '/admin.php';
    }

    if (preg_match('/^album-(\d+|<[^>]+>)(?:-(.*))?$/', $route))
    {
        return file_exists(ROOT . '/admin/album.php') ? null : 'admin/album.php is missing';
    }

    if (preg_match('/^photo-(\d+|<[^>]+>)(?:-(.*))?$/', $route))
    {
        return file_exists(ROOT . '/admin/photo.php') ? null : 'admin/photo.php is missing';
    }

    if (!preg_match('/^[a-z_]+$/', $route))
    {
        return 'admin.php only accepts /^[a-z_]*$/ as a page name';
    }

    return file_exists(ROOT . '/admin/' . $route . '.php') ? null : "no admin/$route.php";
}

// -------------------------------------------------------------- screenshots

$shots = array_map('basename', glob(SHOT_DIR . '/*.png'));
sort($shots);

if (count($shots) === 0)
{
    fail('no screenshots found in handbuch/assets/screenshots/');
}

foreach ($shots as $shot)
{
    if (!isset($referencedShots[$shot]))
    {
        fail("$shot is never referenced by any page");
    }
}

// ------------------------------------------------------------- anti-vacuity

if ($references < MIN_REFERENCES)
{
    fail('only ' . $references . ' local references were checked, expected at least ' . MIN_REFERENCES
        . ' - the parser probably read nothing');
}

if ($adminUrls < MIN_ADMIN_URLS)
{
    fail('only ' . $adminUrls . ' admin routes were checked, expected at least ' . MIN_ADMIN_URLS);
}

if ($bytesScanned < MIN_BYTES_SCANNED)
{
    fail('only ' . $bytesScanned . ' bytes of page text were scanned for forbidden characters, '
        . 'expected at least ' . MIN_BYTES_SCANNED);
}

// -------------------------------------------------------------------- report

if (count($failures) > 0)
{
    foreach ($failures as $failure)
    {
        fwrite(STDERR, 'FAIL  ' . $failure . "\n");
    }
    fwrite(STDERR, count($failures) . " problem(s) found\n");
    exit(1);
}

echo 'OK  ' . count($pages) . ' pages, ' . $references . ' references, '
    . count($shots) . ' screenshots all referenced, ' . $adminUrls . ' admin routes resolve, '
    . $bytesScanned . " bytes free of em-dashes and emoji\n";
exit(0);
