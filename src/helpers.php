<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Shanghai');

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function jsonOut(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $json = json_encode($data, $flags);
    if ($json === false) {
        $json = json_encode($data, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    echo $json === false ? '{}' : $json;
    exit;
}

function utf8Input(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (!function_exists('mb_check_encoding') || mb_check_encoding($value, 'UTF-8')) {
        return $value;
    }
    $converted = @mb_convert_encoding($value, 'UTF-8', 'GB18030');
    if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
        return $converted;
    }
    return $value;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrfCheck(): void
{
    $token = (string)($_POST['_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) {
        http_response_code(400);
        exit('CSRF token 无效，请刷新页面重试');
    }
}

function flash(?string $msg = null, string $type = 'success'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirectTo(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function timeAgo(?string $ts): string
{
    if (!$ts) {
        return '从未';
    }
    $diff = time() - (int)strtotime($ts);
    if ($diff < 5) {
        return '刚刚';
    }
    if ($diff < 60) {
        return $diff . ' 秒前';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' 分钟前';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' 小时前';
    }
    return floor($diff / 86400) . ' 天前';
}

function formatDuration(?string $since): string
{
    if (!$since) {
        return '';
    }
    $sec = max(0, time() - (int)strtotime($since));
    if ($sec < 60) {
        return $sec . ' 秒';
    }
    if ($sec < 3600) {
        return floor($sec / 60) . ' 分钟';
    }
    return floor($sec / 3600) . ' 小时 ' . floor(($sec % 3600) / 60) . ' 分';
}

function initial(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    return function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
}