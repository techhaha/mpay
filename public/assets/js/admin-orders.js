/** 订单列表和抽屉展示服务端事实；动作按服务返回的能力开放，真实到账证据必须由管理员填写。 */
MpayAdmin.ready(async function () {
    'use strict';
    const UI = MpayAdmin,
        E = UI.escape,
        filters = document.getElementById('order-filters');
    if (!filters) return;
    await UI.loadCatalog();
    const feedback = document.getElementById('admin-feedback'),
        locationValues = new URLSearchParams(location.search);
    let page = 1,
        detailSequence = 0,
        currentDialog = null,
        orderTable = null,
        apps = [],
        channels = [],
        groups = [];
    const operationLabels = {
        manual_receipt_confirmed: '人工确认到账',
        manual_confirmation_replayed: '重复确认已处理',
        local_close: '关闭订单',
        local_close_replayed: '重复关闭已处理',
        manual_notify_scheduled: '已安排商户通知',
        manual_notify_replayed: '重复补发请求已处理',
        manual_refund_requested: '申请退款',
    };
    const col = (field, title, width = 150) => ({
        field,
        title,
        minWidth: width,
        templet: (row) =>
            field === 'result_code' && row.result_label
                ? E(row.result_label) + ' ' + UI.help(row.result_code || '无结果代码')
                : field === 'action' && operationLabels[row.action]
                  ? E(operationLabels[row.action]) + ' ' + UI.help(row.action)
                  : E(row[field] ?? '—'),
    });
    for (const name of [
        'app_id',
        'pid',
        'poll_group_id',
        'channel_id',
        'pay_type',
        'status',
        'notification_status',
        'start_date',
        'end_date',
    ])
        if (locationValues.has(name)) filters.elements[name].value = locationValues.get(name);
    for (const name of [
        'biz_no',
        'pay_no',
        'merchant_order_no',
        'channel_order_no',
        'channel_trade_no',
    ])
        if (locationValues.has(name)) {
            filters.elements.number_kind.value = name;
            filters.elements.number.value = locationValues.get(name);
            break;
        }
    if (
        ['notification_status', 'pid', 'poll_group_id', 'channel_id'].some((name) =>
            locationValues.has(name),
        )
    )
        filters.querySelector('details').open = true;
    UI.dateRange(filters);
    UI.render(filters);
    function parameters() {
        const params = new URLSearchParams();
        new FormData(filters).forEach((value, key) => {
            if (value !== '' && !['number', 'number_kind'].includes(key)) params.set(key, value);
        });
        if (filters.elements.number.value.trim())
            params.set(filters.elements.number_kind.value, filters.elements.number.value.trim());
        return params;
    }
    const appName = (row) => apps.find((app) => app.id === row.app_id)?.name || 'PID ' + row.pid;
    const channelName = (row) =>
        row.channel_id
            ? channels.find((channel) => channel.id === row.channel_id)?.name ||
              '通道 #' + row.channel_id
            : '未分配';
    const paymentName = (row) =>
        row.pay_type
            ? UI.paymentLabels[row.pay_type] || row.pay_type
            : row.pay_type_id
              ? '未知方式'
              : '未选择';
    function notification(row) {
        const full = row.notification_status_label || '尚未生成通知';
        const label =
            { 0: '待发送 / 重试', 1: '发送中', 2: '通知成功', 3: '停止重试', 4: '暂不通知' }[
                row.notification_status
            ] ||
            (full === '未设置通知地址' ? '无通知地址' : full === '尚未生成通知' ? '待生成' : full);
        const tone =
            row.notification_status === 2
                ? 'green'
                : [0, 1, 3].includes(row.notification_status)
                  ? 'orange'
                  : full.includes('异常')
                    ? 'red'
                    : 'gray';
        return (
            '<div class="admin-two-line"><span title="' +
            E(full) +
            '">' +
            UI.badge(label, tone) +
            '</span>' +
            (row.notification_first_ack_at && row.notification_status !== 2
                ? '<span class="admin-row-sub">商户曾确认成功</span>'
                : '') +
            '</div>'
        );
    }
    function orderState(row) {
        const tones = {
            0: 'blue',
            1: 'blue',
            2: 'green',
            3: 'red',
            4: 'gray',
            5: 'gray',
            refund_partial: 'blue',
            refund_full: 'gray',
            refund_processing: 'blue',
            refund_unknown: 'orange',
            refund_failed: 'green',
        };
        const detail =
            row.display_status === 'refund_failed'
                ? '退款未成功'
                : ['refund_processing', 'refund_unknown'].includes(row.display_status)
                  ? '¥' + row.refund_pending_amount
                  : ['refund_partial', 'refund_full'].includes(row.display_status)
                    ? '¥' + row.refunded_amount
                    : '';
        const label = row.display_status_label + (detail ? ' · ' + detail : '');
        const badge = UI.badge(label, tones[row.display_status] || 'gray');
        const title =
            '累计已退 ¥' + row.refunded_amount + '；未决占用 ¥' + row.refund_pending_amount;
        return '<span title="' + E(title) + '">' + badge + '</span>';
    }
    // 固定列结构只渲染一次，后续交给官方数据重载保留列宽及条数。
    function load(current = 1) {
        const where = Object.fromEntries(parameters());
        if (orderTable) {
            layui.table.reloadData('order-table', {
                where,
                page: { curr: current },
                scrollPos: 'fixed',
            });
            return;
        }
        const columns = [
            [
                // 文本列仅限制最小宽度，由 table 自动分配余宽；短语义列继续保持固定宽度。
                {
                    field: 'biz_no',
                    title: '订单编号',
                    minWidth: 228,
                    templet: (row) =>
                        '<div class="admin-two-line"><button type="button" class="admin-reference admin-order-number admin-order-link" lay-event="detail" title="查看订单详情">' +
                        E(row.biz_no) +
                        '</button><span class="admin-row-sub admin-order-number" title="商户订单号：' +
                        E(row.merchant_order_no) +
                        '">' +
                        E(row.merchant_order_no) +
                        '</span></div>',
                },
                {
                    field: 'app_id',
                    title: '应用 / 商品',
                    minWidth: 160,
                    templet: (row) =>
                        '<div class="admin-two-line"><span class="admin-row-title" title="' +
                        E(appName(row)) +
                        '">' +
                        E(appName(row)) +
                        '</span><span class="admin-row-sub" title="' +
                        E(row.subject) +
                        '">' +
                        E(row.subject) +
                        '</span></div>',
                },
                {
                    field: 'pay_type',
                    title: '支付方式',
                    width: 130,
                    unresize: true,
                    align: 'center',
                    templet: (row) =>
                        row.pay_type
                            ? UI.payment(row.pay_type)
                            : '<span class="admin-muted">' + E(paymentName(row)) + '</span>',
                },
                {
                    field: 'channel_id',
                    title: '收款通道',
                    minWidth: 155,
                    templet: (row) =>
                        '<span title="' +
                        E(channelName(row)) +
                        '">' +
                        E(channelName(row)) +
                        '</span>',
                },
                {
                    field: 'order_amount',
                    title: '金额（元）',
                    minWidth: 130,
                    align: 'right',
                    templet: (row) =>
                        '<div class="admin-two-line"><span class="admin-money" title="订单金额">' +
                        E(row.order_amount) +
                        '</span><span class="admin-row-sub">实收 ' +
                        E(row.received_amount ?? '—') +
                        '</span></div>',
                },
                {
                    field: 'display_status',
                    title: '订单状态',
                    minWidth: 180,
                    align: 'center',
                    templet: orderState,
                },
                {
                    field: 'notification_status',
                    title: '商户通知',
                    width: 148,
                    align: 'center',
                    templet: notification,
                },
                {
                    field: 'created_at',
                    title: '交易时间',
                    width: 212,
                    templet: (row) =>
                        '<div class="admin-two-line admin-order-time"><span title="创建时间">' +
                        E(row.created_at) +
                        '</span><span class="admin-row-sub" title="付款时间">' +
                        E(row.paid_at ?? '—') +
                        '</span></div>',
                },
                {
                    title: '操作',
                    width: 80,
                    align: 'center',
                    fixed: 'right',
                    templet: () =>
                        '<button type="button" class="admin-link" lay-event="detail">详情</button>',
                },
            ],
        ];
        orderTable = UI.table({
            elem: '#order-table',
            id: 'order-table',
            url: '/adminapi/orders/list',
            where,
            className: 'admin-table-two-line admin-orders-table',
            toolbar: false,
            defaultToolbar: [],
            page: { curr: current },
            feedback,
            done(result, curr) {
                page = curr;
            },
            cols: columns,
        });
    }
    function translatedOrder(order) {
        return {
            '业务单号': order.biz_no,
            '商户订单号': order.merchant_order_no,
            '支付单号': order.pay_no,
            '渠道订单号': order.channel_order_no,
            '渠道交易号': order.channel_trade_no,
            '应用': appName(order),
            '支付方式': paymentName(order),
            '支付状态': order.status_label,
            '退款情况': order.refund_status_label,
            '轮询组':
                groups.find((group) => group.id === order.poll_group_id)?.name ||
                order.poll_group_id,
            '收款通道': channelName(order),
            '收款依据': order.confirmation_source_label,
            '创建时间': order.created_at,
            '付款时间': order.paid_at,
            '有效截止时间': order.expires_at,
        };
    }
    function refundFacts(summary) {
        return UI.facts({
            '已确认实际支付（元）': summary.paid_amount,
            '已退款（元）': summary.refunded_amount,
            '处理中 / 待核实（元）': summary.pending_amount,
            '剩余可退（元）': summary.available_amount,
            '退款情况': summary.status_label,
        });
    }
    function detailContent(data, focusNotification, refundFeedback) {
        const order = data.order,
            hasRefundTab = order.status === 2 || data.refunds.length > 0;
        const callbacks =
            '<table class="order-callbacks-table"></table><div class="order-callbacks-feedback admin-feedback" role="status"></div>';
        const notification = data.notification
            ? UI.facts({
                  '通知号': data.notification.notify_no,
                  '当前安排': data.notification.status_label,
                  '通知目标': data.notification.target_host,
                  '首次商户确认': data.notification.first_ack_at || '尚未确认',
                  '已发送次数': data.notification.retry_count,
                  '最近发送': data.notification.last_notify_at,
                  '最近发送结果': data.notification.reason_label,
                  '下次发送': data.notification.next_retry_at,
              }) +
              (data.actions.retry_notification
                  ? '<div class="admin-filter-buttons"><button type="button" class="layui-btn order-notify-send">' +
                    E(data.actions.notification_action) +
                    '</button></div>'
                  : '') +
              '<div class="admin-section-title">发送记录</div><table class="order-notify-table"></table><div class="order-notify-feedback admin-feedback" role="status"></div>'
            : UI.notice('尚无商户通知');
        const refunds =
            refundFacts(data.refund_summary) +
            (order.status === 2 && data.refund_summary.paid_amount === null
                ? UI.notice('支付单缺少已确认的实际支付金额，暂不能申请退款。', true)
                : '') +
            (data.refund_summary.has_unresolved
                ? UI.notice('已有退款处理中或结果待核实，请先核对平台结果，不要重复申请。', true)
                : '') +
            UI.notice(
                '可退金额按已确认的实际支付金额计算；是否支持退款及部分退款，由原订单插件和平台决定。',
            ) +
            (data.refunds.length
                ? data.refunds
                      .map(
                          (refund) =>
                              '<div class="admin-section-title">' +
                              E(refund.refund_no) +
                              '</div>' +
                              UI.facts({
                                  '退款金额（元）': refund.amount,
                                  '状态': refund.status_label,
                                  '说明': refund.result_label,
                                  '平台退款号': refund.channel_refund_no,
                                  '创建时间': refund.created_at,
                                  '更新时间': refund.updated_at,
                              }),
                      )
                      .join('')
                : UI.notice('尚无退款记录'));
        return (
            '<div class="admin-app-summary"><div><h2>' +
            E(order.subject) +
            '</h2><span class="admin-muted">' +
            E(order.biz_no) +
            '</span> <button type="button" class="admin-link" data-copy="' +
            E(order.biz_no) +
            '">复制</button></div>' +
            orderState(order) +
            '</div>' +
            (refundFeedback ? UI.notice(refundFeedback.message, refundFeedback.status !== 1) : '') +
            '<div class="admin-form-grid">' +
            [
                ['订单原金额', order.order_amount],
                ['提示付款金额', order.receipt_amount],
                ['已确认实收', order.received_amount],
            ]
                .map(
                    ([title, value]) =>
                        '<div class="admin-fact"><label>' +
                        title +
                        '（元）</label><p class="admin-money">' +
                        E(value ?? '—') +
                        '</p></div>',
                )
                .join('') +
            '</div>' +
            UI.tabs(
                [
                    {
                        title: '订单概况',
                        content:
                            UI.facts(translatedOrder(order)) +
                            (data.actions.confirm_unavailable_reason
                                ? UI.notice(data.actions.confirm_unavailable_reason)
                                : ''),
                    },
                    ...(hasRefundTab ? [{ title: '退款记录', content: refunds }] : []),
                    { title: '收款回调', content: callbacks },
                    { title: '商户通知', content: notification },
                    {
                        title: '处理记录',
                        content:
                            '<table class="order-history-table"></table><div class="order-history-feedback admin-feedback" role="status"></div>' +
                            (order.channel_id
                                ? '<details class="admin-details"><summary>技术信息</summary>' +
                                  UI.facts({
                                      '支付产品（pay_product）': order.pay_product,
                                      '支付动作（pay_action）': order.pay_action,
                                  }) +
                                  '<a class="admin-link" href="/admin/callbacks?channel_id=' +
                                  encodeURIComponent(order.channel_id) +
                                  '">查询该通道回调</a></details>'
                                : ''),
                    },
                ],
                focusNotification ? (hasRefundTab ? 3 : 2) : refundFeedback && hasRefundTab ? 1 : 0,
            )
        );
    }
    async function show(number, focusNotification = false, refundFeedback = null) {
        const sequence = ++detailSequence;
        if (currentDialog && !currentDialog.closed) currentDialog.close();
        const handle = UI.open({
            title: '订单详情',
            drawer: true,
            width: 900,
            content: '<div class="order-detail-content"></div>',
        });
        currentDialog = handle;
        handle.feedback('正在读取订单详情…', 'loading');
        try {
            const data = (
                await MpayHttp.request(
                    '/adminapi/orders/detail?' + new URLSearchParams({ biz_no: number }),
                )
            ).data;
            if (sequence !== detailSequence || handle.closed) return;
            handle.feedback('');
            handle.node.querySelector('.order-detail-content').innerHTML = detailContent(
                data,
                focusNotification,
                refundFeedback,
            );
            const footer = handle.node.querySelector('.admin-dialog-footer');
            [
                ['close', '关闭订单', data.actions.close],
                ['confirm', '人工确认到账', data.actions.confirm],
            ].forEach(([kind, label, allowed]) => {
                if (!allowed) return;
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'layui-btn ' + (kind === 'confirm' ? '' : 'layui-btn-primary');
                button.textContent = label;
                button.addEventListener('click', () => {
                    handle.close();
                    action(kind, data);
                });
                footer.append(button);
            });
            if (data.actions.refund) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'layui-btn layui-btn-primary';
                button.textContent = '申请退款';
                button.addEventListener('click', () => {
                    handle.close();
                    refund(data);
                });
                footer.append(button);
            }
            handle.node.querySelector('.order-notify-send')?.addEventListener('click', () => {
                handle.close();
                action('retry-notification', data);
            });
            UI.render(handle.node);
            function history(current, kind = 'operations') {
                const prefix =
                    kind === 'notifications'
                        ? 'order-notify'
                        : kind === 'callbacks'
                          ? 'order-callbacks'
                          : 'order-history';
                const columns =
                    kind === 'operations'
                        ? [
                              col('created_at', '时间', 175),
                              {
                                  title: '操作',
                                  templet: (row) => E(row.action_label || row.action),
                              },
                              col('actor_label', '操作者'),
                              col('reason', '原因', 220),
                          ]
                        : kind === 'callbacks'
                          ? [
                                col('received_at', '接收时间', 175),
                                {
                                    title: '处理结果',
                                    templet: (row) =>
                                        UI.badge(
                                            row.result_label,
                                            {
                                                1: 'green',
                                                2: 'blue',
                                                3: 'orange',
                                                4: 'red',
                                                5: 'gray',
                                            }[row.result_status] || 'gray',
                                        ),
                                },
                                col('amount', '实收金额（元）', 135),
                                col('message', '处理说明', 220),
                                {
                                    title: '详情',
                                    width: 80,
                                    templet: (row) =>
                                        '<a class="admin-link" href="/admin/callbacks?id=' +
                                        encodeURIComponent(row.id) +
                                        '">查看</a>',
                                },
                            ]
                          : [
                                { ...col('attempt_no', '次数', 80), align: 'center' },
                                col('started_at', '开始时间', 175),
                                { ...col('http_status', 'HTTP 状态', 100), align: 'center' },
                                { ...col('duration_ms', '耗时（毫秒）', 110), align: 'right' },
                                col('result_code', '结果'),
                                UI.responseColumn(),
                            ];
                UI.table({
                    elem: handle.node.querySelector('.' + prefix + '-table'),
                    url: '/adminapi/orders/history',
                    where: { biz_no: number, kind },
                    page: { curr: current },
                    feedback: handle.node.querySelector('.' + prefix + '-feedback'),
                    cols: [columns],
                });
            }
            history(1);
            history(1, 'callbacks');
            if (data.notification) history(1, 'notifications');
        } catch (exception) {
            if (!handle.closed) handle.error(exception);
        }
    }
    function refund(data) {
        const summary = data.refund_summary;
        const content =
            UI.notice(
                '本操作会申请实际退款。平台可能要求全额退款；请核对金额，结果未知时先核对原退款，不要重复申请。',
                true,
            ) +
            UI.facts({ '订单': data.order.biz_no }) +
            refundFacts(summary) +
            '<div class="admin-form-grid">' +
            UI.field(
                '退款金额（元）',
                UI.input('refund_amount', summary.available_amount, 'required inputmode="decimal"'),
                { required: true, full: true },
            ) +
            UI.field('退款备注', UI.textarea('reason', '', 'maxlength="512"'), { full: true }) +
            UI.field(
                '管理员密码',
                UI.input(
                    'current_password',
                    '',
                    'type="password" required maxlength="72" autocomplete="current-password"',
                ),
                { full: true, required: true },
            ) +
            '</div>';
        UI.form({
            title: '申请退款',
            width: 620,
            content,
            submit: '确认申请退款',
            async onSubmit(form, handle) {
                let result;
                try {
                    result = await MpayHttp.request('/adminapi/orders/refund', {
                        method: 'POST',
                        data: {
                            biz_no: data.order.biz_no,
                            request_no: summary.request_no,
                            refund_amount: form.elements.refund_amount.value,
                            reason: form.elements.reason.value,
                            current_password: form.elements.current_password.value,
                        },
                    });
                } finally {
                    form.elements.current_password.value = '';
                }
                // 请求完成后即关闭提交入口；后续展示失败不能让同一次退款再次发送。
                handle.clean();
                handle.close();
                load(page);
                show(data.order.biz_no, false, {
                    message: result.message,
                    status: result.data.status,
                });
            },
        });
    }
    function action(kind, data) {
        const titles = {
            confirm: '人工确认到账',
            close: '关闭订单',
            'retry-notification': data.actions.notification_action,
        };
        const impacts = {
            confirm: '请先核对真实到账依据。确认后订单标记为支付成功，可选择是否发送商户通知。',
            close: '关闭后订单停止等待付款，但原收款二维码仍可能收到转账。',
            'retry-notification': data.notification?.first_ack_at
                ? '商户此前已确认接收。本次将向原地址追加一次通知，请核对是否需要重复处理。'
                : data.notification?.status === 4
                  ? '开始向原业务网站发送付款结果；未确认时按正常规则自动重试。'
                  : '使用原通知任务发送付款结果，支付状态保持不变。',
        };
        let content =
            UI.notice(impacts[kind], true) +
            UI.facts({ '订单': data.order.biz_no, '原订单金额（元）': data.order.order_amount }) +
            (kind === 'retry-notification'
                ? UI.facts({
                      '通知目标': data.notification.target_host,
                      '首次商户确认': data.notification.first_ack_at || '尚未确认',
                      '最近发送结果': data.notification.reason_label,
                  })
                : '') +
            '<div class="admin-form-grid">';
        if (kind === 'confirm')
            content +=
                UI.field(
                    '实际收款金额（元）',
                    UI.input('received_amount', '', 'required inputmode="decimal"'),
                    { required: true },
                ) +
                UI.field(
                    '实际付款时间（上海时区）',
                    UI.input(
                        'paid_at',
                        '',
                        'required type="text" data-admin-date="datetime" placeholder="yyyy-MM-dd HH:mm:ss" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}"',
                    ),
                    { required: true },
                ) +
                UI.field(
                    '平台交易号',
                    UI.input('platform_trade_no', '', 'maxlength="128" autocomplete="off"'),
                ) +
                UI.field('核对依据', UI.textarea('reference', '', 'required maxlength="1024"'), {
                    full: true,
                    required: true,
                }) +
                UI.field(
                    '管理员密码',
                    UI.input(
                        'current_password',
                        '',
                        'type="password" required maxlength="72" autocomplete="current-password"',
                    ),
                    { full: true, required: true },
                ) +
                UI.field(
                    '商户通知',
                    '<input type="checkbox" name="notify_merchant" lay-skin="primary" title="确认后通知商户" checked>',
                    {
                        full: true,
                        help: '取消后记为暂不通知，之后可以手动发送；商户主动查询和页面回跳仍会获知支付成功。',
                    },
                );
        content +=
            UI.field('操作原因', UI.textarea('reason', '', 'required maxlength="512"'), {
                full: true,
                required: true,
            }) + '</div>';
        UI.form({
            title: titles[kind],
            width: 730,
            content,
            submit:
                kind === 'confirm'
                    ? '已核实，确认到账'
                    : kind === 'close'
                      ? '确认关闭'
                      : data.actions.notification_action,
            async onSubmit(form, handle) {
                const input = { biz_no: data.order.biz_no, reason: form.elements.reason.value };
                if (kind === 'confirm') {
                    for (const key of [
                        'received_amount',
                        'reference',
                        'platform_trade_no',
                        'current_password',
                    ])
                        input[key] = form.elements[key].value;
                    input.paid_at = form.elements.paid_at.value;
                    input.notify_merchant = form.elements.notify_merchant.checked;
                } else if (kind === 'retry-notification')
                    input.expected_attempts = data.notification.retry_count;
                try {
                    const result = await MpayHttp.request('/adminapi/orders/' + kind, {
                        method: 'POST',
                        data: input,
                    });
                    handle.clean();
                    handle.close();
                    UI.message(result.message);
                    load(page);
                    show(data.order.biz_no, kind === 'retry-notification');
                } catch (exception) {
                    handle.error(exception);
                    if (
                        exception.code === 0 ||
                        exception.code >= 50000 ||
                        exception instanceof TypeError
                    ) {
                        handle.feedback('处理结果尚未确认，请先重新读取原订单，勿重复提交。');
                        const submit = handle.node.querySelector('[type=submit]');
                        submit.dataset.keepDisabled = '1';
                        submit.disabled = true;
                        const check = document.createElement('button');
                        check.className = 'layui-btn layui-btn-primary';
                        check.type = 'button';
                        check.textContent = '读取原订单';
                        check.addEventListener('click', () => {
                            handle.clean();
                            handle.close();
                            show(data.order.biz_no, kind === 'retry-notification');
                        });
                        handle.node.querySelector('.admin-dialog-footer').append(check);
                    }
                } finally {
                    if (form.elements.current_password) form.elements.current_password.value = '';
                }
            },
        });
    }
    layui.table.on('tool(order-table)', (event) => {
        if (event.event === 'detail') show(event.data.biz_no);
    });
    filters.addEventListener('submit', (event) => {
        event.preventDefault();
        if (UI.valid(filters)) load(1);
    });
    UI.onReset(filters, () => {
        UI.render(filters);
        load(1);
    });
    document.getElementById('order-refresh').addEventListener('click', () => {
        if (UI.valid(filters)) load(page);
    });
    document.getElementById('order-export').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        try {
            const bytes = await MpayHttp.download(
                '/adminapi/orders/export?' + parameters(),
                'mpay-orders.csv',
            );
            UI.message('导出文件已准备下载（' + (bytes / 1024).toFixed(1) + ' KB）');
        } catch (exception) {
            UI.error(feedback, exception);
        } finally {
            button.disabled = false;
        }
    });
    document.getElementById('order-notifications').addEventListener('click', (event) => {
        event.preventDefault();
        const params = new URLSearchParams({ all_dates: '1' }),
            status = filters.elements.notification_status.value;
        if (filters.elements.app_id.value) params.set('app_id', filters.elements.app_id.value);
        if (status !== '' && status !== 'none') params.set('status', status);
        location.assign('/admin/notifications?' + params);
    });
    Promise.allSettled([
        MpayHttp.request('/adminapi/applications'),
        MpayHttp.request('/adminapi/poll-groups'),
        UI.all('/adminapi/configuration/channels'),
    ]).then((results) => {
        if (results[0].status === 'fulfilled') {
            apps = results[0].value.data.items;
            apps.forEach((app) =>
                filters.elements.app_id.add(new Option(app.name + ' / ' + app.pid, app.id)),
            );
        }
        if (results[1].status === 'fulfilled') {
            groups = results[1].value.data.items;
            groups.forEach((group) =>
                filters.elements.poll_group_id.add(new Option(group.name, group.id)),
            );
        }
        if (results[2].status === 'fulfilled') {
            channels = results[2].value;
            channels.forEach((channel) =>
                filters.elements.channel_id.add(new Option(channel.name, channel.id)),
            );
        }
        for (const name of ['app_id', 'poll_group_id', 'channel_id'])
            if (locationValues.has(name)) filters.elements[name].value = locationValues.get(name);
        // 配置摘要和事件就绪后才开放查询，避免页面加载期间触发原生 GET 丢失筛选状态。
        UI.render(filters);
        filters.querySelector('[data-order-query]').disabled = false;
        document.getElementById('order-refresh').disabled = false;
        load(1);
    });
    if (locationValues.has('biz_no')) show(locationValues.get('biz_no'));
});
