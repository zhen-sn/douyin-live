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

    /**
     * @param bool $force true 时忽略 next_check_at 全量检测
     */
    public function checkAll(bool $notify = true, ?callable $log = null, bool $force = false): array
    {
        $pdo = Database::pdo();
        $settings = Database::settings();
        $delay = max(0, min(60, (int)($settings['request_delay'] ?? 3)));
        $summary = [
            'checked' => 0,
            'skipped' => 0,
            'live' => 0,
            'offline' => 0,
            'errors' => 0,
            'retry' => ['checked' => 0, 'sent' => 0, 'failed' => 0],
            'gap' => null,
            'results' => [],
        ];

        $summary['gap'] = $this->recordHeartbeatGap($settings);
        if ($summary['gap'] !== null && $log !== null) {
            $log($summary['gap']);
        }

        $sql = 'SELECT * FROM streamers WHERE enabled = 1';
        $params = [];
        if (!$force) {
            $sql .= ' AND (next_check_at IS NULL OR next_check_at <= ?)';
            $params[] = now();
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY is_live DESC, id ASC');
        $stmt->execute($params);
        $streamers = $stmt->fetchAll();

        $total = (int)$pdo->query('SELECT COUNT(*) FROM streamers WHERE enabled = 1')->fetchColumn();
        $summary['skipped'] = max(0, $total - count($streamers));

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

        if ($notify) {
            $summary['retry'] = $this->notifier->retryPending();
            if ($log !== null && $summary['retry']['checked'] > 0) {
                $log(sprintf(
                    '待发通知重试 %d 条：成功 %d，放弃 %d',
                    $summary['retry']['checked'],
                    $summary['retry']['sent'],
                    $summary['retry']['failed']
                ));
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
            $this->recordFailure($id, $message, $settings);
            return ['ok' => false, 'state' => 'unknown', 'message' => $message];
        }

        try {
            $result = $this->detector->detectLive((string)$streamer['web_rid'], $settings);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'state' => 'unknown', 'error' => $e->getMessage()];
        }
        if (($result['state'] ?? '') === 'unknown' || empty($result['ok'])) {
            $message = $result['error'] ?: '检测失败';
            $this->recordFailure($id, $message, $settings);
            return ['ok' => false, 'state' => 'unknown', 'message' => $message];
        }
        if (!empty($result['ssl_fallback'])) {
            Database::setSetting('ssl_fallback_at', now());
        }

        $isLive = $result['state'] === 'live';
        $wasLive = (int)$streamer['is_live'] === 1;
        $confirm = max(0, min(5, (int)($settings['offline_confirm_rounds'] ?? 1)));

        // 下播去抖：连续 confirm 轮都看不到直播才认定下播，避免网络抖动连发事件
        if (!$isLive && $wasLive && $confirm > 0 && (int)$streamer['pending_offline'] < $confirm) {
            $round = (int)$streamer['pending_offline'] + 1;
            $this->updateStreamer($id, [
                'pending_offline' => $round,
                'last_check_at' => now(),
                'last_error' => null,
                'fail_count' => 0,
                'next_check_at' => $this->nextCheckAt($settings, 0, $streamer, true),
                'updated_at' => now(),
            ]);
            return [
                'ok' => true,
                'state' => 'live',
                'message' => '疑似下播，待复核 ' . $round . '/' . $confirm,
            ];
        }
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
            'pending_offline' => 0,
            'next_check_at' => $this->nextCheckAt($settings, 0, $streamer, $isLive),
            'updated_at' => $timestamp,
        ]);

        $message = $isLive
            ? '直播中' . (!empty($result['title']) ? '：' . $result['title'] : '')
            : '未开播';

        $fresh = $this->getStreamer($id);
        if ($isLive && !$wasLive) {
            $this->insertEvent($id, 'online', (string)($result['title'] ?? ''));
            if ($notify && $fresh) {
                $this->notifier->notify($fresh, 'online');
            }
        } elseif (!$isLive && $wasLive) {
            $this->insertEvent($id, 'offline', (string)($streamer['live_title'] ?? ''));
            if ($notify && $fresh) {
                $this->notifier->notify($fresh, 'offline', [
                    'duration' => formatDuration($streamer['live_since']),
                ]);
            }
        } elseif ($isLive && $wasLive) {
            $oldTitle = trim((string)($streamer['live_title'] ?? ''));
            $newTitle = trim((string)($result['title'] ?? ''));
            if ($newTitle !== '' && $oldTitle !== '' && $newTitle !== $oldTitle) {
                $this->insertEvent($id, 'title', $newTitle);
                if ($notify && $fresh) {
                    $this->notifier->notify($fresh, 'title', [
                        'new_title' => $newTitle,
                        'old_title' => $oldTitle,
                    ]);
                }
                $message = '直播中（标题已更新）：' . $newTitle;
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

    /**
     * 下次检测时间：失败指数退避、长期未开播降频、正常按轮询间隔。
     */
    private function nextCheckAt(array $settings, int $failCount, array $streamer, bool $isLive): string
    {
        $base = max(20, min(3600, (int)($settings['check_interval'] ?? 60)));

        if ($failCount > 0) {
            $wait = min($base * (2 ** min($failCount - 1, 4)), 900);
            return date('Y-m-d H:i:s', time() + $wait);
        }

        if (!$isLive) {
            $idleDays = max(0, (int)($settings['idle_after_days'] ?? 3));
            $idleInterval = max($base, min(86400, (int)($settings['idle_interval'] ?? 600)));
            $since = $streamer['last_live_at'] ?? ($streamer['created_at'] ?? null);
            if ($idleDays > 0 && !empty($since)) {
                $age = time() - strtotime((string)$since);
                if ($age > $idleDays * 86400) {
                    return date('Y-m-d H:i:s', time() + $idleInterval);
                }
            }
        }

        return date('Y-m-d H:i:s', time() + $base);
    }

    /**
     * 进程停摆提示：断档超过 3 个周期时记下区间，期间的整场直播可能已漏记。
     */
    private function recordHeartbeatGap(array $settings): ?string
    {
        $heartbeat = (string)($settings['monitor_heartbeat'] ?? '');
        $interval = max(20, min(3600, (int)($settings['check_interval'] ?? 60)));
        if ($heartbeat === '') {
            return null;
        }
        $gap = time() - strtotime($heartbeat);
        if ($gap < $interval * 3) {
            return null;
        }
        Database::setSettings([
            'monitor_gap_at' => $heartbeat,
            'monitor_gap_minutes' => (string)(int)round($gap / 60),
        ]);
        return sprintf('距上次心跳 %s（%s），期间的开播/下播可能已漏记', $heartbeat, formatDuration($heartbeat));
    }

    private function recordFailure(int $id, string $message, ?array $settings = null): void
    {
        $settings = $settings ?? Database::settings();
        $stmt = Database::pdo()->prepare('SELECT fail_count FROM streamers WHERE id = ?');
        $stmt->execute([$id]);
        $next = $this->nextCheckAt($settings, (int)$stmt->fetchColumn() + 1, [], false);

        $update = Database::pdo()->prepare(
            'UPDATE streamers
             SET last_check_at = ?, last_error = ?, fail_count = fail_count + 1,
                 next_check_at = ?, updated_at = ?
             WHERE id = ?'
        );
        $update->execute([now(), $message, $next, now(), $id]);
    }

    private function insertEvent(int $streamerId, string $type, string $title): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO events(streamer_id, type, title, created_at) VALUES(?, ?, ?, ?)'
        );
        $stmt->execute([$streamerId, $type, $title, now()]);
    }
}