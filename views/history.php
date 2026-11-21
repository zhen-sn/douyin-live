<?php
declare(strict_types=1);
/** @var array $events */
/** @var array $stats */
?>
<div class="section-head">
    <div>
        <h1 class="page-title">开播记录</h1>
        <p class="hint">展示最近 300 条开播与下播事件（共今日 <?= (int)$stats['today_events'] ?> 条）。</p>
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
                        <span class="status <?= $e['type'] === 'online' ? 'is-live' : 'is-idle' ?>">
                            <i></i><?= $e['type'] === 'online' ? '开播' : '下播' ?>
                        </span>
                    </td>
                    <td><?= h((string)$e['title']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>