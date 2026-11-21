(() => {
    'use strict';

    const flashClose = document.querySelector('.flash button');
    if (flashClose) {
        flashClose.addEventListener('click', () => flashClose.closest('.flash').remove());
    }

    document.querySelectorAll('form[data-once]').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('button').forEach((btn) => {
                btn.disabled = true;
                btn.dataset.label = btn.textContent;
                btn.textContent = '处理中…';
            });
        });
    });

    const parseTime = (value) => {
        if (!value) return null;
        const date = new Date(String(value).replace(/-/g, '/'));
        return Number.isNaN(date.getTime()) ? null : date;
    };

    const formatDuration = (seconds) => {
        if (seconds < 60) return seconds + ' 秒';
        if (seconds < 3600) return Math.floor(seconds / 60) + ' 分钟';
        return Math.floor(seconds / 3600) + ' 小时 ' + Math.floor((seconds % 3600) / 60) + ' 分';
    };

    const tickDurations = () => {
        document.querySelectorAll('[data-duration][data-since]').forEach((el) => {
            const since = parseTime(el.dataset.since);
            if (since) {
                el.textContent = formatDuration(Math.max(0, Math.floor((Date.now() - since.getTime()) / 1000)));
            }
        });
    };
    tickDurations();
    setInterval(tickDurations, 30000);

    const cards = Array.from(document.querySelectorAll('[data-streamer-id]'));
    if (cards.length === 0) return;

    let reloading = false;
    const poll = async () => {
        if (reloading || document.hidden) return;
        try {
            const response = await fetch('index.php?api=status&_=' + Date.now());
            if (!response.ok) return;
            const data = await response.json();
            const byId = new Map(data.streamers.map((item) => [item.id, item]));
            let changed = false;

            cards.forEach((card) => {
                const item = byId.get(Number(card.dataset.streamerId));
                if (!item) return;
                if (String(card.dataset.live) !== String(item.is_live)) changed = true;
                const pill = card.querySelector('[data-status-pill]');
                if (pill) {
                    pill.className = 'status ' + (item.is_live ? 'is-live' : 'is-idle');
                    pill.innerHTML = '<i></i>' + (item.is_live ? '直播中' : '未开播');
                }
                const lastCheck = card.querySelector('[data-last-check]');
                if (lastCheck) lastCheck.textContent = item.last_check_ago || '从未';
                const duration = card.querySelector('[data-duration]');
                if (duration && item.is_live) duration.textContent = item.live_duration || '';
            });

            const topLive = document.querySelector('[data-top-live] b');
            if (topLive) topLive.textContent = data.live_count;

            if (changed) {
                reloading = true;
                setTimeout(() => window.location.reload(), 800);
            }
        } catch (error) {
            return;
        }
    };

    setInterval(poll, 20000);
})();