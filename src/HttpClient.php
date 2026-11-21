<?php
declare(strict_types=1);

final class HttpClient
{
    public static function get(string $url, array $options = []): array
    {
        return self::request('GET', $url, $options);
    }

    public static function post(string $url, string $body, array $options = []): array
    {
        $options['body'] = $body;
        return self::request('POST', $url, $options);
    }

    public static function request(string $method, string $url, array $options = []): array
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return self::result(false, 0, '', $url, '仅支持 http/https 地址');
        }
        $options += [
            'headers' => [],
            'body' => null,
            'timeout' => 20,
            'follow' => true,
            'cookieFile' => '',
            'cookie' => '',
            'verifySsl' => true,
        ];
        return function_exists('curl_init')
            ? self::viaCurl(strtoupper($method), $url, $options)
            : self::viaStream(strtoupper($method), $url, $options);
    }

    private static function result(bool $ok, int $status, string $body, string $url, string $error, bool $sslFallback = false): array
    {
        return [
            'ok' => $ok,
            'status' => $status,
            'body' => $body,
            'url' => $url,
            'error' => $error,
            'sslFallback' => $sslFallback,
        ];
    }

    private static function viaCurl(string $method, string $url, array $options): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            $ch = curl_init($url);
            $headers = [];
            foreach ($options['headers'] as $key => $value) {
                $headers[] = is_int($key) ? (string)$value : $key . ': ' . $value;
            }
            $curlOpts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => (bool)$options['follow'],
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => min(10, (int)$options['timeout']),
                CURLOPT_TIMEOUT => (int)$options['timeout'],
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => '',
                CURLOPT_SSL_VERIFYPEER => (bool)$options['verifySsl'],
                CURLOPT_SSL_VERIFYHOST => $options['verifySsl'] ? 2 : 0,
            ];
            if ((string)$options['cookie'] !== '') {
                $curlOpts[CURLOPT_COOKIE] = (string)$options['cookie'];
            }
            if ((string)$options['cookieFile'] !== '') {
                $dir = dirname((string)$options['cookieFile']);
                if ($dir !== '' && !is_dir($dir)) {
                    @mkdir($dir, 0777, true);
                }
                $curlOpts[CURLOPT_COOKIEFILE] = (string)$options['cookieFile'];
                $curlOpts[CURLOPT_COOKIEJAR] = (string)$options['cookieFile'];
            }
            if ($method === 'POST') {
                $curlOpts[CURLOPT_POST] = true;
                $curlOpts[CURLOPT_POSTFIELDS] = (string)$options['body'];
            } elseif ($method !== 'GET') {
                $curlOpts[CURLOPT_CUSTOMREQUEST] = $method;
                if ($options['body'] !== null) {
                    $curlOpts[CURLOPT_POSTFIELDS] = (string)$options['body'];
                }
            }
            curl_setopt_array($ch, $curlOpts);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            if ($body === false && $options['verifySsl'] && self::isSslError($errno, $error) && $attempt === 1) {
                $options['verifySsl'] = false;
                $options['_sslFallback'] = true;
                continue;
            }
            if ($body === false) {
                return self::result(false, $status, '', $finalUrl ?: $url, $error ?: 'cURL 请求失败', !empty($options['_sslFallback']));
            }
            return self::result($status >= 200 && $status < 400, $status, (string)$body, $finalUrl ?: $url, '', !empty($options['_sslFallback']));
        }
    }

    private static function viaStream(string $method, string $url, array $options): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            $headers = [];
            foreach ($options['headers'] as $key => $value) {
                $headers[] = is_int($key) ? (string)$value : $key . ': ' . $value;
            }
            if ((string)$options['cookie'] !== '') {
                $headers[] = 'Cookie: ' . $options['cookie'];
            }
            $http = [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => (int)$options['timeout'],
                'ignore_errors' => true,
                'follow_location' => $options['follow'] ? 1 : 0,
                'max_redirects' => 5,
                'user_agent' => '',
            ];
            if ($method === 'POST') {
                $http['content'] = (string)$options['body'];
            }
            $context = stream_context_create([
                'http' => $http,
                'ssl' => [
                    'verify_peer' => (bool)$options['verifySsl'],
                    'verify_peer_name' => (bool)$options['verifySsl'],
                ],
            ]);
            $body = @file_get_contents($url, false, $context);
            $status = 0;
            $lastHeaders = $http_response_header ?? [];
            foreach ($lastHeaders as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int)$m[1];
                }
            }
            $error = $body === false ? (string)(error_get_last()['message'] ?? '请求失败') : '';
            if ($body === false && $options['verifySsl'] && $attempt === 1 && stripos($error, 'ssl') !== false) {
                $options['verifySsl'] = false;
                $options['_sslFallback'] = true;
                continue;
            }
            if ($body === false) {
                return self::result(false, $status, '', $url, $error, !empty($options['_sslFallback']));
            }
            return self::result($status >= 200 && $status < 400, $status, (string)$body, $url, '', !empty($options['_sslFallback']));
        }
    }

    private static function isSslError(int $errno, string $error): bool
    {
        if (in_array($errno, [35, 51, 58, 60, 77, 83], true)) {
            return true;
        }
        return stripos($error, 'SSL') !== false || stripos($error, 'certificate') !== false;
    }
}