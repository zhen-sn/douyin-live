<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

function monitorLog(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    fwrite(STDOUT, $line);
    $dir = DATA_DIR . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($dir . '/monitor.log', $line, FILE_APPEND);
}

$argv = $_SERVER['argv'] ?? [];
$once = in_array('--once', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$forceAll = in_array('--all', $argv, true);
$onlyId = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--id=')) {
        $onlyId = (int)substr($arg, 5);
    }
    if (preg_match('/^--interval=(\d+)$/', $arg, $m)) {
        Database::setSetting('check_interval', (string)max(10, (int)$m[1]));
    }
}

$lock = fopen(DATA_DIR . '/monitor.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    monitorLog('已有监控进程在运行，本次启动退出');
    exit(1);
}

$service = new MonitorService();
$log = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        monitorLog($message);
    }
};

$log('开播雷达监控启动（PID ' . getmypid() . '）');

if ($onlyId > 0) {
    $result = $service->checkOne($onlyId, true);
    $log('主播 #' . $onlyId . ' → ' . ($result['message'] ?? '无结果'));
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(($result['ok'] ?? false) ? 0 : 1);
}

$exitCode = 0;
while (true) {
    $settings = Database::settings();
    try {
        $progress = $quiet ? null : static function (string $message) use ($log): void {
            $log('  ' . $message);
        };
        $summary = $service->checkAll(true, $progress, $forceAll);
        $log(sprintf(
            '本轮完成：检测 %d 位，直播中 %d，未开播 %d，异常 %d，未到时间跳过 %d',
            $summary['checked'],
            $summary['live'],
            $summary['offline'],
            $summary['errors'],
            $summary['skipped']
        ));
        if ($summary['retry']['checked'] > 0) {
            $log(sprintf(
                '通知重试：处理 %d 条，补发成功 %d，放弃 %d',
                $summary['retry']['checked'],
                $summary['retry']['sent'],
                $summary['retry']['failed']
            ));
        }
        if ($summary['errors'] > 0) {
            $exitCode = 1;
        }
    } catch (Throwable $e) {
        $exitCode = 1;
        $log('监控轮次异常：' . $e->getMessage());
    }
    if ($once) {
        break;
    }
    $interval = max(10, (int)($settings['check_interval'] ?? 60));
    // 下一次唤醒：轮询间隔与最近一个到期时间取较早者，避免空转
    $nextDue = Database::pdo()
        ->query('SELECT MIN(next_check_at) FROM streamers WHERE enabled = 1 AND next_check_at IS NOT NULL')
        ->fetchColumn();
    $wait = $interval;
    if (is_string($nextDue) && $nextDue !== '') {
        $wait = min($interval, max(5, strtotime($nextDue) - time()));
    }
    $log('等待 ' . $wait . ' 秒');
    $deadline = time() + $wait;
    while (time() < $deadline) {
        sleep(min(3, max(1, $deadline - time())));
    }
}

flock($lock, LOCK_UN);
fclose($lock);
exit($exitCode);