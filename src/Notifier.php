<?php
declare(strict_types=1);

/**
 * 推送调度：每个「事件 × 渠道」先在 notify_queue 落一行，再立刻同步发送。
 * 失败的行留在队列里按指数退避重试，超过上限才标 failed——这样开播那一刻
 * 正好断网也不会永久丢掉提醒。
 */
final class Notifier
{
    private const CHANNELS = ['serverchan', 'bark', 'webhook', 'mail'];

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
        if (!$force && $event === 'offline' && ($this->settings['notify_on_offline'] ?? '1') !== '1') {
            return;
        }
        if (!$force && $event === 'title' && ($this->settings['notify_on_title'] ?? '0') !== '1') {
            return;
        }

        $name = (string)$streamer['name'];
        $liveUrl = 'https://live.douyin.com/' . rawurlencode((string)$streamer['web_rid']);
        $profileUrl = (string)($streamer['url'] ?? '');

        if ($isOnline) {
            $title = '【开播提醒】' . $name . ' 开播了';
            $lines = ['主播：' . $name];
            if (!empty($streamer['live_title'])) {
                $lines[] = '直播标题：' . $streamer['live_title'];
            }
            $lines[] = '开播时间：' . ($streamer['live_since'] ?: now());
            $lines[] = '直播间：' . $liveUrl;
        } elseif ($event === 'title') {
            $title = '【标题更新】' . $name . ' 换了直播标题';
            $lines = [
                '主播：' . $name,
                '新标题：' . (string)($context['new_title'] ?? ''),
            ];
            if (!empty($context['old_title'])) {
                $lines[] = '原标题：' . $context['old_title'];
            }
            $lines[] = '已直播：' . formatDuration($streamer['live_since'] ?? null);
            $lines[] = '直播间：' . $liveUrl;
        } else {
            $title = '【下播提醒】' . $name . ' 已下播';
            $lines = ['主播：' . $name];
            if (!empty($context['duration'])) {
                $lines[] = '本次直播时长：' . $context['duration'];
            }
            $lines[] = '主页：' . $profileUrl;
        }
        $body = implode("\n", $lines);

        $snapshot = [
            'id' => (int)$streamer['id'],
            'name' => $name,
            'web_rid' => (string)$streamer['web_rid'],
            'url' => $profileUrl,
            'live_title' => (string)($streamer['live_title'] ?? ''),
            'room_id' => (string)($streamer['live_room_id'] ?? ''),
        ];
        $payload = json_encode(
            ['streamer' => $snapshot, 'context' => $context, 'live_url' => $liveUrl],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        foreach ($this->enabledChannels() as $channel) {
            $queueId = $this->enqueue(
                (int)$streamer['id'],
                $event,
                $channel,
                $title,
                $body,
                $isOnline || $event === 'title' ? $liveUrl : $profileUrl,
                (string)$payload
            );
            $this->attempt($queueId);
        }
    }

    /**
     * @return string[] 已配置的渠道
     */
    public function enabledChannels(): array
    {
        $out = [];
        if (trim((string)($this->settings['notify_serverchan_key'] ?? '')) !== '') {
            $out[] = 'serverchan';
        }
        if (trim((string)($this->settings['notify_bark_url'] ?? '')) !== '') {
            $out[] = 'bark';
        }
        if (trim((string)($this->settings['notify_webhook'] ?? '')) !== '') {
            $out[] = 'webhook';
        }
        if (Mailer::configured($this->settings)) {
            $out[] = 'mail';
        }
        return $out;
    }

    /**
     * 排空待发通知。监控每轮调用时只处理到期的行；
     * $force 用于手动「立即重试」，忽略退避时间并重用已放弃的行。
     * @return array{checked:int,sent:int,failed:int}
     */
    public function retryPending(int $limit = 20, bool $force = false): array
    {
        $pdo = Database::pdo();
        $stmt = $force
            ? $pdo->prepare(
                "SELECT id FROM notify_queue WHERE status IN ('pending', 'failed')
                 ORDER BY id ASC LIMIT " . max(1, $limit)
            )
            : $pdo->prepare(
                "SELECT id FROM notify_queue
                 WHERE status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= ?)
                 ORDER BY id ASC LIMIT " . max(1, $limit)
            );
        if ($force) {
            $stmt->execute();
        } else {
            $stmt->execute([now()]);
        }
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($force && $ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare(
                "UPDATE notify_queue SET status = 'pending', attempts = 0, next_retry_at = NULL
                 WHERE id IN ({$in})"
            )->execute($ids);
        }

        $stat = ['checked' => count($ids), 'sent' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            $before = $this->row((int)$id);
            if ($before === null) {
                continue;
            }
            $this->attempt((int)$id);
            $after = $this->row((int)$id);
            if ($after === null) {
                continue;
            }
            if ($after['status'] === 'sent') {
                $stat['sent']++;
            } elseif ($after['status'] === 'failed') {
                $stat['failed']++;
            }
        }
        return $stat;
    }

    public static function queueStats(): array
    {
        $row = Database::pdo()->query(
            "SELECT
                SUM(status = 'pending') AS pending,
                SUM(status = 'failed') AS failed,
                SUM(status = 'sent') AS sent
             FROM notify_queue"
        )->fetch();
        return [
            'pending' => (int)($row['pending'] ?? 0),
            'failed' => (int)($row['failed'] ?? 0),
            'sent' => (int)($row['sent'] ?? 0),
        ];
    }

    public static function clearQueue(string $status = 'failed'): int
    {
        $stmt = Database::pdo()->prepare('DELETE FROM notify_queue WHERE status = ?');
        $stmt->execute([$status]);
        return $stmt->rowCount();
    }

    private function enqueue(
        int $streamerId,
        string $event,
        string $channel,
        string $title,
        string $body,
        string $url,
        string $payload
    ): int {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO notify_queue(streamer_id, event, channel, title, body, url, context, status, created_at)
             VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$streamerId, $event, $channel, $title, $body, $url, $payload, 'pending', now()]);
        return (int)$pdo->lastInsertId();
    }

    private function row(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM notify_queue WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * 发送一行；成功置 sent，失败累加 attempts 并安排下次重试。
     */
    public function attempt(int $id): bool
    {
        $row = $this->row($id);
        if ($row === null || $row['status'] !== 'pending') {
            return false;
        }
        $decoded = json_decode((string)$row['context'], true);
        $payload = is_array($decoded) ? $decoded : [];

        try {
            $this->deliver(
                (string)$row['channel'],
                (string)$row['event'],
                (string)$row['title'],
                (string)$row['body'],
                (string)$row['url'],
                $payload
            );
        } catch (Throwable $e) {
            return $this->recordAttemptFailure($row, $e->getMessage());
        }

        $stmt = Database::pdo()->prepare(
            "UPDATE notify_queue SET status = 'sent', sent_at = ?, last_error = NULL, next_retry_at = NULL
             WHERE id = ?"
        );
        $stmt->execute([now(), $id]);
        return true;
    }

    private function recordAttemptFailure(array $row, string $error): bool
    {
        $attempts = (int)$row['attempts'] + 1;
        $max = max(1, (int)($this->settings['notify_retry_max'] ?? 3));
        $base = max(15, (int)($this->settings['notify_retry_backoff'] ?? 120));
        $exhausted = $attempts >= $max;

        $next = null;
        if (!$exhausted) {
            $wait = min($base * (2 ** ($attempts - 1)), 3600);
            $next = date('Y-m-d H:i:s', time() + $wait);
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE notify_queue SET attempts = ?, last_error = ?, next_retry_at = ?, status = ? WHERE id = ?'
        );
        $stmt->execute([
            $attempts,
            mb_substr($error, 0, 300),
            $next,
            $exhausted ? 'failed' : 'pending',
            (int)$row['id'],
        ]);
        $this->log(
            $row['channel'] . ' 第 ' . $attempts . ' 次发送失败：' . $error
            . ($exhausted ? '（已放弃）' : '（将于 ' . $next . ' 重试）')
        );
        return false;
    }

    private function deliver(string $channel, string $event, string $title, string $body, string $url, array $payload): void
    {
        $streamer = is_array($payload['streamer'] ?? null) ? $payload['streamer'] : [];
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $liveUrl = (string)($payload['live_url'] ?? $url);

        switch ($channel) {
            case 'serverchan':
                $this->sendServerChan(trim((string)$this->settings['notify_serverchan_key']), $title, $body);
                return;
            case 'bark':
                $this->sendBark(trim((string)$this->settings['notify_bark_url']), $title, $body, $url);
                return;
            case 'webhook':
                $this->sendWebhook(trim((string)$this->settings['notify_webhook']), $event, $title, $body, $streamer, $context);
                return;
            case 'mail':
                $this->sendMail($title, $body, $liveUrl, $streamer, $context);
                return;
        }
        throw new RuntimeException('未知渠道 ' . $channel);
    }

    private function sendServerChan(string $key, string $title, string $body): void
    {
        if ($key === '') {
            throw new RuntimeException('Server酱 SendKey 未配置');
        }
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
            throw new RuntimeException('Server酱 推送失败：' . ($res['error'] ?: 'HTTP ' . $res['status']));
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
            throw new RuntimeException('Bark 推送失败：' . ($res['error'] ?: 'HTTP ' . $res['status']));
        }
    }

    private function sendWebhook(
        string $url,
        string $event,
        string $title,
        string $body,
        array $streamer,
        array $context
    ): void {
        if ($url === '') {
            throw new RuntimeException('Webhook 地址未配置');
        }
        $payload = json_encode([
            'event' => $event,
            'title' => $title,
            'body' => $body,
            'streamer' => $streamer,
            'context' => $context,
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
            throw new RuntimeException('Webhook 推送失败：' . ($res['error'] ?: 'HTTP ' . $res['status']));
        }
    }

    private function sendMail(string $title, string $body, string $liveUrl, array $streamer, array $context): void
    {
        $recipients = self::recipients((string)($this->settings['smtp_to'] ?? ''));
        if ($recipients === []) {
            throw new RuntimeException('邮件通知失败：收件地址均不合法');
        }
        $from = trim((string)($this->settings['smtp_from'] ?? '')) ?: trim((string)($this->settings['smtp_user'] ?? ''));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('邮件通知失败：发件地址未填写或不合法');
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
            throw new RuntimeException('邮件通知失败：' . $e->getMessage(), 0, $e);
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
