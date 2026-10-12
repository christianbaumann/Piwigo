<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The handbook is served to logged-in users only, through handbuch.php (decision 0063),
 * on both web servers: DDEV's nginx (.ddev/nginx/handbuch.conf) and the local Apache
 * container (handbuch/.htaccess), which stands in for the remote's Apache.
 *
 * Every case runs once per server. The Apache container is required, not optional:
 * testTheApacheContainerAnswers fails naming it, so the .htaccess half never passes by
 * being skipped.
 */
final class HandbookAccessTest extends TestCase
{
    /** The Apache service of .ddev/docker-compose.apache.yaml, as the web container reaches it. */
    private const APACHE_URL = 'http://apache';

    private const LOGIN_PAGE = 'identification.php';

    /** index.html plus seven topic pages, measured 2026-10-12; a lower bound. */
    private const MIN_PAGES = 8;

    private const HANDBUCH_DIR = PIWIGO_ROOT . 'handbuch';

    /** @var array<int, string> */
    private array $cookieFiles = array();

    protected function tearDown(): void
    {
        foreach ($this->cookieFiles as $file)
        {
            @unlink($file);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function servers(): array
    {
        return array(
            'nginx' => array(Config::baseUrl()),
            'apache' => array(self::APACHE_URL),
        );
    }

    /** Structural: the Apache container is up and is Apache. */
    public function testTheApacheContainerAnswers(): void
    {
        $res = $this->get(self::APACHE_URL . '/identification.php', null, false);

        $this->assertSame(
            200,
            $res['http_code'],
            'the local Apache container does not answer at ' . self::APACHE_URL
            . ' - start it with `ddev restart` (service `apache`, .ddev/docker-compose.apache.yaml)'
        );
        $this->assertMatchesRegularExpression('/^Server: Apache/mi', $res['headers']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function guestRequests(): array
    {
        $cases = array();
        foreach (self::servers() as $server => list($base))
        {
            foreach (array('/handbuch/', '/handbuch/01-alben.html', '/handbuch/assets/handbuch.css', '/handbuch') as $path)
            {
                $cases["$server $path"] = array($base, $path);
            }
        }
        return $cases;
    }

    /** [NEG] A guest asking for any handbook URL lands on the login page, which leads back to it. */
    #[DataProvider('guestRequests')]
    public function testAGuestLandsOnTheLoginPage(string $base, string $path): void
    {
        $res = $this->get($base . $path, null, true);

        $this->assertSame(200, $res['http_code']);
        $this->assertSame($base . '/' . self::LOGIN_PAGE, strtok($res['url'], '?'), "a guest got $path");
        $this->assertStringContainsString('redirect=' . rtrim($path, '/'), urldecode(urldecode($res['url'])), 'the login page does not lead back');
    }

    /**
     * [ST] guest -> login -> handbook, in one browser: the page a guest was sent away from
     * opens after the login. Under Apache core's cookie_path() read the rewrite's REDIRECT_URL
     * and scoped the guest's session cookie to /handbuch/, which the browser then preferred
     * over the logged-in one, so the login page came back forever.
     */
    #[DataProvider('servers')]
    public function testAGuestWhoLogsInThenGetsTheHandbook(string $base): void
    {
        $cookies = tempnam(sys_get_temp_dir(), 'handbook-');
        $this->cookieFiles[] = $cookies;

        $guest = $this->get($base . '/handbuch/', $cookies, false);
        $this->assertSame(302, $guest['http_code']);
        $this->assertDoesNotMatchRegularExpression('#^Set-Cookie:.*path=[^;\r\n]*/handbuch#mi', $guest['headers']);

        $this->login($base, $cookies);
        $res = $this->get($base . '/handbuch/', $cookies, false);

        $this->assertSame(200, $res['http_code'], 'the login page came back after logging in');
        $this->assertSame(file_get_contents(self::HANDBUCH_DIR . '/index.html'), $res['body']);
    }

    /** [HAPPY] A logged-in normal user gets every page, the stylesheet and every screenshot, byte for byte. */
    #[DataProvider('servers')]
    public function testANormalUserGetsEveryFile(string $base): void
    {
        $cookies = $this->login($base);
        $files = $this->handbookFiles();

        foreach ($files as $relative => $type)
        {
            $res = $this->get($base . '/handbuch/' . $relative, $cookies, false);
            $this->assertSame(200, $res['http_code'], $relative);
            $this->assertMatchesRegularExpression('/^Content-Type: ' . preg_quote($type, '/') . '/mi', $res['headers'], $relative);
            $this->assertSame(file_get_contents(self::HANDBUCH_DIR . '/' . $relative), $res['body'], $relative);
        }

        $index = $this->get($base . '/handbuch/', $cookies, false);
        $this->assertSame(file_get_contents(self::HANDBUCH_DIR . '/index.html'), $index['body']);
    }

    /** [HAPPY] Every relative link and image of every page, resolved as a browser would, is served. */
    #[DataProvider('servers')]
    public function testEveryRelativeReferenceOfAPageIsServed(string $base): void
    {
        $cookies = $this->login($base);
        $pages = glob(self::HANDBUCH_DIR . '/*.html');
        $this->assertGreaterThanOrEqual(self::MIN_PAGES, count($pages));

        $checked = 0;
        foreach ($pages as $page)
        {
            preg_match_all('/(?:href|src)="([^"#]+)/', file_get_contents($page), $m);
            foreach (array_unique($m[1]) as $target)
            {
                if (preg_match('#^(?:[a-z]+:|/)#i', $target))
                {
                    continue;
                }
                $res = $this->get($base . '/handbuch/' . $target, $cookies, false);
                $this->assertSame(200, $res['http_code'], basename($page) . ' -> ' . $target);
                $checked++;
            }
        }
        $this->assertGreaterThan(self::MIN_PAGES, $checked, 'the pages carry almost no relative references');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedRequests(): array
    {
        $cases = array();
        foreach (self::servers() as $server => list($base))
        {
            foreach (array(
                '/handbuch.php?f=../local/config/database.inc.php',
                '/handbuch.php?f=tools/check.php',
                '/handbuch/tools/check.php',
                '/handbuch/tools/seed.php',
                '/handbuch/.htaccess',
                ) as $path)
            {
                $cases["$server $path"] = array($base, $path);
            }
        }
        return $cases;
    }

    /** [NEG] Traversal, tools and hidden files are refused even for a logged-in user, and leak nothing. */
    #[DataProvider('refusedRequests')]
    public function testARefusedFileIsNotServed(string $base, string $path): void
    {
        $cookies = $this->login($base);
        $res = $this->get($base . $path, $cookies, false);

        $this->assertContains($res['http_code'], array(400, 403, 404), "$path answered {$res['http_code']}");
        $this->assertStringNotContainsString('<?php', $res['body']);
        $this->assertStringNotContainsString('RewriteRule', $res['body']);
    }

    /** [NEG] A query string on a handbook URL does not change which file is served. */
    #[DataProvider('servers')]
    public function testAQueryStringDoesNotPickTheFile(string $base): void
    {
        $cookies = $this->login($base);
        $res = $this->get($base . '/handbuch/index.html?f=01-alben.html', $cookies, false);

        $this->assertSame(200, $res['http_code']);
        $this->assertSame(file_get_contents(self::HANDBUCH_DIR . '/index.html'), $res['body']);
    }

    /** @return array<string, string> handbook file relative to handbuch/ => expected content type */
    private function handbookFiles(): array
    {
        $files = array();
        foreach (glob(self::HANDBUCH_DIR . '/*.html') as $file)
        {
            $files[basename($file)] = 'text/html';
        }
        $this->assertGreaterThanOrEqual(self::MIN_PAGES, count($files));
        $files['assets/handbuch.css'] = 'text/css';
        $shots = glob(self::HANDBUCH_DIR . '/assets/screenshots/*.png');
        $this->assertNotEmpty($shots);
        foreach ($shots as $file)
        {
            $files['assets/screenshots/' . basename($file)] = 'image/png';
        }
        return $files;
    }

    /** Logs the normal account in on one server, into a new or the given cookie file; returns it. */
    private function login(string $base, ?string $cookies = null): string
    {
        if ($cookies === null)
        {
            $cookies = tempnam(sys_get_temp_dir(), 'handbook-');
            $this->cookieFiles[] = $cookies;
        }
        $ch = curl_init($base . '/ws.php?format=json');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(array(
                'method' => 'pwg.session.login',
                'username' => Config::normalUsername(),
                'password' => Config::normalPassword(),
            )),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $cookies,
            CURLOPT_COOKIEFILE => $cookies,
            CURLOPT_TIMEOUT => 30,
        ));
        $body = curl_exec($ch);
        curl_close($ch);
        $this->assertSame('ok', json_decode((string)$body, true)['stat'] ?? null, "login on $base: $body");
        return $cookies;
    }

    /** @return array{http_code: int, headers: string, body: string, url: string} */
    private function get(string $url, ?string $cookies, bool $follow): array
    {
        $ch = curl_init($url);
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_TIMEOUT => 30,
        );
        if ($cookies !== null)
        {
            $opts[CURLOPT_COOKIEFILE] = $cookies;
            $opts[CURLOPT_COOKIEJAR] = $cookies;
        }
        curl_setopt_array($ch, $opts);
        $raw = (string)curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $res = array(
            'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'headers' => substr($raw, 0, $headerSize),
            'body' => substr($raw, $headerSize),
            'url' => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        );
        curl_close($ch);
        return $res;
    }
}
