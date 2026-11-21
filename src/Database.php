<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('缺少 pdo_sqlite 扩展，请在 php.ini 中启用 extension=pdo_sqlite');
        }
        if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0777, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('无法创建 data 目录，请检查目录权限');
        }
        $pdo = new PDO('sqlite:' . DATA_DIR . '/monitor.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=8000');
        self::$pdo = $pdo;
        return $pdo;
    }

    public static function init(): void
    {
        $pdo = self::pdo();
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS streamers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    platform TEXT NOT NULL DEFAULT 'douyin',
    sec_uid TEXT,
    web_rid TEXT,
    name TEXT NOT NULL,
    avatar TEXT,
    url TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    is_live INTEGER NOT NULL DEFAULT 0,
    live_title TEXT,
    live_cover TEXT,
    live_room_id TEXT,
    live_since TEXT,
    last_live_at TEXT,
    last_check_at TEXT,
    last_error TEXT,
    fail_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    streamer_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    title TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (streamer_id) REFERENCES streamers(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_events_streamer ON events(streamer_id, id DESC);
CREATE UNIQUE INDEX IF NOT EXISTS ux_streamers_rid ON streamers(platform, web_rid)
    WHERE web_rid IS NOT NULL AND web_rid <> '';
CREATE UNIQUE INDEX IF NOT EXISTS ux_streamers_sec ON streamers(platform, sec_uid)
    WHERE sec_uid IS NOT NULL AND sec_uid <> '';
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
SQL);
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES(?, ?)');
        foreach (self::defaults() as $key => $value) {
            $stmt->execute([$key, $value]);
        }
    }

    public static function defaults(): array
    {
        return [
            'check_interval' => '60',
            'request_delay' => '3',
            'request_timeout' => '20',
            'verify_ssl' => '1',
            'ssl_fallback_at' => '',
            'douyin_cookie' => '',
            'notify_on_online' => '1',
            'notify_on_offline' => '1',
            'notify_serverchan_key' => '',
            'notify_bark_url' => '',
            'notify_webhook' => '',
            'smtp_host' => '',
            'smtp_port' => '465',
            'smtp_secure' => 'ssl',
            'smtp_user' => '',
            'smtp_pass' => '',
            'smtp_from' => '',
            'smtp_from_name' => '',
            'smtp_to' => '',
            'monitor_heartbeat' => '',
        ];
    }

    public static function setting(string $key, ?string $default = null): ?string
    {
        $stmt = self::pdo()->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return $default ?? (self::defaults()[$key] ?? null);
        }
        return (string)$value;
    }

    public static function settings(): array
    {
        $out = self::defaults();
        foreach (self::pdo()->query('SELECT key, value FROM settings') as $row) {
            $out[$row['key']] = $row['value'];
        }
        return $out;
    }

    public static function setSetting(string $key, string $value): void
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO settings(key, value) VALUES(?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([$key, $value]);
    }

    public static function setSettings(array $pairs): void
    {
        $pdo = self::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO settings(key, value) VALUES(?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $pdo->beginTransaction();
        try {
            foreach ($pairs as $key => $value) {
                $stmt->execute([$key, (string)$value]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}