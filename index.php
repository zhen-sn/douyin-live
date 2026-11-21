<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$pdo = Database::pdo();
$settings = Database::settings();
$page = (string)($_GET['page'] ?? 'home');
$action = (string)($_POST['action'] ?? '');
$detector = new DouyinDetector();
$service = new MonitorService($detector);

$safeBack = static function (string $back, string $default = 'index.php'): string {
    return str_starts_with($back, 'index.php') ? $back : $default;
};
$clamp = static function (int $value, int $min, int $max): int {
    return max($min, min($max, $value));
};

if ($action !== '') {
    csrfCheck();
    $back = $safeBack((string)($_POST['back'] ?? ''));
    try {
        switch ($action) {
            case 'add':
                $target = utf8Input((string)($_POST['target'] ?? ''));
                $name = utf8Input((string)($_POST['name'] ?? ''));
                $parsed = DouyinDetector::parseTarget($target);
                $secUid = $parsed['sec_uid'];
                $webRid = $parsed['web_rid'];
                $avatar = '';
                $profileNote = '';
                if ($secUid !== null && !$webRid) {
                    $profile = $detector->resolveProfile((string)$secUid, $settings);
                    if ($profile['ok']) {
                        $webRid = $profile['web_rid'];
                        $avatar = (string)$profile['avatar'];
                        if ($name === '') {
                            $name = (string)$profile['nickname'];
                        }
                    } else {
                        $profileNote = '（' . $profile['error'] . '）';
                    }
                }
                if ($secUid === null && !$webRid) {
                    throw new RuntimeException('无法识别输入内容，请粘贴抖音主页链接、sec_uid 或抖音号');
                }
                if ($name === '') {
                    $name = (string)($webRid ?: ('主播' . substr((string)$secUid, -6)));
                }
                $url = $secUid
                    ? 'https://www.douyin.com/user/' . $secUid
                    : 'https://live.douyin.com/' . $webRid;
                $dup = $pdo->prepare(
                    "SELECT id FROM streamers WHERE platform = 'douyin'
                       AND ((? <> '' AND web_rid = ?) OR (? <> '' AND sec_uid = ?)) LIMIT 1"
                );
                $dup->execute([$webRid, $webRid, $secUid, $secUid]);
                if ($dup->fetchColumn()) {
                    throw new RuntimeException('该主播已在监控列表中');
                }
                $ts = now();
                $pdo->prepare(
                    'INSERT INTO streamers (platform, sec_uid, web_rid, name, avatar, url, enabled, is_live, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,1,0,?,?)'
                )->execute(['douyin', $secUid, $webRid, $name, $avatar, $url, $ts, $ts]);
                $newId = (int)$pdo->lastInsertId();
                $checkNote = $webRid ? '' : '，未取得抖音号，监控时会自动重试' . $profileNote;
                if ($webRid) {
                    try {
                        $first = $service->checkOne($newId, false);
                        $checkNote = '，初始状态：' . ($first['message'] ?? '未知');
                    } catch (Throwable $e) {
                        $checkNote = '，初始检测失败：' . $e->getMessage();
                    }
                }
                flash('已添加主播 ' . $name . $checkNote);
                redirectTo('index.php');

            case 'toggle':
                $id = (int)($_POST['id'] ?? 0);
                $pdo->prepare('UPDATE streamers SET enabled = 1 - enabled, updated_at = ? WHERE id = ?')
                    ->execute([now(), $id]);
                flash('已切换该主播的监控开关');
                redirectTo($back);

            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                $pdo->prepare('DELETE FROM events WHERE streamer_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM streamers WHERE id = ?')->execute([$id]);
                flash('已删除该主播及其历史记录');
                redirectTo('index.php');

            case 'check':
                $id = (int)($_POST['id'] ?? 0);
                $result = $service->checkOne($id, true);
                flash('检测完成：' . ($result['message'] ?? ''), ($result['ok'] ?? false) ? 'success' : 'error');
                redirectTo($back);

            case 'check_all':
                set_time_limit(1800);
                $summary = $service->checkAll(true);
                flash(sprintf(
                    '批量检测完成：共 %d 位，直播中 %d，未开播 %d，异常 %d',
                    $summary['checked'],
                    $summary['live'],
                    $summary['offline'],
                    $summary['errors']
                ), $summary['errors'] > 0 ? 'warn' : 'success');
                redirectTo('index.php');

            case 'settings':
                $webhook = utf8Input((string)($_POST['notify_webhook'] ?? ''));
                if ($webhook !== '' && !preg_match('#^https?://#i', $webhook)) {
                    throw new RuntimeException('Webhook 地址必须以 http:// 或 https:// 开头');
                }
                $smtpHost = utf8Input((string)($_POST['smtp_host'] ?? ''));
                $smtpSecure = in_array((string)($_POST['smtp_secure'] ?? 'ssl'), ['ssl', 'tls', 'none'], true)
                    ? (string)$_POST['smtp_secure']
                    : 'ssl';
                $smtpUser = utf8Input((string)($_POST['smtp_user'] ?? ''));
                $smtpFrom = utf8Input((string)($_POST['smtp_from'] ?? ''));
                $smtpToRaw = utf8Input((string)($_POST['smtp_to'] ?? ''));
                $smtpTo = Notifier::recipients($smtpToRaw);
                if ($smtpHost !== '') {
                    if ($smtpFrom !== '' && !filter_var($smtpFrom, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException('SMTP 发件地址格式不正确');
                    }
                    if ($smtpToRaw !== '' && $smtpTo === []) {
                        throw new RuntimeException('SMTP 收件地址格式不正确，多个地址用逗号分隔');
                    }
                }
                $pairs = [
                    'check_interval' => (string)$clamp((int)($_POST['check_interval'] ?? 60), 20, 3600),
                    'request_delay' => (string)$clamp((int)($_POST['request_delay'] ?? 3), 0, 60),
                    'request_timeout' => (string)$clamp((int)($_POST['request_timeout'] ?? 20), 5, 60),
                    'verify_ssl' => isset($_POST['verify_ssl']) ? '1' : '0',
                    'douyin_cookie' => utf8Input((string)($_POST['douyin_cookie'] ?? '')),
                    'notify_on_online' => isset($_POST['notify_on_online']) ? '1' : '0',
                    'notify_on_offline' => isset($_POST['notify_on_offline']) ? '1' : '0',
                    'notify_serverchan_key' => utf8Input((string)($_POST['notify_serverchan_key'] ?? '')),
                    'notify_bark_url' => utf8Input((string)($_POST['notify_bark_url'] ?? '')),
                    'notify_webhook' => $webhook,
                    'smtp_host' => $smtpHost,
                    'smtp_port' => (string)$clamp((int)($_POST['smtp_port'] ?? 465), 1, 65535),
                    'smtp_secure' => $smtpSecure,
                    'smtp_user' => $smtpUser,
                    'smtp_from' => $smtpFrom,
                    'smtp_from_name' => utf8Input((string)($_POST['smtp_from_name'] ?? '')),
                    'smtp_to' => implode(', ', $smtpTo),
                ];
                $smtpPass = (string)($_POST['smtp_pass'] ?? '');
                if ($smtpPass !== '') {
                    $pairs['smtp_pass'] = $smtpPass;
                }
                Database::setSettings($pairs);
                flash('设置已保存');
                redirectTo('index.php?page=settings');

            case 'test_notify':
                $channels = array_filter([
                    'Server酱' => trim((string)($settings['notify_serverchan_key'] ?? '')),
                    'Bark' => trim((string)($settings['notify_bark_url'] ?? '')),
                    'Webhook' => trim((string)($settings['notify_webhook'] ?? '')),
                    '邮件' => Mailer::configured($settings) ? 'on' : '',
                ], static fn(string $v): bool => $v !== '');
                if ($channels === []) {
                    throw new RuntimeException('请先在下方填写至少一个推送渠道并保存');
                }
                $fake = [
                    'id' => 0,
                    'name' => '测试主播',
                    'web_rid' => 'test',
                    'url' => 'https://www.douyin.com/',
                    'live_title' => '这是一条测试直播标题',
                    'live_since' => date('Y-m-d H:i:s', time() - 600),
                ];
                $notifier = new Notifier($settings);
                $notifier->notify($fake, 'online', [], true);
                $names = implode('、', array_keys($channels));
                $errors = $notifier->errors();
                if ($errors !== []) {
                    flash('已尝试 ' . $names . '，失败：' . implode('；', $errors), 'error');
                } else {
                    flash('已发送测试通知到 ' . $names . ($settings['notify_on_online'] === '1' ? '' : '（测试不受「开播时推送」开关限制，正式提醒仍需勾选）'));
                }
                redirectTo('index.php?page=settings');

            case 'clear_events':
                $pdo->exec('DELETE FROM events');
                flash('历史记录已清空');
                redirectTo('index.php?page=history');

            default:
                throw new RuntimeException('未知操作');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'error');
        redirectTo($back);
    }
}

if (($_GET['api'] ?? '') === 'status') {
    $rows = $pdo->query(
        'SELECT id, name, web_rid, is_live, live_title, live_since, last_check_at, last_error
         FROM streamers ORDER BY id ASC'
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['is_live'] = (int)$row['is_live'];
        $row['last_check_ago'] = timeAgo($row['last_check_at']);
        $row['live_duration'] = $row['is_live'] === 1 ? formatDuration($row['live_since']) : '';
    }
    unset($row);
    jsonOut([
        'at' => now(),
        'live_count' => count(array_filter($rows, static fn(array $r): bool => $r['is_live'] === 1)),
        'heartbeat' => (string)($settings['monitor_heartbeat'] ?? ''),
        'streamers' => $rows,
    ]);
}

$flash = flash();
$totalStreamers = (int)$pdo->query('SELECT COUNT(*) FROM streamers')->fetchColumn();
$liveCount = (int)$pdo->query('SELECT COUNT(*) FROM streamers WHERE is_live = 1')->fetchColumn();
$todayStmt = $pdo->prepare('SELECT COUNT(*) FROM events WHERE created_at >= ?');
$todayStmt->execute([date('Y-m-d 00:00:00')]);
$todayEvents = (int)$todayStmt->fetchColumn();
$heartbeat = (string)($settings['monitor_heartbeat'] ?? '');
$heartbeatAge = $heartbeat !== '' ? time() - (int)strtotime($heartbeat) : null;
$monitorAlive = $heartbeatAge !== null
    && $heartbeatAge <= max(180, ((int)($settings['check_interval'] ?? 60)) * 3);
$stats = [
    'total' => $totalStreamers,
    'live' => $liveCount,
    'today_events' => $todayEvents,
    'heartbeat' => $heartbeat,
    'monitor_alive' => $monitorAlive,
];

if ($page === 'home') {
    $streamers = $pdo->query(
        'SELECT * FROM streamers ORDER BY is_live DESC, enabled DESC, id ASC'
    )->fetchAll();
    $eventStmt = $pdo->query(
        'SELECT e.*, s.name, s.web_rid FROM events e
         JOIN streamers s ON s.id = e.streamer_id
         ORDER BY e.id DESC LIMIT 12'
    );
    $events = $eventStmt->fetchAll();
} elseif ($page === 'streamer') {
    $streamerId = (int)($_GET['id'] ?? 0);
    $streamer = $service->getStreamer($streamerId);
    if ($streamer === null) {
        http_response_code(404);
        $page = 'notfound';
    } else {
        $eventStmt = $pdo->prepare(
            'SELECT * FROM events WHERE streamer_id = ? ORDER BY id DESC LIMIT 100'
        );
        $eventStmt->execute([$streamerId]);
        $events = $eventStmt->fetchAll();
        $onlineStmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE streamer_id = ? AND type = 'online'");
        $onlineStmt->execute([$streamerId]);
        $onlineTimes = (int)$onlineStmt->fetchColumn();
    }
} elseif ($page === 'history') {
    $events = $pdo->query(
        'SELECT e.*, s.name, s.web_rid FROM events e
         JOIN streamers s ON s.id = e.streamer_id
         ORDER BY e.id DESC LIMIT 300'
    )->fetchAll();
} elseif ($page === 'settings') {
    $checks = [
        ['PHP 版本', PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>=')],
        ['cURL 扩展', extension_loaded('curl') ? '已启用' : '未启用（回退到流式请求）', extension_loaded('curl')],
        ['PDO SQLite', extension_loaded('pdo_sqlite') ? '已启用' : '未启用', extension_loaded('pdo_sqlite')],
        [
            'OpenSSL 扩展',
            extension_loaded('openssl') ? '已启用（邮件通知可用 SSL/STARTTLS）' : '未启用（邮件只能用明文端口）',
            extension_loaded('openssl'),
        ],
        ['数据目录', is_writable(DATA_DIR) ? DATA_DIR . ' 可写' : DATA_DIR . ' 不可写', is_writable(DATA_DIR)],
        ['数据库文件', is_file(DATA_DIR . '/monitor.sqlite') ? '已就绪' : '待创建', is_file(DATA_DIR . '/monitor.sqlite')],
        [
            'HTTPS 证书校验',
            ($settings['ssl_fallback_at'] ?? '') === ''
                ? '正常'
                : '因系统证书链已自动降级（' . $settings['ssl_fallback_at'] . '）',
            ($settings['ssl_fallback_at'] ?? '') === '',
        ],
    ];
}

require __DIR__ . '/views/header.php';
if ($page === 'home') {
    require __DIR__ . '/views/home.php';
} elseif ($page === 'streamer') {
    require __DIR__ . '/views/streamer.php';
} elseif ($page === 'history') {
    require __DIR__ . '/views/history.php';
} elseif ($page === 'settings') {
    require __DIR__ . '/views/settings.php';
} else {
    require __DIR__ . '/views/notfound.php';
}
require __DIR__ . '/views/footer.php';