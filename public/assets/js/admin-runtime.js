/** 运行页面只读取任务事实；诊断按需触发，客户端心跳不代表支付平台在线，也不提供进程控制入口。 */
MpayAdmin.ready(function () {
    'use strict';
    const UI = MpayAdmin,
        E = UI.escape,
        form = document.getElementById('runtime-filters');
    if (!form) return;
    const feedback = document.getElementById('admin-feedback'),
        query = new URLSearchParams(location.search);
    let page = 1,
        overviewSequence = 0,
        collectionTable = null;
    const collectionTones = {
        0: 'blue',
        1: 'green',
        11: 'green',
        12: 'gray',
        13: 'orange',
        3: 'orange',
        4: 'orange',
        5: 'orange',
        6: 'red',
        7: 'red',
        9: 'blue',
        10: 'gray',
    };
    const when = (value) =>
        value == null
            ? '暂无记录'
            : new Date(value * 1000).toLocaleString('zh-CN', {
                  timeZone: 'Asia/Shanghai',
                  hour12: false,
              });
    async function overview() {
        const sequence = ++overviewSequence;
        try {
            const data = (await MpayHttp.request('/adminapi/maintenance')).data;
            if (sequence !== overviewSequence) return;
            const workers = document.getElementById('runtime-workers');
            workers.replaceChildren();
            data.workers.workers.forEach((worker) => {
                const card = document.createElement('section');
                card.className = 'admin-worker-card';
                card.innerHTML =
                    '<h2>' +
                    E(worker.label) +
                    UI.badge(
                        worker.status_label,
                        worker.recent ? 'green' : data.workers.scan_complete ? 'gray' : 'orange',
                    ) +
                    '</h2><p>最近活动：' +
                    E(when(worker.observed_at)) +
                    '</p>';
                workers.append(card);
            });
            if (!data.workers.scan_complete)
                UI.feedback(feedback, '任务活动记录读取不完整，请核对运行目录权限。');
            document.getElementById('runtime-overview').innerHTML = Object.entries({
                '观测时间': data.observed_at,
                'PHP': data.php + ' / ' + data.sapi,
                '待发送通知': data.notifications.pending,
                '发送中通知': data.notifications.sending,
                '停止自动重试': data.notifications.exhausted,
                '未知运行状态': data.unknown_states,
                ...data.packages,
            })
                .map(([name, value]) => '<dt>' + E(name) + '</dt><dd>' + E(value) + '</dd>')
                .join('');
            const selected = form.elements.status.value;
            form.elements.status.replaceChildren(new Option('全部状态', ''));
            Object.entries(data.status_options).forEach(([value, label]) =>
                form.elements.status.add(new Option(label, value)),
            );
            form.elements.status.value = selected;
            UI.render(form);
        } catch (exception) {
            if (sequence === overviewSequence) UI.error(feedback, exception);
        }
    }
    function collections(current = 1) {
        const where = {};
        new FormData(form).forEach((value, key) => {
            if (value !== '') where[key] = value;
        });
        if (collectionTable) {
            layui.table.reloadData('runtime-collections', {
                where,
                page: { curr: current },
                scrollPos: 'fixed',
            });
            return;
        }
        collectionTable = UI.table({
            elem: '#runtime-collections',
            id: 'runtime-collections',
            url: '/adminapi/maintenance/collections',
            where,
            page: { curr: current },
            feedback,
            done(result, curr) {
                page = curr;
            },
            emptyText: '暂无运行记录',
            cols: [
                [
                    {
                        field: 'name',
                        title: '收款通道',
                        minWidth: 180,
                        templet: (row) => E(row.name),
                    },
                    {
                        title: '状态',
                        minWidth: 200,
                        align: 'center',
                        templet: (row) =>
                            UI.badge(row.status_label, collectionTones[row.status] || 'gray'),
                    },
                    {
                        title: '最近异常／说明',
                        minWidth: 280,
                        templet: (row) => {
                            const error = row.last_error
                                ? '<div class="admin-runtime-error"><span class="admin-runtime-error-message" title="' +
                                  E(row.last_error.message) + '">' + E(row.last_error.message) + '</span>' +
                                  (row.recovered_at ? UI.badge('已恢复', 'green') : '') + '</div>'
                                : '';
                            const heartbeat = row.heartbeat_note ? '<div>' + E(row.heartbeat_note) + '</div>' : '';
                            return error + heartbeat || '—';
                        },
                    },
                    {
                        field: 'last_activity_at',
                        title: '最近活动',
                        width: 200,
                        templet: (row) => E(row.last_activity_at || '暂无记录'),
                    },
                    {
                        field: 'pending_count',
                        title: '待核对订单',
                        width: 120,
                        align: 'center',
                        templet: (row) => E(row.pending_count ?? 0),
                    },
                    {
                        field: 'consecutive_failures',
                        title: '连续失败',
                        width: 140,
                        align: 'center',
                        templet: (row) => E(row.consecutive_failures ?? '—'),
                    },
                    {
                        title: '操作',
                        width: 100,
                        align: 'center',
                        fixed: 'right',
                        templet: () =>
                            '<button type="button" class="admin-link" lay-event="detail">详情</button>',
                    },
                ],
            ],
        });
    }
    layui.table.on('tool(runtime-collections)', (event) => {
        if (event.event !== 'detail') return;
        const item = event.data;
        const error = item.last_error;
        const diagnosis = error
            ? '<div class="admin-section-title">最近一次错误 ' +
              (item.recovered_at ? UI.badge('已恢复', 'green') : '') +
              '</div>' +
              UI.facts({
                  '来源': error.source_label,
                  '失败阶段': error.stage,
                  '平台错误码': error.platform_code,
                  '错误说明': error.message,
                  'HTTP 状态': error.http_status,
                  '发生时间': error.occurred_at,
                  '恢复时间': item.recovered_at,
              }) +
              (error.message_truncated
                  ? UI.notice('错误说明超出长度上限，仅保留前 2048 字节。')
                  : '')
            : '';
        UI.open({
            title: '运行详情 · ' + item.name,
            drawer: true,
            content:
                UI.badge(item.status_label, collectionTones[item.status] || 'gray') +
                diagnosis +
                (item.heartbeat_note ? UI.notice(item.heartbeat_note) : '') +
                UI.facts({
                    '收款通道': item.name,
                    '通道启用': item.enabled,
                    '待核对订单': item.pending_count,
                    '最近活动': item.last_activity_at,
                    '活动类型': item.collector ? '最近查单成功' : '最近有效客户端心跳',
                    '最近登录验证': item.last_verified_at,
                    '关联批次确认笔数': item.matched_count,
                    '连续失败批次': item.consecutive_failures,
                    '最近耗时（毫秒）': item.last_duration_ms,
                    '处理说明': item.reason_label,
                }) +
                (item.listener ? UI.facts({
                    '监听应用': item.listener.package_name,
                    '最近上报设备': item.listener.device_name,
                    '客户端': item.listener.client,
                    '上报监听状态': item.listener.listening ? '正在监听' : '未在监听',
                    '最近心跳': when(item.listener.received_at),
                }) + UI.notice('心跳仅表示客户端上报情况，不代表支付应用登录或收款能力。') : '') +
                '<details class="admin-details"><summary>技术信息</summary>' +
                UI.facts({
                    '插件': item.plugin_code,
                    '配置版本': item.configuration_version,
                    '最近更新': item.updated_at,
                }) +
                '</details><a class="layui-btn layui-btn-primary" href="/admin/configurations?configuration_id=' +
                encodeURIComponent(item.configuration_id) +
                '">查看收款通道</a>',
        });
    });
    document.getElementById('runtime-doctor').addEventListener('click', () => {
        const handle = UI.open({
            title: '只读环境诊断',
            width: 850,
            content: '<table class="runtime-diagnosis"></table>',
        });
        handle.run(async () => {
            handle.feedback('正在检查…', 'loading');
            const data = (
                await MpayHttp.request('/adminapi/maintenance/doctor', { timeout: 30000 })
            ).data;
            const names = {
                php: 'PHP 与扩展',
                site: '站点配置',
                runtime: '运行目录',
                database: '数据库',
                migrations: '安装进度',
                notification: '通知任务',
                collection: '通道运行状态',
                workers: '任务活动',
            };
            const states = {
                ok: ['通过', 'green'],
                warning: ['需关注', 'orange'],
                error: ['未通过', 'red'],
            };
            UI.table({
                elem: handle.node.querySelector('.runtime-diagnosis'),
                data: data.checks,
                page: false,
                cellExpandedMode: 'tips',
                cols: [
                    [
                        { title: '项目', width: 160, templet: (row) => E(names[row.id] || row.id) },
                        {
                            title: '结果',
                            width: 100,
                            align: 'center',
                            templet: (row) => UI.badge(...(states[row.status] || ['未知', 'gray'])),
                        },
                        {
                            field: 'message',
                            title: '说明',
                            minWidth: 300,
                            templet: (row) => E(row.message),
                        },
                    ],
                ],
            });
            handle.feedback('');
        });
    });
    // 通道列表提供当前专属配置 ID；筛选运行摘要时不能把通道 ID 当成配置 ID。
    UI.all('/adminapi/configuration/channels')
        .then((items) => {
            items.forEach((item) =>
                form.elements.configuration_id.add(new Option(item.name, item.configuration_id)),
            );
            if (query.has('configuration_id'))
                form.elements.configuration_id.value = query.get('configuration_id');
            UI.render(form);
            collections();
        })
        .catch((exception) => UI.error(feedback, exception));
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        UI.feedback(feedback, '');
        collections(1);
    });
    UI.onReset(form, () => {
        UI.render(form);
        collections(1);
    });
    document.getElementById('runtime-refresh').addEventListener('click', () => {
        UI.feedback(feedback, '');
        overview();
        collections(page);
    });
    overview();
});
