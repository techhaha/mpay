/** 四类记录共享只读列表与详情抽屉；跳转使用服务器已经保存的关联号，不按金额或时间猜关联。 */
MpayAdmin.ready(function () {
    'use strict';
    const UI = MpayAdmin,
        E = UI.escape,
        form = document.getElementById('records-filter');
    if (!form) return;
    const definitions = {
        receipts: {
            url: '/adminapi/receipts/list',
            detail: '/adminapi/receipts/detail',
            history: '/adminapi/receipts/history',
            key: 'id',
            title: '到账详情',
            date: '按系统接收日期筛选，来源付款时间在列表及详情中单独展示。',
            main: ['plugin_code'],
            advanced: { id: '流水 ID', scope_key: '收款范围', pay_no: '关联支付单号' },
            columns: {
                id: '流水编号',
                amount: '实际金额（元）',
                plugin_code: '收款来源',
                biz_no: '关联订单',
                paid_at: '来源付款时间',
                reason_label: '核对说明',
            },
        },
        notifications: {
            url: '/adminapi/notifications/list',
            detail: '/adminapi/notifications/detail',
            history: '/adminapi/notifications/attempts',
            key: 'notify_no',
            title: '商户通知详情',
            date: '按原通知任务创建日期筛选。发送次数不是支付次数。',
            states: {
                0: '待发送 / 重试',
                1: '发送中',
                2: '最近通知成功',
                3: '已停止自动重试',
                4: '暂不通知',
                acknowledged: '曾确认成功',
            },
            main: ['status', 'biz_no'],
            advanced: { notify_no: '通知号', pay_no: '支付单号' },
            columns: {
                notify_no: '通知号',
                biz_no: '关联订单',
                status_label: '当前安排',
                first_ack_at: '首次商户确认',
                retry_count: '已发送次数',
                target_host: '通知目标',
                next_retry_at: '下次发送',
            },
        },
        ingress: {
            url: '/adminapi/receipts/ingress',
            key: 'id',
            title: '通道回调详情',
            date: '按外部通知的接收日期筛选。接收成功不代表订单支付成功。',
            main: ['channel_id', 'plugin_code'],
            advanced: { result_code: '结果代码', request_id: '请求编号' },
            columns: {
                created_at: '接收时间',
                channel_id: '收款通道',
                result_code: '处理结果',
                flow_id: '关联流水',
            },
        },
        audit: {
            url: '/adminapi/audit',
            key: 'id',
            title: '操作日志详情',
            date: '按管理员操作发生日期筛选。',
            main: ['action', 'result_code'],
            advanced: { admin_id: '管理员 ID', request_id: '请求编号' },
            columns: {
                created_at: '操作时间',
                admin_user_id: '管理员',
                action: '操作',
                result_code: '结果',
                target_type: '对象类型',
            },
        },
    };
    const kind = form.dataset.kind,
        config = definitions[kind];
    if (!config) return;
    const feedback = document.getElementById('admin-feedback'),
        locationValues = new URLSearchParams(location.search);
    const actions = {
        'admin.login': '登录后台',
        'admin.logout': '退出所有登录',
        'admin.password': '修改密码',
        'system.initialize': '初始化系统',
        'channel.save': '保存收款通道',
        'channel.enabled': '通道启停',
        'channel.delete': '删除通道',
        'configuration.create': '新增插件配置',
        'configuration.save': '修改插件配置',
        'configuration.enabled': '插件配置启停',
        'application.create': '新增应用',
        'application.save': '修改应用',
        'application.key_reveal': '查看 KEY',
        'application.key_rotate': '重置 KEY',
        'application.bind': '配置应用路由',
        'poll_group.create': '新增轮询组',
        'poll_group.save': '修改轮询组',
        'system.configuration': '修改系统设置',
        'file.upload': '上传图片',
    };
    const results = {
        accepted: '成功',
        created: '已创建',
        updated: '已更新',
        unchanged: '未改变',
        enabled: '已启用',
        disabled: '已停用',
        deleted: '已删除',
        viewed: '已查看',
        computed: '已计算',
        rejected: '未通过',
        failed: '失败',
        invalid_credentials: '认证未通过',
    };
    const targets = {
        payment_channel: '收款通道',
        payment_plugin_conf: '插件配置',
        payment_app: '接入应用',
        payment_poll_group: '轮询组',
        system_config: '系统设置',
        admin_user: '管理员',
        biz_order: '订单',
        file_asset: '图片',
    };
    let page = 1,
        currentDialog = null,
        plugins = [],
        channels = [],
        recordsTable = null;
    const notificationTones = { 0: 'orange', 1: 'blue', 2: 'green', 3: 'orange', 4: 'gray' };
    const resultTones = {
        accepted: 'green',
        created: 'green',
        updated: 'green',
        enabled: 'green',
        disabled: 'gray',
        rejected: 'red',
        failed: 'red',
        invalid_credentials: 'red',
        verified: 'green',
    };
    // 使用服务真实写入的审计代码，未知代码仍保留原文供排障。
    Object.assign(actions, { 'orders.export': '导出订单', 'biz.local_close': '关闭业务订单' });
    Object.assign(results, { prepared: '已生成导出内容', verified: '已核验' });
    Object.assign(targets, { system_installation: '系统安装', payment_plugin: '支付插件' });
    function filter(name, label, options, advanced = false, searchable = false) {
        const wrapper = document.createElement('label');
        wrapper.textContent = label;
        const control = document.createElement(options ? 'select' : 'input');
        control.name = name;
        if (options) {
            if (searchable) control.setAttribute('lay-search', '');
            control.add(new Option('全部', ''));
            Object.entries(options).forEach(([value, text]) =>
                control.add(new Option(text, value)),
            );
        } else {
            control.className = 'layui-input';
            control.maxLength = 64;
        }
        if (locationValues.has(name)) control.value = locationValues.get(name);
        wrapper.append(control);
        document
            .getElementById(advanced ? 'records-advanced-filters' : 'records-main-filters')
            .append(wrapper);
    }
    config.main.forEach((name) =>
        filter(
            name,
            {
                status: '状态',
                plugin_code: '来源插件',
                channel_id: '收款通道',
                biz_no: '业务单号',
                action: '操作类型',
                result_code: '结果',
            }[name],
            name === 'status'
                ? config.states
                : name === 'action'
                  ? actions
                  : name === 'result_code'
                    ? results
                    : ['plugin_code', 'channel_id'].includes(name)
                      ? {}
                      : null,
            false,
            ['plugin_code', 'channel_id', 'action'].includes(name),
        ),
    );
    Object.entries(config.advanced).forEach(([name, label]) => filter(name, label, null, true));
    if (kind === 'notifications') filter('app_id', '应用', {}, false, true);
    ['start_date', 'end_date'].forEach((name) => {
        if (locationValues.has(name)) form.elements[name].value = locationValues.get(name);
    });
    // 从待处理队列进入时延续全部日期范围，并以可见开关让用户明确当前筛选。
    if (['receipts', 'notifications'].includes(kind)) {
        const label = document.createElement('label');
        label.className = 'admin-all-dates';
        label.innerHTML =
            '<input type="checkbox" name="all_dates" value="1" lay-skin="primary" title="全部日期">';
        document.getElementById('records-main-filters').append(label);
        form.elements.all_dates.checked = locationValues.get('all_dates') === '1';
        form.elements.all_dates.addEventListener('change', syncDates);
    }
    function syncDates() {
        const all = !!form.elements.all_dates?.checked,
            node = form.querySelector('[data-date-range]');
        form.elements.start_date.disabled = all;
        form.elements.end_date.disabled = all;
        node.classList.toggle('is-disabled', all);
    }
    syncDates();
    document.getElementById('records-date-help').dataset.help = config.date;
    const buttons = document.createElement('div');
    buttons.className = 'admin-filter-buttons';
    buttons.innerHTML =
        '<button type="submit" class="layui-btn">查询</button><button type="reset" class="layui-btn layui-btn-primary">重置</button>';
    document.getElementById('records-main-filters').append(buttons);
    if (Object.keys(config.advanced).some((name) => locationValues.has(name)))
        form.querySelector('details').open = true;
    UI.dateRange(form);
    UI.render(form);
    function labelValue(name, value) {
        if (value == null) return '—';
        if (name === 'plugin_code')
            return plugins.find((item) => item.code === value)?.name || value;
        if (name === 'channel_id')
            return channels.find((item) => item.id === value)?.name || '#' + value;
        if (name === 'action') return actions[value] || value;
        if (name === 'result_code') return results[value] || value;
        if (name === 'target_type') return targets[value] || value;
        return typeof value === 'boolean' ? (value ? '是' : '否') : String(value);
    }
    function column(name, title) {
        const centered = [
            'id',
            'admin_user_id',
            'status_label',
            'result_code',
            'retry_count',
            'flow_id',
            'attempt_no',
            'http_status',
            'target_type',
        ].includes(name);
        const compact = ['notify_no', 'biz_no'].includes(name);
        const width =
            name === 'status_label'
                ? 175
                : [
                        'retry_count',
                        'flow_id',
                        'attempt_no',
                        'http_status',
                        'admin_user_id',
                        'id',
                    ].includes(name)
                  ? 105
                  : name === 'amount'
                    ? 150
                    : undefined;
        return {
            field: name,
            title,
            width,
            minWidth:
                width ??
                (compact ? 190 : name.includes('_at') ? 178 : name === 'result_code' ? 210 : 140),
            align:
                name === 'amount' || name === 'duration_ms'
                    ? 'right'
                    : centered
                      ? 'center'
                      : 'left',
            templet: (row) => {
                if (name === 'flow_id')
                    return row[name]
                        ? '<a href="/admin/receipts?id=' +
                              encodeURIComponent(row[name]) +
                              '">' +
                              E(row[name]) +
                              '</a>'
                        : '—';
                if (name === 'amount')
                    return '<span class="admin-money">' + E(row[name]) + '</span>';
                if (name === 'status_label')
                    return UI.badge(row[name], notificationTones[row.status] || 'gray');
                if (name === 'result_code' && row.result_label) {
                    const accepted = row[name] === 'channel_accepted',
                        fault = row[name] === 'channel_fault';
                    return (
                        UI.badge(
                            accepted ? '已接收' : fault ? '处理异常' : '未通过',
                            accepted ? 'blue' : 'red',
                        ) +
                        ' ' +
                        UI.help(row.result_label)
                    );
                }
                if (name === 'result_code' && results[row[name]])
                    return UI.badge(results[row[name]], resultTones[row[name]] || 'blue');
                if (compact && row[name]) {
                    // 列表只缩短显示，完整编号保留在提示和详情中；跳转仍使用原始关联号。
                    const value = String(row[name]),
                        label =
                            value.length > 22 ? value.slice(0, 10) + '…' + value.slice(-6) : value;
                    return name === 'biz_no'
                        ? '<a class="admin-reference" title="' +
                              E(value) +
                              '" href="/admin/orders?biz_no=' +
                              encodeURIComponent(value) +
                              '">' +
                              E(label) +
                              '</a>'
                        : '<span class="admin-reference" title="' +
                              E(value) +
                              '">' +
                              E(label) +
                              '</span>';
                }
                return E(labelValue(name, row[name]));
            },
        };
    }
    function parameters() {
        const params = new URLSearchParams();
        new FormData(form).forEach((value, key) => {
            if (value !== '') params.set(key, value);
        });
        return params;
    }
    function load(current = 1) {
        const where = Object.fromEntries(parameters());
        if (recordsTable) {
            layui.table.reloadData('records-table', {
                where,
                page: { curr: current },
                scrollPos: 'fixed',
            });
            return;
        }
        recordsTable = UI.table({
            elem: '#records-table',
            id: 'records-table',
            url: config.url,
            where,
            page: { curr: current },
            feedback,
            done(result, curr) {
                page = curr;
            },
            cols: [
                [
                    ...Object.entries(config.columns).map(([name, title]) => column(name, title)),
                    {
                        title: '操作',
                        width: 88,
                        align: 'center',
                        fixed: 'right',
                        templet: () =>
                            '<button type="button" class="admin-link" lay-event="detail">详情</button>',
                    },
                ],
            ],
        });
    }
    async function show(row) {
        if (currentDialog && !currentDialog.closed) currentDialog.close();
        const handle = UI.open({
            title: config.title,
            drawer: true,
            width: 870,
            content: '<div class="records-detail-content"></div>',
        });
        currentDialog = handle;
        handle.feedback('正在读取详情…', 'loading');
        try {
            const record = config.detail
                ? (
                      await MpayHttp.request(
                          config.detail +
                              '?' +
                              new URLSearchParams({ [config.key]: row[config.key] }),
                      )
                  ).data
                : row;
            if (handle.closed) return;
            handle.feedback('');
            const labels = {
                id: '记录编号',
                amount: '实际金额（元）',
                status_label: '处理状态',
                reason_label: '核对说明',
                plugin_code: '来源插件',
                paid_at: '来源付款时间',
                received_at: '系统接收时间',
                platform_trade_no: '平台交易号',
                remark: '付款备注',
                biz_no: '业务单号',
                pay_no: '支付单号',
                notify_no: '通知号',
                retry_count: '已发送次数',
                first_ack_at: '首次商户确认',
                target_host: '通知目标',
                next_retry_at: '下次发送',
                last_notify_at: '最近发送',
                created_at: '记录时间',
                channel_id: '收款通道',
                flow_id: '关联流水',
                admin_user_id: '管理员 ID',
                action: '操作',
                result_code: '处理结果',
                target_type: '目标类型',
                target_id: '目标编号',
                client_ip: '连接 IP',
            };
            // 手机来源可能使用派生流水标识，不能将它承诺为平台官方交易号。
            labels.platform_trade_no = '来源流水号';
            const facts = {};
            Object.entries(labels).forEach(([name, label]) => {
                if (Object.hasOwn(record, name))
                    facts[label] =
                        name === 'result_code' && record.result_label
                            ? record.result_label
                            : labelValue(name, record[name]);
            });
            const technical = {};
            Object.entries({
                request_id: '请求编号',
                reason_code: '原因码',
                scope_key: '收款范围',
                payload_digest: '报文摘要',
                event_key: '流水身份',
                config_revision_id: '接收配置版本',
                plugin_version: '插件版本',
            }).forEach(([name, label]) => {
                if (Object.hasOwn(record, name)) technical[label] = record[name];
            });
            if (kind === 'notifications') facts['最近发送结果'] = record.reason_label;
            if (record.result_label) technical['结果代码'] = record.result_code;
            const summaries = {
                reason: '操作原因',
                origin: '操作入口',
                old_version: '修改前版本',
                new_version: '修改后版本',
                enabled: '启用状态',
                before_status: '修改前状态',
                after_status: '修改后状态',
                credential_version: 'KEY 版本',
                scope_count: '范围数量',
            };
            Object.entries(record.summary || {}).forEach(([name, value]) => {
                if (summaries[name]) facts[summaries[name]] = value;
                else technical[name] = value;
            });
            let links = '';
            if (record.biz_no)
                links +=
                    '<a class="layui-btn layui-btn-primary" href="/admin/orders?biz_no=' +
                    encodeURIComponent(record.biz_no) +
                    '">查看原订单</a>';
            if (record.flow_id)
                links +=
                    '<a class="layui-btn layui-btn-primary" href="/admin/receipts?id=' +
                    encodeURIComponent(record.flow_id) +
                    '">查看到账流水</a>';
            if (kind === 'audit' && record.target_type === 'biz_order' && record.target_id)
                links +=
                    '<a class="layui-btn layui-btn-primary" href="/admin/orders?biz_no=' +
                    encodeURIComponent(record.target_id) +
                    '">查看目标订单</a>';
            if (kind === 'audit' && record.target_type === 'payment_channel' && record.target_id)
                links +=
                    '<a class="layui-btn layui-btn-primary" href="/admin/configurations?channel_id=' +
                    encodeURIComponent(record.target_id) +
                    '">查看通道</a>';
            const historyId = 'records-history-' + Date.now();
            handle.node.querySelector('.records-detail-content').innerHTML =
                UI.facts(facts) +
                (links ? '<div class="admin-filter-buttons">' + links + '</div>' : '') +
                (config.history
                    ? '<div class="admin-section-title">' +
                      (kind === 'notifications' ? '发送记录' : '处理记录') +
                      '</div><table id="' +
                      historyId +
                      '" lay-filter="records-history"></table><div class="records-history-feedback admin-feedback" role="status"></div>'
                    : '') +
                (Object.keys(technical).length
                    ? '<details class="admin-details"><summary>技术信息</summary>' +
                      UI.facts(technical) +
                      '</details>'
                    : '');
            function history(current) {
                const columns =
                    kind === 'notifications'
                        ? [
                              column('attempt_no', '次数'),
                              column('started_at', '发送时间'),
                              column('http_status', 'HTTP 状态'),
                              column('duration_ms', '耗时（毫秒）'),
                              column('result_label', '发送结果'),
                              UI.responseColumn(),
                          ]
                        : [
                              column('created_at', '时间'),
                              column('result_label', '处理说明'),
                              column('request_id', '请求编号'),
                          ];
                UI.table({
                    elem: '#' + historyId,
                    url: config.history,
                    where: { [config.key]: record[config.key] },
                    page: { curr: current },
                    feedback: handle.node.querySelector('.records-history-feedback'),
                    cols: [columns],
                });
            }
            if (config.history) history(1);
        } catch (exception) {
            if (!handle.closed) handle.error(exception);
        }
    }
    // 固定过滤器只绑定一次，重复打开详情不会累积按时间戳命名的事件。
    layui.table.on('tool(records-table)', (event) => {
        if (event.event === 'detail') show(event.data);
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        load(1);
    });
    UI.onReset(form, () => {
        syncDates();
        UI.render(form);
        load(1);
    });
    document.getElementById('records-refresh').addEventListener('click', () => load(page));
    Promise.allSettled([
        MpayHttp.request('/adminapi/configuration/plugins'),
        UI.all('/adminapi/configuration/channels'),
        kind === 'notifications'
            ? MpayHttp.request('/adminapi/applications')
            : Promise.resolve(null),
    ]).then((results) => {
        if (results[0].status === 'fulfilled') plugins = results[0].value.data.items;
        if (results[1].status === 'fulfilled') channels = results[1].value;
        if (form.elements.plugin_code) {
            plugins.forEach((item) =>
                form.elements.plugin_code.add(new Option(item.name, item.code)),
            );
            if (locationValues.has('plugin_code'))
                form.elements.plugin_code.value = locationValues.get('plugin_code');
        }
        if (form.elements.channel_id) {
            channels.forEach((item) =>
                form.elements.channel_id.add(new Option(item.name, item.id)),
            );
            if (locationValues.has('channel_id'))
                form.elements.channel_id.value = locationValues.get('channel_id');
        }
        if (form.elements.app_id && results[2].status === 'fulfilled') {
            results[2].value.data.items.forEach((item) =>
                form.elements.app_id.add(new Option(item.name, item.id)),
            );
            if (locationValues.has('app_id'))
                form.elements.app_id.value = locationValues.get('app_id');
        }
        UI.render(form);
        load(1);
    });
    if (config.detail && locationValues.has(config.key))
        show({ [config.key]: locationValues.get(config.key) });
});
