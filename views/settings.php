<?php
declare(strict_types=1);
/** @var array $settings */
/** @var array $checks */
/** @var array $stats */
?>
<div class="section-head">
    <div>
        <h1 class="page-title">设置</h1>
        <p class="hint">修改后立即生效，后台监控进程会在下一个轮询周期读取新间隔。</p>
    </div>
    <span class="monitor-state <?= $stats['monitor_alive'] ? 'is-on' : '' ?>">
        <i></i>后台心跳：<?= $stats['heartbeat'] !== '' ? h(timeAgo($stats['heartbeat'])) : '未运行' ?>
    </span>
</div>

<div class="settings-grid">
    <form class="panel" method="post" action="index.php" data-once>
        <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="settings">

        <h2>检测频率</h2>
        <div class="field-row">
            <label class="field">
                <span>轮询间隔（秒）</span>
                <input type="number" name="check_interval" min="20" max="3600" step="10"
                       value="<?= h((string)$settings['check_interval']) ?>">
            </label>
            <label class="field">
                <span>单主播间隔（秒）</span>
                <input type="number" name="request_delay" min="0" max="60" step="1"
                       value="<?= h((string)$settings['request_delay']) ?>">
            </label>
            <label class="field">
                <span>请求超时（秒）</span>
                <input type="number" name="request_timeout" min="5" max="60" step="1"
                       value="<?= h((string)$settings['request_timeout']) ?>">
            </label>
        </div>
        <div class="field-row">
            <label class="field">
                <span>下播复核轮数（0=立刻判定）</span>
                <input type="number" name="offline_confirm_rounds" min="0" max="5" step="1"
                       value="<?= h((string)$settings['offline_confirm_rounds']) ?>">
            </label>
            <label class="field">
                <span>久未开播阈值（天）</span>
                <input type="number" name="idle_after_days" min="0" max="365" step="1"
                       value="<?= h((string)$settings['idle_after_days']) ?>">
            </label>
            <label class="field">
                <span>降频后间隔（秒）</span>
                <input type="number" name="idle_interval" min="60" max="86400" step="60"
                       value="<?= h((string)$settings['idle_interval']) ?>">
            </label>
        </div>
        <p class="hint">
            复核轮数用于抗抖动：连续 N 轮看不到直播才判定下播，下播提醒会相应晚 N−1 个周期。
            连续 <?= (int)$settings['idle_after_days'] ?> 天没开播的主播自动降到
            <?= (int)$settings['idle_interval'] ?> 秒一次，开播后立刻恢复常规间隔；填 0 关闭降频。
        </p>

        <h2>抖音访问</h2>
        <label class="field">
            <span>自定义 Cookie（选填，触发风控时粘贴浏览器 douyin.com 的 Cookie）</span>
            <textarea name="douyin_cookie" rows="3" spellcheck="false"
                      placeholder="ttwid=...; 其他=..."><?= h((string)$settings['douyin_cookie']) ?></textarea>
        </label>
        <label class="checkbox">
            <input type="checkbox" name="verify_ssl" value="1" <?= $settings['verify_ssl'] === '1' ? 'checked' : '' ?>>
            <span>校验 HTTPS 证书（Windows 报证书链错误时可取消）</span>
        </label>

        <h2>开播通知</h2>
        <label class="checkbox">
            <input type="checkbox" name="notify_on_online" value="1" <?= $settings['notify_on_online'] === '1' ? 'checked' : '' ?>>
            <span>开播时推送</span>
        </label>
        <label class="checkbox">
            <input type="checkbox" name="notify_on_offline" value="1" <?= $settings['notify_on_offline'] === '1' ? 'checked' : '' ?>>
            <span>下播时推送</span>
        </label>
        <label class="checkbox">
            <input type="checkbox" name="notify_on_title" value="1" <?= $settings['notify_on_title'] === '1' ? 'checked' : '' ?>>
            <span>直播中改标题时推送</span>
        </label>
        <div class="field-row">
            <label class="field">
                <span>推送失败最大重试次数</span>
                <input type="number" name="notify_retry_max" min="1" max="10" step="1"
                       value="<?= h((string)$settings['notify_retry_max']) ?>">
            </label>
            <label class="field">
                <span>重试首次退避（秒）</span>
                <input type="number" name="notify_retry_backoff" min="15" max="3600" step="15"
                       value="<?= h((string)$settings['notify_retry_backoff']) ?>">
            </label>
        </div>
        <p class="hint">
            每次开播/下播都会按渠道写入发送队列并立即尝试；失败的行按 <?= (int)$settings['notify_retry_backoff'] ?> 秒起指数退避自动重试，
            超过 <?= (int)$settings['notify_retry_max'] ?> 次标记为放弃，可在「开播记录」页查看与手动补发。
        </p>
        <div class="field-row">
            <label class="field field-grow">
                <span>Server酱 SendKey</span>
                <input name="notify_serverchan_key" autocomplete="off" placeholder="SCT..."
                       value="<?= h((string)$settings['notify_serverchan_key']) ?>">
            </label>
        </div>
        <label class="field">
            <span>Bark 地址</span>
            <input name="notify_bark_url" autocomplete="off" placeholder="https://api.day.app/yourkey"
                   value="<?= h((string)$settings['notify_bark_url']) ?>">
        </label>
        <label class="field">
            <span>自定义 Webhook（POST JSON）</span>
            <input name="notify_webhook" autocomplete="off" placeholder="https://example.com/hook"
                   value="<?= h((string)$settings['notify_webhook']) ?>">
        </label>

        <h2>邮件通知（SMTP）</h2>
        <div class="field-row">
            <label class="field">
                <span>SMTP 服务器</span>
                <input name="smtp_host" autocomplete="off" placeholder="smtp.qq.com"
                       value="<?= h((string)$settings['smtp_host']) ?>">
            </label>
            <label class="field">
                <span>端口</span>
                <input type="number" name="smtp_port" min="1" max="65535" step="1"
                       value="<?= h((string)$settings['smtp_port']) ?>">
            </label>
            <label class="field">
                <span>加密方式</span>
                <select name="smtp_secure">
                    <?php foreach (['ssl' => 'SSL（465）', 'tls' => 'STARTTLS（587）', 'none' => '不加密（25）'] as $k => $label): ?>
                        <option value="<?= h($k) ?>" <?= ($settings['smtp_secure'] ?? 'ssl') === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="field-row">
            <label class="field">
                <span>登录账号</span>
                <input name="smtp_user" autocomplete="off" placeholder="you@qq.com"
                       value="<?= h((string)$settings['smtp_user']) ?>">
            </label>
            <label class="field">
                <span>授权码 / 密码</span>
                <input type="password" name="smtp_pass" autocomplete="new-password"
                       value="" placeholder="<?= ($settings['smtp_pass'] ?? '') !== '' ? '已保存，留空表示不修改' : 'QQ/163 请用授权码' ?>">
            </label>
            <label class="field">
                <span>发件人名称</span>
                <input name="smtp_from_name" autocomplete="off" placeholder="开播雷达"
                       value="<?= h((string)$settings['smtp_from_name']) ?>">
            </label>
        </div>
        <div class="field-row">
            <label class="field">
                <span>发件地址（留空用登录账号）</span>
                <input name="smtp_from" autocomplete="off" placeholder="you@qq.com"
                       value="<?= h((string)$settings['smtp_from']) ?>">
            </label>
            <label class="field" style="grid-column: span 2;">
                <span>收件地址（多个用逗号分隔）</span>
                <input name="smtp_to" autocomplete="off" placeholder="a@qq.com, b@163.com"
                       value="<?= h((string)$settings['smtp_to']) ?>">
            </label>
        </div>
        <p class="hint">填好服务器与收件地址并保存后即启用；发送失败会记录在 <code>data/logs/notify.log</code>。邮箱请用服务商授权码，不是登录密码。</p>

        <div class="form-actions">
            <button class="btn primary" type="submit">保存设置</button>
        </div>
    </form>

    <div class="settings-side">
        <section class="panel">
            <h2>环境自检</h2>
            <ul class="check-list">
                <?php foreach ($checks as [$label, $value, $good]): ?>
                    <li class="<?= $good ? 'ok' : 'warn' ?>">
                        <span><?= h($label) ?></span>
                        <b><?= h($value) ?></b>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form class="inline-form" method="post" action="index.php" data-once>
                <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="test_notify">
                <button class="btn ghost" type="submit">发送测试通知</button>
            </form>
        </section>

        <section class="panel">
            <h2>后台监控</h2>
            <p class="hint">看板不会自己轮询，需要常驻进程或计划任务：</p>
            <pre class="code">php bin/monitor.php
php bin/monitor.php --once
php -S 127.0.0.1:8080 index.php</pre>
            <p class="hint">Windows 可直接双击 run-web.bat 与 run-monitor.bat。</p>
        </section>
    </div>
</div>