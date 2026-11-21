<?php
declare(strict_types=1);

final class Notifier
{
    private array $settings;
    /** @var string[] */
    private array $errors = [];

    public function __construct(?array $settings = null)
    {
        $this->settings = $settings ?? Database::settings();
    }

    /**
     * @return string[] 本次调度里各渠道的失败原因
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function notify(array $streamer, string $event, array $context = [], bool $force = false): void
    {
        $isOnline = $event === 'online';
        if (!$force && $isOnline && ($this->settings['notify_on_online'] ?? '1') !== '1') {
            return;
        }
        if (!$force && !$isOnline && ($this->settings['notify_on_offline'] ?? '1') !== '1') {
            return;
        }
        $name = (string)$streamer['name'];
        $liveUrl = 'https://live.douyin.com/' . rawurlencode((string)$streamer['web_rid']);
        $title = $isOnline ? '【开播提醒】' . $name . ' 开播了' : '【下播提醒】' . $name . ' 已下播';
        $lines = ['主播：' . $name];
        if ($isOnline) {
            if (!empty($streamer['live_title'])) {
                $lines[] = '直播标题：' . $streamer['live_title'];
            }
            $lines[] = '开播时间：' . ($streamer['live_since'] ?: now());
            $lines[] = '直播间：' . $liveUrl;
        } else {
            if (!empty($context['duration'])) {
                $lines[] = '本次直播时长：' . $context['duration'];
            }
            $lines[] = '主页：' . (string)$streamer['url'];
        }
        $body = implode("\n", $lines);

        $key = trim((string)($this->settings['notify_serverchan_key'] ?? ''));
        if ($key !== '') {
            $this->sendServerChan($key, $title, $body);
        }
        $bark = trim((string)($this->settings['notify_bark_url'] ?? ''));
        if ($bark !== '') {
            $this->sendBark($bark, $title, $body, $isOnline ? $liveUrl : (string)$streamer['url']);
        }
        $webhook = trim((string)($this->settings['notify_webhook'] ?? ''));
        if ($webhook !== '') {
            $this->sendWebhook($webhook, $event, $title, $body, $streamer);
        }
        if (Mailer::configured($this->settings)) {
            $this->sendMail($title, $body, $liveUrl, $streamer);
        }
    }

    private function sendMail(string $title, string $body, string $liveUrl, array $streamer): void
    {
        $recipients = self::recipients((string)($this->settings['smtp_to'] ?? ''));
        if ($recipients === []) {
            $this->log('邮件通知跳过：收件地址均不合法');
            return;
        }
        $from = trim((string)($this->settings['smtp_from'] ?? '')) ?: trim((string)($this->settings['smtp_user'] ?? ''));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $this->log('邮件通知跳过：发件地址未填写或不合法');
            return;
        }
        $fromName = trim((string)($this->settings['smtp_from_name'] ?? '')) ?: '开播雷达';
        $extra = [];
        if (!str_contains($body, $liveUrl)) {
            $extra[] = '直播间：' . $liveUrl;
        }
        $profile = (string)($streamer['url'] ?? '');
        if ($profile !== '' && !str_contains($body, $profile)) {
            $extra[] = '主播主页：' . $profile;
        }
        $text = rtrim($body)
            . ($extra === [] ? '' : "\n" . implode("\n", $extra))
            . "\n\n—— 来自开播雷达（" . now() . '）';
        try {
            (new Mailer($this->settings))->send($from, $fromName, $recipients, $title, $text);
        } catch (Throwable $e) {
            $this->log('邮件通知失败：' . $e->getMessage());
        }
    }

    /**
     * @return string[]
     */
    public static function recipients(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,\s;、；]+/u', $raw) ?: [] as $item) {
            $item = trim($item);
            if ($item !== '' && filter_var($item, FILTER_VALIDATE_EMAIL) && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    private function sendServerChan(string $key, string $title, string $body): void
    {
        $url = 'https://sctapi.ftqq.com/' . rawurlencode($key) . '.send';
        $payload = http_build_query(['title' => $title, 'desp' => $body]);
        $res = HttpClient::post($url, $payload, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                'Accept' => 'application/json',
            ],
            'timeout' => 10,
            'verifySsl' => ($this->settings['verify_ssl'] ?? '1') === '1',
        ]);
        if (!$res['ok']) {
            $this->log('ServerChan 推送失败：' . ($res['error'] ?: 'HTTP ' . $res['status']));
        }
    }

    private function sendBark(string $baseUrl, string $title, string $body, string $url): void
    {
        if (!preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'https://api.day.app/' . trim($baseUrl, '/');
        }
        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'group' => '开播雷达',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $res = HttpClient::post($baseUrl, (string)$payload, [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Accept' => 'application/json',
            ],
            'timeout' => 10,
            'verifySsl' => ($this->settings['verify_ssl'] ?? '1') === '1',
        ]);
        if (!$res['ok']) {
            $this->log('Bark 推送失败：' . ($res['error'] ?: 'HTTP ' . $res['status']));
        }
    }

    private function sendWebhook(string $url, string $event, string $title, string $body, array $streamer): void
    {
        $payload = json_encode([
            'event' => $event,
            'title' => $title,
            'body' => $body,
            'streamer' => [
                'id' => (int)$streamer['id'],
                'name' => $streamer['name'],
                'web_rid' => $streamer['web_rid'],
                'url' => $streamer['url'],
                'live_title' => $streamer['live_title'] ?? '',
                'room_id' => $streamer['live_room_id'] ?? '',
            ],
            'timestamp' => now(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $res = HttpClient::post($url, (string)$payload, [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Accept' => 'application/json',
            ],
            'timeout' => 10,
            'verifySsl' => ($this->settings['verify_ssl'] ?? '1') === '1',
        ]);
        if (!$res['ok']) {
            $this->log('Webhook 推送失败：' . ($res['error'] ?: 'HTTP ' . $res['status']));
        }
    }

    private function log(string $message): void
    {
        $this->errors[] = $message;
        $dir = DATA_DIR . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($dir . '/notify.log', '[' . now() . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}