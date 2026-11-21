<?php
declare(strict_types=1);
/** @var array $streamers */
/** @var array $events */
/** @var array $stats */
/** @var array $settings */

$hf = static function (string $action, int $id = 0, string $back = 'index.php'): string {
    $fields = '<input type="hidden" name="_token" value="' . h(csrfToken()) . '">'
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="back" value="' . h($back) . '">';
    if ($id > 0) {
        $fields .= '<input type="hidden" name="id" value="' . $id . '">';
    }
    return $fields;
};
?>
<section class="hero">
    <div class="hero-copy">
        <h1>主播开播检测</h1>
        <p>粘贴抖音主页链接，系统读取直播页头像位置的「直播中」标识，开播与下播时写入记录并推送提醒。</p>
        <div class="hero-actions">
            <form method="post" action="index.php" data-once>
                <?= $hf('check_all') ?>
                <button class="btn primary" type="submit">立即检测全部</button>
            </form>
            <a class="btn ghost" href="index.php?page=settings">配置通知渠道</a>
        </div>
    </div>
    <div class="stat-grid">
        <div class="stat">
            <b><?= (int)$stats['total'] ?></b>
            <span>监控主播</span>
        </div>
        <div class="stat stat-live" data-stat-live>
            <b data-stat-live-count><?= (int)$stats['live'] ?></b>
            <span>正在直播</span>
        </div>
        <div class="stat">
            <b><?= (int)$stats['today_events'] ?></b>
            <span>今日开播次数</span>
        </div>
        <div class="stat">
            <b><?= (int)$settings['check_interval'] ?>s</b>
            <span>轮询间隔</span>
        </div>
    </div>
</section>

<section class="panel add-panel">
    <h2>添加监控主播</h2>
    <form class="add-form" method="post" action="index.php" data-once>
        <?= $hf('add') ?>
        <label class="field field-grow">
            <span>抖音主页链接 / sec_uid / 抖音号</span>
            <input name="target" required autocomplete="off"
                   placeholder="https://www.douyin.com/user/MS4wLjABAAAA...">
        </label>
        <label class="field">
            <span>备注名（选填）</span>
            <input name="name" autocomplete="off" placeholder="留空自动使用昵称">
        </label>
        <button class="btn primary" type="submit">添加并检测</button>
    </form>
    <p class="hint">只有 sec_uid 时会自动取抖音号；若资料接口被风控，可直接填写 <code>live.douyin.com/后面的抖音号</code>。</p>
</section>

<div class="section-head">
    <h2>监控列表</h2>
    <span class="hint">看板每 20 秒同步一次状态</span>
</div>

<?php if ($streamers === []): ?>
    <div class="empty">还没有监控的主播，先在上方添加一条。</div>
<?php else: ?>
    <div class="grid" data-monitor-grid>
        <?php foreach ($streamers as $s):
            $live = (int)$s['is_live'] === 1;
            $enabled = (int)$s['enabled'] === 1;
            $liveUrl = $s['web_rid']
                ? 'https://live.douyin.com/' . rawurlencode((string)$s['web_rid'])
                : (string)$s['url'];
            ?>
            <article class="card <?= $live ? 'card-live' : '' ?><?= $enabled ? '' : ' card-paused' ?>"
                     data-streamer-id="<?= (int)$s['id'] ?>" data-live="<?= $live ? 1 : 0 ?>">
                <div class="card-main">
                    <div class="avatar <?= $live ? 'is-live' : '' ?>">
                        <span class="avatar-initial"><?= h(initial((string)$s['name'])) ?></span>
                        <?php if (!empty($s['avatar'])): ?>
                            <img src="<?= h($s['avatar']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
                        <?php endif; ?>
                        <?php if ($live): ?><span class="live-tag">直播中</span><?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="card-title">
                            <h3><a href="index.php?page=streamer&amp;id=<?= (int)$s['id'] ?>"><?= h($s['name']) ?></a></h3>
                            <span class="rid">
                                <?= $s['web_rid'] ? '抖音号 ' . h($s['web_rid']) : '抖音号待解析' ?>
                            </span>
                        </div>
                        <div class="card-status">
                            <span class="status <?= $live ? 'is-live' : ($enabled ? 'is-idle' : 'is-paused') ?>" data-status-pill>
                                <i></i><?= $live ? '直播中' : ($enabled ? '未开播' : '已暂停') ?>
                            </span>
                            <span class="muted">检测于 <b data-last-check><?= h(timeAgo($s['last_check_at'])) ?></b></span>
                        </div>
                        <p class="live-title" data-live-title <?= $live && !empty($s['live_title']) ? '' : 'hidden' ?>>
                            <?= h((string)$s['live_title']) ?>
                        </p>
                        <p class="live-duration" <?= $live ? '' : 'hidden' ?>>
                            已直播 <b data-duration data-since="<?= h((string)$s['live_since']) ?>"><?= h(formatDuration($s['live_since'])) ?></b>
                        </p>
                        <?php if (!empty($s['last_error'])): ?>
                            <p class="card-error">
                                检测异常：<?= h((string)$s['last_error']) ?>（连续 <?= (int)$s['fail_count'] ?> 次，状态暂不翻转）
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-actions">
                    <a class="btn sm <?= $live ? 'primary' : 'ghost' ?>" href="<?= h($liveUrl) ?>"
                       target="_blank" rel="noopener noreferrer"><?= $live ? '进入直播间' : '打开直播页' ?></a>
                    <form method="post" action="index.php" data-once>
                        <?= $hf('check', (int)$s['id']) ?>
                        <button class="btn sm ghost" type="submit">立即检测</button>
                    </form>
                    <form method="post" action="index.php">
                        <?= $hf('toggle', (int)$s['id']) ?>
                        <button class="btn sm ghost" type="submit"><?= $enabled ? '暂停监控' : '恢复监控' ?></button>
                    </form>
                    <form method="post" action="index.php"
                          onsubmit="return confirm('确定删除该主播及其历史记录？');">
                        <?= $hf('delete', (int)$s['id']) ?>
                        <button class="btn sm danger" type="submit">删除</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($events)): ?>
    <section class="panel">
        <div class="panel-head">
            <h2>最近动态</h2>
            <a class="link" href="index.php?page=history">查看全部</a>
        </div>
        <ul class="timeline">
            <?php foreach ($events as $e): ?>
                <li class="event event-<?= h($e['type']) ?>">
                    <i></i>
                    <div class="event-body">
                        <strong><?= h($e['name']) ?></strong>
                        <span><?= $e['type'] === 'online' ? '开播' : '下播' ?><?= !empty($e['title']) ? ' · ' . h($e['title']) : '' ?></span>
                        <time><?= h($e['created_at']) ?></time>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>