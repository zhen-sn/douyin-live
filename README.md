# 开播雷达 · 主播直播检测（PHP + SQLite）

监控抖音主播是否开播。开播时在头像位置出现的「直播中」标识会被读取，状态翻转写入 SQLite 并可推送到手机。

## 1. 检测原理

1. 粘贴主播主页链接 `https://www.douyin.com/user/MS4wLjABAAAA...`，程序用 sec_uid 调用
   `https://www.iesdouyin.com/web/api/v2/user/info/?sec_uid=...` 取得昵称、头像与抖音号（`unique_id`）。
2. 再请求直播页 `https://live.douyin.com/{抖音号}`。页面内嵌 `roomStore.roomInfo`：
   - `room.status == 2`：正在直播（等价于头像上的「直播中」角标），同时可取到直播标题、封面、房间号、在线人数。
   - `room.status == 4` 或 `web_stream_url == null`：未开播。
3. 只有状态发生翻转（未开播 → 直播中，或直播中 → 未开播）才写入 `events` 并触发推送。
4. 请求失败、被风控或页面解析异常时只记录 `last_error` 并累加 `fail_count`，**不会**翻转直播状态，避免误报下播。

### 稳定性设计

- **下播去抖**：检测到「未开播」后先挂起 `pending_offline`，连续 N 轮（默认 1，可在设置里改）仍看不到直播才认定下播，网络抖动不会连发「下播」。
- **失败退避**：检测失败时按 `轮询间隔 × 2^(失败次数-1)`（上限 15 分钟）推迟下次检测，恢复成功后清零，避免故障主播拖慢整轮。
- **久未开播降频**：超过「久未开播阈值」（默认 3 天）仍未直播的主播，检测间隔自动放宽到「降频后间隔」（默认 600 秒），开播后立即恢复常规间隔。
- **到期调度**：每个主播带 `next_check_at`，监控每轮只检测到期的主播，整轮耗时更可控。
- **通知失败重试**：推送按渠道入队 `notify_queue`，失败按指数退避自动重试（默认最多 3 次，首次退避 120 秒），在「开播记录」页可手动立即重试或清理已放弃的记录。
- **停摆提示**：监控心跳间隔超过 3 倍轮询间隔时，日志会提示期间可能漏记开播/下播，设置页也会显示最近一次断档时间。
- **改标题推送**：直播中直播标题变化时写入 `title` 事件，可选推送「标题更新」。

解析器会做多轮反转义（页面里的 JSON 存在 1~2 层转义），并校验页面返回的 `web_rid` 与目标抖音号一致，防止拿到别人的直播间。

## 2. 环境要求

- PHP 8.1 及以上（已在 PHP 8.2 / 8.3 验证）
- 必需扩展：`pdo_sqlite`
- 建议扩展：`curl`（缺失时自动回退到流式请求）、`mbstring`、`openssl`（邮件通知走 SSL/STARTTLS 时需要）
- 无需任何框架、无需 Composer

Windows 下若扩展未开启，编辑 `php.ini`：

```ini
extension=pdo_sqlite
extension=curl
extension=mbstring
extension=openssl
```

## 3. 启动

```bash
# 面板
php -S 127.0.0.1:8080 -t .
# 后台轮询（常驻）
php bin/monitor.php
# 只跑一轮（适合计划任务）
php bin/monitor.php --once
```

Windows 可直接双击 `run-web.bat`、`run-monitor.bat`，脚本会自动挑选启用了 `pdo_sqlite` + `curl` 的 PHP。

CLI 参数：`--once` 单轮退出、`--id=3` 只检测某个主播、`--all` 忽略到期时间强制检测全部主播、`--interval=120` 覆盖轮询间隔、`--quiet` 不打印逐个主播的明细。同一时间只允许一个监控进程（`data/monitor.lock`）。

默认按主播的 `next_check_at` 到期调度，进程会睡到最近一个到期的时刻；`--all` 和面板「立即检测全部」会强制检测全部已启用主播。

## 4. 使用

1. 面板「添加监控主播」粘贴主页链接、sec_uid 或抖音号，保存后立刻做一次检测。
2. 卡片显示头像直播角标、直播标题、已直播时长、检测时间与异常提示；看板每 20 秒通过 `index.php?api=status` 同步一次状态，状态变化自动刷新页面。
3. 「开播记录」保存每次开播/下播/改标题事件，并展示通知发送队列（可「立即重试」或清理已放弃的记录）；「设置」里可调轮询间隔、单主播间隔、超时、自定义 Cookie、SSL 校验、下播复核轮数、失败重试次数与退避、久未开播降频，以及 Server酱 / Bark / 自定义 Webhook / 邮件（SMTP）推送渠道，并提供环境自检与测试通知。
4. 后台监控是否在跑，看顶栏「后台监控运行中」——依据 `monitor_heartbeat` 心跳判断。
5. 邮件通知：设置「邮件通知（SMTP）」里填服务器、端口与加密方式（QQ/163 常见为 `smtp.qq.com:465 SSL`）、登录账号与**授权码**（不是登录密码）、收件地址（多个用逗号分隔），保存即启用。发件地址留空时使用登录账号；密码框留空表示保留已保存的授权码，页面上不会回显。

## 5. 目录与数据

```
index.php            Web 入口（增删改查、设置、状态 API）
src/bootstrap.php    常量与自动加载
src/Database.php     SQLite 连接、建表、设置项
src/HttpClient.php   cURL / 流式请求，证书异常自动降级
src/DouyinDetector.php 资料解析 + 直播页解析
src/MonitorService.php 状态机、事件写入、推送调度
src/Notifier.php     Server酱 / Bark / Webhook / 邮件
src/Mailer.php       无依赖 SMTP 客户端（SSL 465 / STARTTLS 587 / 明文 25）
bin/monitor.php      常驻轮询进程
data/monitor.sqlite  数据库（自动创建）
data/cookies/        抖音 ttwid 等 Cookie 缓存
data/logs/           监控与推送日志
```

表结构：`streamers`（主播与最新状态/到期时间）、`events`（开播/下播/改标题记录）、`notify_queue`（各渠道推送队列，失败重试与发送结果）、`settings`（键值配置）。
`web_rid` 与 `sec_uid` 上有部分唯一索引，重复添加会被拒绝。旧库启动时自动补齐新增字段。

## 6. 常见问题

- **提示「抖音返回风控验证页」**：把间隔调到 120 秒以上，或在设置里粘贴浏览器 `douyin.com` 的 Cookie；程序不会因风控误判成下播。
- **只有 sec_uid 拿不到抖音号**：资料接口偶发失败，监控每轮会重试；也可以直接填 `live.douyin.com/` 后面的抖音号。
- **设置页显示证书已降级**：Windows/代理环境的证书链缺少根证书，程序会自动关闭该次校验；可在设置里手动开关，建议优先修复 `curl.cainfo`。
- **邮件收不到**：先看 `data/logs/notify.log` 的具体 SMTP 报错。`535` 一般是用了登录密码而非授权码；465 端口要选 SSL、587 选 STARTTLS；部分服务商用 25 端口需选「不加密」并要求本机出网；提示缺少 openssl 扩展时请启用 `extension=openssl` 或改用明文端口。
- **推送提示失败但状态正常**：通知会留在「开播记录」的发送队列里按退避自动重试，恢复网络后点「立即重试」即可补发；顶栏出现计数徽标说明还有待发/失败记录。
- **想减少请求**：监控默认按到期时间调度，并会在久未开播时自动降频；监控间隔建议 60~300 秒。
- **计划任务方式**：`php bin/monitor.php --once --quiet`，配合 Windows 计划任务或 crontab 每 1~5 分钟执行一次即可。

请仅用于个人关注与合规用途，控制请求频率，遵守平台规则。
