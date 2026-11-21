<?php
declare(strict_types=1);

final class DouyinDetector
{
    private const UA_MOBILE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private const UA_DESKTOP = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
    ];

    public static function parseTarget(string $input): array
    {
        $input = trim($input);
        $secUid = null;
        $webRid = null;
        if (preg_match('#(?:douyin\.com/user/|iesdouyin\.com/share/user/)([A-Za-z0-9_\-]+)#i', $input, $m)) {
            $secUid = $m[1];
        } elseif (preg_match('#live\.douyin\.com/([A-Za-z0-9._\-]+)#i', $input, $m)) {
            $webRid = rawurldecode($m[1]);
        } elseif (preg_match('/^MS4wLjAB[A-Za-z0-9_\-]+$/', $input)) {
            $secUid = $input;
        } elseif (preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $input)) {
            $webRid = $input;
        }
        return ['sec_uid' => $secUid, 'web_rid' => $webRid];
    }

    public function resolveProfile(string $secUid, array $settings): array
    {
        $url = 'https://www.iesdouyin.com/web/api/v2/user/info/?sec_uid=' . rawurlencode($secUid);
        $res = HttpClient::get($url, [
            'headers' => [
                'User-Agent' => self::UA_MOBILE,
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'zh-CN,zh;q=0.9',
                'Referer' => 'https://www.iesdouyin.com/',
            ],
            'timeout' => (int)($settings['request_timeout'] ?? 20),
            'verifySsl' => ($settings['verify_ssl'] ?? '1') === '1',
        ]);
        if (!$res['ok']) {
            $msg = $res['status'] > 0 ? 'HTTP ' . $res['status'] : $res['error'];
            return ['ok' => false, 'error' => '获取用户资料失败：' . $msg];
        }
        $json = json_decode($res['body'], true);
        if (!is_array($json) || (int)($json['status_code'] ?? -1) !== 0 || empty($json['user_info'])) {
            return ['ok' => false, 'error' => '用户资料接口返回异常'];
        }
        $user = $json['user_info'];
        $webRid = trim((string)($user['unique_id'] ?? ''));
        if ($webRid === '' || $webRid === '0') {
            $webRid = trim((string)($user['short_id'] ?? ''));
        }
        return [
            'ok' => true,
            'error' => '',
            'sec_uid' => (string)($user['sec_uid'] ?? $secUid),
            'web_rid' => $webRid,
            'nickname' => trim((string)($user['nickname'] ?? '')),
            'avatar' => (string)($user['avatar_thumb']['url_list'][0] ?? ''),
        ];
    }

    public function detectLive(string $webRid, array $settings): array
    {
        $webRid = trim($webRid);
        if (!preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $webRid)) {
            return $this->unknown('抖音号格式不正确');
        }
        $url = 'https://live.douyin.com/' . rawurlencode($webRid);
        $headers = [
            'User-Agent' => self::UA_DESKTOP[array_rand(self::UA_DESKTOP)],
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'zh-CN,zh;q=0.9',
            'Referer' => 'https://live.douyin.com/',
        ];
        $res = HttpClient::get($url, [
            'headers' => $headers,
            'timeout' => (int)($settings['request_timeout'] ?? 20),
            'cookieFile' => DATA_DIR . '/cookies/douyin.cookie',
            'cookie' => (string)($settings['douyin_cookie'] ?? ''),
            'verifySsl' => ($settings['verify_ssl'] ?? '1') === '1',
        ]);
        if (!$res['ok']) {
            $msg = $res['status'] > 0 ? 'HTTP ' . $res['status'] : $res['error'];
            return $this->unknown('直播页请求失败：' . $msg);
        }
        $body = $res['body'];
        if (strlen($body) < 20000 || strpos($body, '_$jsvmprt') !== false) {
            return $this->unknown('抖音返回风控验证页，请稍后重试，或在设置中填入浏览器 Cookie');
        }
        $room = self::parseRoomInfo($body, $webRid);
        if ($room === null) {
            return $this->unknown('未能从直播页解析出房间信息');
        }
        if ($room['web_rid'] !== '' && strcasecmp($room['web_rid'], $webRid) !== 0) {
            return $this->unknown('直播页返回的账号与目标不一致（' . $room['web_rid'] . '）');
        }
        return [
            'ok' => true,
            'state' => $room['is_live'] ? 'live' : 'offline',
            'error' => '',
            'title' => $room['title'],
            'cover' => $room['cover'],
            'room_id' => $room['room_id'],
            'room_status' => $room['status'],
            'web_rid' => $room['web_rid'],
            'nickname' => $room['nickname'],
            'avatar' => $room['avatar'],
            'user_count' => $room['user_count'],
            'live_url' => $url,
            'http_status' => $res['status'],
            'page_size' => strlen($body),
            'ssl_fallback' => $res['sslFallback'],
        ];
    }

    public static function parseRoomInfo(string $html, string $expectedWebRid = ''): ?array
    {
        $candidate = $html;
        $fallback = null;
        for ($pass = 0; $pass < 3; $pass++) {
            if ($pass > 0) {
                $candidate = self::unescape($candidate);
            }
            $lists = [];
            foreach (self::extractObjects($candidate, '"roomStore":') as $store) {
                $roomInfo = $store['roomInfo'] ?? null;
                if (is_array($roomInfo) && (isset($roomInfo['room']) || array_key_exists('web_stream_url', $roomInfo))) {
                    $lists[] = $roomInfo;
                }
            }
            if ($lists === []) {
                foreach (self::extractObjects($candidate, '"roomInfo":') as $roomInfo) {
                    if (is_array($roomInfo) && (isset($roomInfo['room']) || array_key_exists('web_stream_url', $roomInfo))) {
                        $lists[] = $roomInfo;
                    }
                }
            }
            foreach ($lists as $roomInfo) {
                $rid = (string)($roomInfo['web_rid'] ?? '');
                if ($expectedWebRid !== '' && $rid !== '' && strcasecmp($rid, $expectedWebRid) === 0) {
                    return self::buildParsedRoom($roomInfo);
                }
                if ($fallback === null) {
                    $fallback = $roomInfo;
                }
            }
        }
        return $fallback === null ? null : self::buildParsedRoom($fallback);
    }

    private static function buildParsedRoom(array $roomInfo): array
    {
        $room = isset($roomInfo['room']) && is_array($roomInfo['room']) ? $roomInfo['room'] : [];
        $status = isset($room['status']) ? (int)$room['status'] : null;
        $streamUrl = $roomInfo['web_stream_url'] ?? null;
        $isLive = $status === 2 || ($status === null && !empty($streamUrl));
        $anchor = isset($roomInfo['anchor']) && is_array($roomInfo['anchor']) ? $roomInfo['anchor'] : [];
        return [
            'is_live' => $isLive,
            'status' => $status,
            'web_rid' => (string)($roomInfo['web_rid'] ?? ''),
            'room_id' => (string)($roomInfo['roomId'] ?? ($room['id_str'] ?? '')),
            'title' => trim((string)($room['title'] ?? '')),
            'cover' => (string)($room['cover']['url_list'][0] ?? ''),
            'nickname' => trim((string)($anchor['nickname'] ?? '')),
            'avatar' => (string)($anchor['avatar_thumb']['url_list'][0] ?? ($anchor['avatar'] ?? ($room['owner']['avatar_thumb']['url_list'][0] ?? ''))),
            'user_count' => (string)($room['user_count_str'] ?? ''),
        ];
    }

    private function unknown(string $error): array
    {
        return [
            'ok' => false,
            'state' => 'unknown',
            'error' => $error,
            'title' => '',
            'cover' => '',
            'room_id' => '',
            'room_status' => null,
            'web_rid' => '',
            'nickname' => '',
            'avatar' => '',
            'user_count' => '',
            'live_url' => '',
            'http_status' => 0,
            'page_size' => 0,
            'ssl_fallback' => false,
        ];
    }

    private static function unescape(string $value): string
    {
        return str_replace(['\\"', '&quot;', '\\u0026'], ['"', '"', '&'], $value);
    }

    private static function extractObjects(string $source, string $anchor): array
    {
        $out = [];
        $offset = 0;
        $length = strlen($source);
        while (($pos = strpos($source, $anchor, $offset)) !== false) {
            $start = strpos($source, '{', $pos + strlen($anchor));
            if ($start === false) {
                break;
            }
            $depth = 0;
            $inString = false;
            $escaped = false;
            for ($i = $start; $i < $length; $i++) {
                $char = $source[$i];
                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\') {
                        $escaped = true;
                    } elseif ($char === '"') {
                        $inString = false;
                    }
                    continue;
                }
                if ($char === '"') {
                    $inString = true;
                } elseif ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $data = json_decode(substr($source, $start, $i - $start + 1), true);
                        if (is_array($data)) {
                            $out[] = $data;
                        }
                        $offset = $i + 1;
                        break;
                    }
                }
            }
            if ($i >= $length) {
                break;
            }
        }
        return $out;
    }
}