<?php
declare(strict_types=1);

final class MonitorService
{
    private DouyinDetector $detector;
    private Notifier $notifier;

    public function __construct(?DouyinDetector $detector = null, ?Notifier $notifier = null)
    {
        $this->detector = $detector ?? new DouyinDetector();
        $this->notifier = $notifier ?? new Notifier();
    }

    public function checkAll(bool $notify = true, ?callable $log = null): array
    {
        $pdo = Database::pdo();
        $streamers = $pdo->query('SELECT * FROM streamers WHERE enabled = 1 ORDER BY is_live DESC, id ASC')->fetchAll();
        $settings = Database::settings();
        $delay = max(0, min(60, (int)($settings['request_delay'] ?? 3)));
        $summary = ['checked' => 0, 'live' => 0, 'offline' => 0, 'errors' => 0, 'results' => []];
        $lastIndex = count($streamers) - 1;

        foreach ($streamers as $index => $streamer) {
            if ($index > 0 && $delay > 0) {
                usleep($delay * 1000000 + random_int(0, 800) * 1000);
            }
            $result = $this->checkOne((int)$streamer['id'], $notify);
            $summary['checked']++;
            $state = $result['state'] ?? 'unknown';
            if ($state === 'live') {
                $summary['live']++;
            } elseif ($state === 'offline') {
                $summary['offline']++;
            } else {
                $summary['errors']++;
            }
            $summary['results'][] = ['id' => (int)$streamer['id'], 'state' => $state, 'message' => $result['message'] ?? ''];
            if ($log !== null) {
                $log($streamer['name'] . ' → ' . ($result['message'] ?? $state));
            }
        }
        Database::setSetting('monitor_heartbeat', now());
        return $summary;
    }

    public function checkOne(int $id, bool $notify = true): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM streamers WHERE id = ?');
        $stmt->execute([$id]);
        $streamer = $stmt->fetch();
        if (!$streamer) {
            return ['ok' => false, 'state' => 'unknown', 'message' => '主播不存在'];
        }

        $settings = Database::settings();
        if (empty($streamer['web_rid']) && !empty($streamer['sec_uid'])) {
            $profile = $this->detector->resolveProfile((string)$streamer['sec_uid'], $settings);
            if ($profile['ok']) {
                $streamer['web_rid'] = $profile['web_rid'];
                $streamer['name'] = $profile['nickname'] ?: $streamer['name'];
                if (!empty($profile['avatar'])) {
                    $streamer['avatar'] = $profile['avatar'];
                }
                $this->updateStreamer($id, [
                    'web_rid' => $streamer['web_rid'],
                    'name' => $streamer['name'],
                    'avatar' => $streamer['avatar'],
                ]);
            }
        }
        if (empty($streamer['web_rid'])) {
            $message = '缺少抖音号，无法检测；请重新添加主页链接或直接填写抖音号';
            $this->recordFailure($id, $message);
            return ['ok' => false, 'state' => 'unknown', 'message' => $message];
        }

        try {
            $result = $this->detector->detectLive((string)$streamer['web_rid'], $settings);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'state' => 'unknown', 'error' => $e->getMessage()];
        }
        if (($result['state'] ?? '') === 'unknown' || empty($result['ok'])) {
            $message = $result['error'] ?: '检测失败';
            $this->recordFailure($id, $message);
            return ['ok' => false, 'state' => 'unknown', 'message' => $message];
        }
        if (!empty($result['ssl_fallback'])) {
            Database::setSetting('ssl_fallback_at', now());
        }

        $isLive = $result['state'] === 'live';
        $wasLive = (int)$streamer['is_live'] === 1;
        $name = trim((string)$streamer['name']);
        $nickname = trim((string)($result['nickname'] ?? ''));
        if ($nickname !== '' && ($name === '' || $name === (string)$streamer['web_rid'] || $name === (string)$streamer['sec_uid'])) {
            $name = $nickname;
        }
        $avatar = (string)$streamer['avatar'];
        if ($avatar === '' && !empty($result['avatar'])) {
            $avatar = (string)$result['avatar'];
        }
        $timestamp = now();
        $liveSince = $streamer['live_since'];
        if ($isLive && !$wasLive) {
            $liveSince = $timestamp;
        } elseif (!$isLive) {
            $liveSince = null;
        }
        $liveTitle = $isLive ? (string)($result['title'] ?? '') : null;
        $liveCover = $isLive ? (string)($result['cover'] ?? '') : null;
        $roomId = $isLive ? (string)($result['room_id'] ?? '') : null;

        $this->updateStreamer($id, [
            'name' => $name,
            'avatar' => $avatar,
            'is_live' => $isLive ? 1 : 0,
            'live_title' => $liveTitle,
            'live_cover' => $liveCover,
            'live_room_id' => $roomId,
            'live_since' => $liveSince,
            'last_live_at' => $isLive ? $timestamp : $streamer['last_live_at'],
            'last_check_at' => $timestamp,
            'last_error' => null,
            'fail_count' => 0,
            'updated_at' => $timestamp,
        ]);

        $message = $isLive
            ? '直播中' . (!empty($result['title']) ? '：' . $result['title'] : '')
            : '未开播';

        if ($isLive && !$wasLive) {
            $this->insertEvent($id, 'online', (string)($result['title'] ?? ''));
            $fresh = $this->getStreamer($id);
            if ($notify && $fresh) {
                $this->notifier->notify($fresh, 'online');
            }
        } elseif (!$isLive && $wasLive) {
            $this->insertEvent($id, 'offline', (string)($streamer['live_title'] ?? ''));
            $fresh = $this->getStreamer($id);
            if ($notify && $fresh) {
                $this->notifier->notify($fresh, 'offline', [
                    'duration' => formatDuration($streamer['live_since']),
                ]);
            }
        }

        return ['ok' => true, 'state' => $isLive ? 'live' : 'offline', 'message' => $message];
    }

    public function getStreamer(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM streamers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function updateStreamer(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $sets = [];
        $values = [];
        foreach ($fields as $key => $value) {
            $sets[] = $key . ' = ?';
            $values[] = $value;
        }
        $values[] = $id;
        $stmt = Database::pdo()->prepare('UPDATE streamers SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    private function recordFailure(int $id, string $message): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE streamers
             SET last_check_at = ?, last_error = ?, fail_count = fail_count + 1, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([now(), $message, now(), $id]);
    }

    private function insertEvent(int $streamerId, string $type, string $title): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO events(streamer_id, type, title, created_at) VALUES(?, ?, ?, ?)'
        );
        $stmt->execute([$streamerId, $type, $title, now()]);
    }
}