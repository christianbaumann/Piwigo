<?php
/** curl wrapper around the gallery: login, ws.php calls and page fetches. */
class WsClient
{
    private string $cookieFile;

    public function __construct()
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'photoinfo_ws_');
    }

    public function __destruct()
    {
        @unlink($this->cookieFile);
    }

    public function call(string $method, array $params = array()): array
    {
        $params['method'] = $method;
        $body = $this->request('/ws.php?format=json', array(
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => http_build_query($params),
        ));

        return array('body' => $body, 'json' => json_decode($body, true));
    }

    /** The same call as a GET request, which a post_only method must refuse. */
    public function callGet(string $method, array $params = array()): array
    {
        $params['method'] = $method;
        $body = $this->request('/ws.php?format=json&' . http_build_query($params), array());

        return array('body' => $body, 'json' => json_decode($body, true));
    }

    /** This session's pwg_token, as the page hands it to the editor. */
    public function token(): string
    {
        $res = $this->call('pwg.session.getStatus');
        if (empty($res['json']['result']['pwg_token']))
        {
            throw new RuntimeException('no pwg_token in pwg.session.getStatus: ' . $res['body']);
        }
        return $res['json']['result']['pwg_token'];
    }

    /** GET a gallery page with this client's session; a fresh client is a guest. */
    public function fetchPage(string $path): string
    {
        return $this->request($path, array(CURLOPT_FOLLOWLOCATION => true));
    }

    public function login(string $username, string $password): void
    {
        $res = $this->call('pwg.session.login', array('username' => $username, 'password' => $password));
        if (!$res['json'] || $res['json']['stat'] !== 'ok')
        {
            throw new RuntimeException("Login failed for $username: " . $res['body']);
        }
    }

    private function request(string $path, array $options): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, $options + array(
            CURLOPT_URL => Config::baseUrl() . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
        ));
        $body = curl_exec($ch);
        curl_close($ch);

        if (!is_string($body))
        {
            throw new RuntimeException("no response from $path");
        }
        return $body;
    }
}
