<?php
declare(strict_types=1);
/** @var array $streamer */
/** @var array $events */
/** @var int $onlineTimes */
/** @var int $streamerId */

$hf = static function (string $action, int $id = 0, string $back = ''): string {
    $back = $back !== '' ? $back : 'index.php?page=streamer&id=' . $id;
    $fields = '<input type="hidden" name="_token" value="' . h(csrfToken()) . '">'
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="back" value="' . h($back) . '">';
    if ($id > 0) {
        $fields .= '<input type="hidden" name="id" value="' . $id . '">';
    }
    return $fields;
};

$live = (int)$streamer['is_live'] === 1;
$enabled = (int)$streamer['enabled'] === 1;
$liveUrl = $streamer['web_rid']
    ? 'https://live.douyin.com/' . rawurlencode((string)$streamer['web_rid'])
    : (string)$streamer['url'];
?>
<a class="back" href="index.php">返回监控看板</a>

<section class="detail-head card <?= $live ? 'card-live' : '' ?><?= $enabled ? '' : ' card-paused' ?>"
         data-streamer-id="<?= $streamerId ?>" data-live="<?= $live ? 1 : 0 ?>">
    <div class="avatar avatar-lg <?= $live ? 'is-live' : '' ?>">
        <span class="avatar-initial"><?= h(initial((string)$streamer['name'])) ?></span>
        <?php if (!empty($streamer['avatar'])): ?>
            <img src="<?= h($streamer['avatar']) ?>" alt="" referrerpolicy="no-referrer">
        <?php endif; ?>
        <?php if ($live): ?><span class="live-tag">直播中</span><?php endif; ?>
    </div>
    <div class="detail-info">
        <h1><?= h($streamer['name']) ?></h1>
        <div class="card-status">
            <span class="status <?= $live ? 'is-live' : ($enabled ? 'is-idle' : 'is-paused') ?>" data-status-pill>
                <i></i><?= $live ? '直播中' : ($enabled ? '未开播' : '已暂停') ?>
            </span>
            <span class="muted">检测于 <b data-last-check><?= h(timeAgo($streamer['last_check_at'])) ?></b></span>
            <?php if ($live): ?>
                <span class="muted">已直播 <b data-duration data-since="<?= h((string)$streamer['live_since']) ?>"><?= h(formatDuration($streamer['live_since'])) ?></b></span>
            <?php endif; ?>
        </div>
        <?php if ($live && !empty($streamer['live_title'])): ?>
            <p class="live-title" data-live-title><?= h($streamer['live_title']) ?></p>
        <?php endif; ?>
        <?php if (!empty($streamer['last_error'])): ?>
            <p class="card-error">检测异常：<?= h($streamer['last_error']) ?>（连续 <?= (int)$streamer['fail_count'] ?> 次）</p>
        <?php endif; ?>
        <div class="card-actions">
            <a class="btn <?= $live ? 'primary' : 'ghost' ?>" href="<?= h($liveUrl) ?>" target="_blank" rel="noopener noreferrer">
                <?= $live ? '进入直播间' : '打开直播页' ?>
            </a>
            <a class="btn ghost" href="<?= h($streamer['url']) ?>" target="_blank" rel="noopener noreferrer">主播主页</a>
            <form method="post" action="index.php" data-once>
                <?= $hf('check', $streamerId) ?>
                <button class="btn ghost" type="submit">立即检测</button>
            </form>
            <form method="post" action="index.php">
                <?= $hf('toggle', $streamerId) ?>
                <button class="btn ghost" type="submit"><?= $enabled ? '暂停监控' : '恢复监控' ?></button>
            </form>
            <form method="post" action="index.php" onsubmit="return confirm('确定删除该主播及其历史记录？');">
                <?= $hf('delete', $streamerId, 'index.php') ?>
                <button class="btn danger" type="submit">删除</button>
            </form>
        </div>
    </div>
</section>

<section class="panel">
    <h2>账号信息</h2>
    <div class="kv-grid">
        <div><span>抖音号</span><b><?= h((string)($streamer['web_rid'] ?: '待解析')) ?></b></div>
        <div><span>房间号</span><b><?= h((string)($streamer['live_room_id'] ?: '—')) ?></b></div>
        <div><span>加入时间</span><b><?= h((string)$streamer['created_at']) ?></b></div>
        <div><span>最近确认开播</span><b><?= h((string)($streamer['last_live_at'] ?: '—')) ?></b></div>
        <div><span>累计开播事件</span><b><?= $onlineTimes ?> 次</b></div>
        <div><span>sec_uid</span><b class="mono-clip"><?= h((string)($streamer['sec_uid'] ?: '—')) ?></b></div>
    </div>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>开播记录</h2>
        <a class="link" href="index.php?page=history">全部记录</a>
    </div>
    <?php if ($events === []): ?>
        <div class="empty empty-sm">该主播还没有开播记录。</div>
    <?php else: ?>
        <ul class="timeline">
            <?php foreach ($events as $e): ?>
                <li class="event event-<?= h($e['type']) ?>">
                    <i></i>
                    <div class="event-body">
                        <strong><?= $e['type'] === 'online' ? '开播' : '下播' ?></strong>
                        <span><?= h((string)$e['title']) ?></span>
                        <time><?= h($e['created_at']) ?></time>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>