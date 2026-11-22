<?php
declare(strict_types=1);
/** @var array $events */
/** @var array $stats */
/** @var array $queueRows */
$eventLabels = ['online' => '开播', 'offline' => '下播', 'title' => '改标题'];
?>
<div class="section-head">
    <div>
        <h1 class="page-title">开播记录</h1>
        <p class="hint">展示最近 300 条开播、下播与标题变更事件（共今日 <?= (int)$stats['today_events'] ?> 条）。</p>
    </div>
    <form method="post" action="index.php" onsubmit="return confirm('确定清空全部历史记录？');">
        <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="clear_events">
        <button class="btn ghost danger" type="submit">清空记录</button>
    </form>
</div>

<?php if ($events === []): ?>
    <div class="empty">暂无记录。检测到主播开播或下播后会自动写入。</div>
<?php else: ?>
    <div class="panel table-panel">
        <table class="table">
            <thead>
            <tr>
                <th>时间</th>
                <th>主播</th>
                <th>事件</th>
                <th>直播标题</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($events as $e): ?>
                <tr class="row-<?= h($e['type']) ?>">
                    <td class="mono"><?= h($e['created_at']) ?></td>
                    <td>
                        <a class="link" href="index.php?page=streamer&amp;id=<?= (int)$e['streamer_id'] ?>">
                            <?= h($e['name']) ?>
                        </a>
                        <span class="rid-inline"><?= $e['web_rid'] ? h($e['web_rid']) : '' ?></span>
                    </td>
                    <td>
                        <?php $label = $eventLabels[$e['type']] ?? $e['type']; ?>
                        <span class="status <?= $e['type'] === 'online' ? 'is-live' : 'is-idle' ?>">
                            <i></i><?= h($label) ?>
                        </span>
                    </td>
                    <td><?= h((string)$e['title']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<section class="panel table-panel queue-panel">
    <h2>通知发送队列</h2>
    <p class="hint">
        已送达 <?= (int)$stats['queue']['sent'] ?> 条；待发 <?= (int)$stats['queue']['pending'] ?> 条会按指数退避自动重试；
        放弃 <?= (int)$stats['queue']['failed'] ?> 条表示重试次数已用完。
    </p>
    <div class="form-actions queue-actions">
        <form class="inline-form" method="post" action="index.php" data-once>
            <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="retry_queue">
            <button class="btn ghost" type="submit">立即重试待发通知</button>
        </form>
        <?php if ($stats['queue']['failed'] > 0): ?>
            <form class="inline-form" method="post" action="index.php" data-once>
                <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="clear_queue">
                <input type="hidden" name="queue_status" value="failed">
                <button class="btn ghost danger" type="submit">清理已放弃</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($queueRows === []): ?>
        <div class="empty">没有待发或失败的通知。</div>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th>时间</th>
                <th>主播</th>
                <th>事件</th>
                <th>渠道</th>
                <th>状态</th>
                <th>次数</th>
                <th>下次重试</th>
                <th>错误</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($queueRows as $q): ?>
                <tr>
                    <td class="mono"><?= h($q['created_at']) ?></td>
                    <td><?= h((string)($q['name'] ?? '#' . $q['streamer_id'])) ?></td>
                    <td><?= h($eventLabels[$q['event']] ?? $q['event']) ?></td>
                    <td><?= h($q['channel']) ?></td>
                    <td>
                        <span class="status <?= $q['status'] === 'pending' ? 'is-idle' : 'is-live' ?>">
                            <i></i><?= $q['status'] === 'pending' ? '待发' : '放弃' ?>
                        </span>
                    </td>
                    <td class="mono"><?= (int)$q['attempts'] ?></td>
                    <td class="mono"><?= h((string)($q['next_retry_at'] ?? '—')) ?></td>
                    <td class="err-cell"><?= h((string)($q['last_error'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>