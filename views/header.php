<?php
declare(strict_types=1);
/** @var string $page */
/** @var array $stats */
/** @var array|null $flash */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf" content="<?= h(csrfToken()) ?>">
    <title>开播雷达 · 主播直播检测</title>
    <link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body class="page-<?= h($page) ?>">
<div class="glow glow-a" aria-hidden="true"></div>
<div class="glow glow-b" aria-hidden="true"></div>

<div class="shell">
    <header class="topbar">
        <a class="brand" href="index.php">
            <span class="brand-mark" aria-hidden="true"><span></span></span>
            <span class="brand-text">
                <strong>开播雷达</strong>
                <em>Douyin Live Monitor</em>
            </span>
        </a>
        <nav class="nav">
            <a href="index.php" class="<?= in_array($page, ['home', 'streamer'], true) ? 'active' : '' ?>">监控看板</a>
            <a href="index.php?page=history" class="<?= $page === 'history' ? 'active' : '' ?>">开播记录</a>
            <a href="index.php?page=settings" class="<?= $page === 'settings' ? 'active' : '' ?>">设置</a>
        </nav>
        <div class="top-side">
            <span class="monitor-state <?= $stats['monitor_alive'] ? 'is-on' : '' ?>">
                <i></i>后台监控<?= $stats['monitor_alive'] ? '运行中' : '未运行' ?>
            </span>
            <span class="top-live" data-top-live><i></i><b><?= (int)$stats['live'] ?></b> 个直播中</span>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash flash-<?= h($flash['type']) ?>">
            <span><?= h($flash['msg']) ?></span>
            <button type="button" aria-label="关闭提示">&times;</button>
        </div>
    <?php endif; ?>

    <main class="main">