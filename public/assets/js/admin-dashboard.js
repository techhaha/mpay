/** 工作台只展示服务器统计；通知和到账次数不能在浏览器相加为收入。 */
MpayAdmin.ready(function () {
    'use strict';
    const UI = MpayAdmin,
        form = document.getElementById('dashboard-filter');
    if (!form) return;
    const feedback = document.getElementById('admin-feedback');
    let sequence = 0;
    Promise.all([
        MpayHttp.request('/adminapi/applications'),
        UI.all('/adminapi/configuration/channels'),
    ])
        .then(([result, channels]) => {
            result.data.items.forEach((app) =>
                form.elements.app_id.add(new Option(app.name + ' / ' + app.pid, app.id)),
            );
            const hasChannel = channels.some((channel) => channel.enabled);
            const hasApp = result.data.items.some(
                (app) => app.enabled && app.allowed_hosts.length > 0,
            );
            const setup = document.getElementById('dashboard-setup');
            setup.hidden = hasChannel && hasApp;
            document.getElementById('setup-channel').innerHTML =
                '<div class="admin-section-title">1. 添加收款通道 ' +
                UI.badge(hasChannel ? '已启用通道' : '待配置', hasChannel ? 'green' : 'orange') +
                '</div><a class="layui-btn" href="/admin/configurations?action=create">新增通道</a>';
            document.getElementById('setup-application').innerHTML =
                '<div class="admin-section-title">2. 接入业务网站 ' +
                UI.badge(hasApp ? '已配置域名' : '待接入', hasApp ? 'green' : 'orange') +
                '</div><a class="layui-btn layui-btn-primary" href="/admin/applications?setup=1">查看接入信息</a>';
            UI.render(form);
        })
        .catch((exception) => UI.error(feedback, exception));
    async function load() {
        const request = ++sequence;
        UI.feedback(feedback, '正在读取统计…', 'loading');
        try {
            const data = (
                await MpayHttp.request(
                    '/adminapi/dashboard?' + new URLSearchParams(new FormData(form)),
                )
            ).data;
            if (request !== sequence) return;
            document
                .querySelectorAll('[data-metric]')
                .forEach((node) => (node.textContent = data[node.dataset.metric] ?? '—'));
            const queue = document.getElementById('dashboard-queues');
            queue.replaceChildren();
            const appQuery = form.elements.app_id.value
                    ? '&app_id=' + encodeURIComponent(form.elements.app_id.value)
                    : '',
                appScope = appQuery ? '当前应用' : '全部应用';
            const rows = [
                [
                    '待发送商户通知',
                    data.notification_counts[0],
                    appScope,
                    '/admin/notifications?status=0&all_dates=1' + appQuery,
                ],
                [
                    '停止自动重试',
                    data.notification_counts[3],
                    appScope,
                    '/admin/notifications?status=3&all_dates=1' + appQuery,
                ],
            ];
            rows.forEach(([label, count, scope, path]) => {
                const row = document.createElement('a');
                row.className = 'admin-task-row admin-queue-link';
                row.href = path;
                row.innerHTML =
                    '<div><h3>' +
                    UI.escape(label) +
                    '</h3><small>' +
                    scope +
                    '</small></div><span class="admin-task-number"><span class="admin-count-badge' +
                    (count > 0 ? ' attention' : '') +
                    '">' +
                    UI.escape(count ?? '—') +
                    '</span>' +
                    UI.icon('right') +
                    '</span>';
                queue.append(row);
            });
            const workers = document.getElementById('dashboard-workers');
            workers.replaceChildren();
            data.runtime.workers.forEach((worker) => {
                const row = document.createElement('div');
                row.className = 'admin-task-row';
                const time =
                    worker.observed_at == null
                        ? '尚无活动记录'
                        : new Date(worker.observed_at * 1000).toLocaleString('zh-CN', {
                              timeZone: 'Asia/Shanghai',
                              hour12: false,
                          });
                row.innerHTML =
                    '<div><h3>' +
                    UI.escape(worker.label) +
                    '</h3><small>' +
                    UI.escape(time) +
                    '</small></div>' +
                    UI.badge(
                        worker.status_label,
                        worker.recent ? 'green' : data.runtime.scan_complete ? 'gray' : 'orange',
                    );
                workers.append(row);
            });
            document.getElementById('dashboard-other').textContent =
                '已确认收款订单：' + (data.confirmed_orders ?? '—');
            document.getElementById('dashboard-observed').textContent =
                '读取时间：' + data.observed_at + ' · 上海时区';
            UI.feedback(
                feedback,
                data.unknown_state_records > 0 ? '存在未知状态记录，请核对数据。' : '',
            );
        } catch (exception) {
            if (request === sequence)
                UI.feedback(
                    feedback,
                    (exception.message || '统计读取失败') + '；已显示数字为上次读取结果。',
                );
        }
    }
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (UI.valid(form)) load();
    });
    document.getElementById('dashboard-refresh').addEventListener('click', load);
    load();
});
