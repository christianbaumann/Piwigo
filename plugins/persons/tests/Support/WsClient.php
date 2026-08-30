<?php
/** curl wrapper around ws.php: login, token, and method calls. */
class WsClient
{
    private string $cookieFile;
    private ?string $token = null;

    public function __construct()
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'persons_ws_');
    }

    public function __destruct()
    {
        @unlink($this->cookieFile);
    }

    public function call(string $method, array $params = array(), bool $useCookies = true): array
    {
        $params['method'] = $method;

        $ch = curl_init();
        $opts = array(
            CURLOPT_URL => Config::baseUrl() . '/ws.php?format=json',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => 1,
            // Encoded here rather than handed to curl as an array: curl flattens
            // a nested array to its last element, so image_id => array(4, 7)
            // would silently post only 7 and every multi-photo call would test
            // one photo.
            CURLOPT_POSTFIELDS => http_build_query($params),
        );
        if ($useCookies)
        {
            $opts[CURLOPT_COOKIEJAR] = $this->cookieFile;
            $opts[CURLOPT_COOKIEFILE] = $this->cookieFile;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array(
            'http_code' => $httpCode,
            'body' => $body,
            'json' => json_decode($body, true),
        );
    }

    /** Same call over GET, to characterise the absence of `post_only`. */
    public function callGet(string $method, array $params = array()): array
    {
        $params['method'] = $method;

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => Config::baseUrl() . '/ws.php?format=json&' . http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
        ));
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array(
            'http_code' => $httpCode,
            'body' => $body,
            'json' => json_decode($body, true),
        );
    }

    /** GET a gallery page, reusing this client's session (or as a guest). */
    public function fetchPage(string $path, bool $useCookies = true): array
    {
        $ch = curl_init();
        $opts = array(
            CURLOPT_URL => Config::baseUrl() . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
        );
        if ($useCookies)
        {
            $opts[CURLOPT_COOKIEJAR] = $this->cookieFile;
            $opts[CURLOPT_COOKIEFILE] = $this->cookieFile;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array('http_code' => $httpCode, 'body' => $body);
    }

    /**
     * Multipart call, for the one method that takes a real file.
     *
     * Kept apart from call(): that one urlencodes its body, which cannot carry a
     * file, and this one must not urlencode, which would not carry an array.
     *
     * @param string $field the $_FILES key the handler reads
     */
    public function upload(string $method, string $filePath, string $field, array $params = array()): array
    {
        if (!is_file($filePath))
        {
            throw new RuntimeException("nothing to upload at $filePath");
        }

        $params['method'] = $method;
        $params[$field] = new CURLFile($filePath, 'image/png', basename($filePath));

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => Config::baseUrl() . '/ws.php?format=json',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
        ));
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array(
            'http_code' => $httpCode,
            'body' => $body,
            'json' => json_decode($body, true),
        );
    }

    /** POST an admin page, reusing this client's session. */
    public function postPage(string $path, array $params): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => Config::baseUrl() . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
        ));
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array('http_code' => $httpCode, 'body' => $body);
    }

    public function login(string $username, string $password): void
    {
        $res = $this->call('pwg.session.login', array('username' => $username, 'password' => $password));
        if (!$res['json'] || $res['json']['stat'] !== 'ok')
        {
            throw new RuntimeException("Login failed for $username: " . $res['body']);
        }
        $this->token = null;
    }

    public function logout(): void
    {
        $this->call('pwg.session.logout');
        $this->token = null;
    }

    public function token(): string
    {
        if ($this->token === null)
        {
            $res = $this->call('pwg.session.getStatus');
            $this->token = $res['json']['result']['pwg_token'];
        }
        return $this->token;
    }
}
