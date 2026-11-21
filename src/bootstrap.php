<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/DouyinDetector.php';
require_once __DIR__ . '/Notifier.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/MonitorService.php';

foreach ([DATA_DIR, DATA_DIR . '/cookies', DATA_DIR . '/logs'] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_name('kaibo_radar');
    session_start();
}

Database::init();