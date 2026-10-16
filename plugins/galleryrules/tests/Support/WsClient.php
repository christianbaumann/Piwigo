<?php
/** curl wrapper around the gallery: login, ws.php calls and page fetches. */
class WsClient
{
    private string $cookieFile;

    public function __construct()
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'galleryrules_ws_');
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
        ))['body'];

        return array('body' => $body, 'json' => json_decode($body, true));
    }

    /** A ws.php call as multipart form data, with one file in $_FILES[$field]. */
    public function upload(string $method, array $params, string $field, string $filePath): array
    {
        $fields = array('method' => $method);
        foreach ($params as $name => $value)
        {
            if (is_array($value))
            {
                foreach (array_values($value) as $i => $item)
                {
                    $fields[$name . '[' . $i . ']'] = (string)$item;
                }
            }
            else
            {
                $fields[$name] = (string)$value;
            }
        }
        $fields[$field] = new CURLFile($filePath, mime_content_type($filePath) ?: 'application/octet-stream', basename($filePath));

        $body = $this->request('/ws.php?format=json', array(
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $fields,
        ))['body'];

        return array('body' => $body, 'json' => json_decode($body, true));
    }

    /** This session's pwg_token. */
    public function token(): string
    {
        $res = $this->call('pwg.session.getStatus');
        if (empty($res['json']['result']['pwg_token']))
        {
            throw new RuntimeException('no pwg_token in pwg.session.getStatus: ' . $res['body']);
        }
        return $res['json']['result']['pwg_token'];
    }

    /**
     * GET a path with this client's session; a fresh client is a guest.
     *
     * @return array status (HTTP code), body
     */
    public function get(string $path): array
    {
        return $this->request($path, array());
    }

    /**
     * POST a form to a path with this client's session, as a browser sends it.
     *
     * @return array status (HTTP code), body
     */
    public function post(string $path, array $fields): array
    {
        return $this->request($path, array(
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => http_build_query($fields),
        ));
    }

    public function login(string $username, string $password): void
    {
        $res = $this->call('pwg.session.login', array('username' => $username, 'password' => $password));
        if (!$res['json'] || $res['json']['stat'] !== 'ok')
        {
            throw new RuntimeException("Login failed for $username: " . $res['body']);
        }
    }

    private function request(string $path, array $options): array
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
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($body))
        {
            throw new RuntimeException("no response from $path");
        }
        return array('status' => $status, 'body' => $body);
    }
}
