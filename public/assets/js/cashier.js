/**
 * 收银台展示与原单查询。金额、备注、是否允许付款以及成功状态均来自服务器，页面不另建交易规则。
 * 写请求超时后只查询原订单；隐藏/恢复页面不重新下单，也不自动重放未知的外部请求。
 */
layui.use('layer', function () {
    'use strict';
    const root = document.getElementById('cashier');
    if (!root) return;
    const identity = {biz_no: root.dataset.bizNo, token: root.dataset.token};
    const find = name => document.getElementById('cashier-' + name);
    const query = new URLSearchParams(identity).toString();
    let current = null;
    let generation = 0;
    let fetching = false;
    let queued = false;
    let writing = false;
    let timer = null;
    let receivedAt = 0;
    let qrKey = '';
    let expiryRefreshRequested = false;
    let writeFailure = null;

    function error(message) {
        find('error').textContent = message;
        find('error').hidden = !message;
    }

    /** 直接绘制库产生的模块矩阵，避免把付款内容插入 HTML；完整保留 UTF-8 字节与静区。 */
    function drawQr(presentation) {
        const key = JSON.stringify([presentation.qrcode_content, presentation.qrcode_image_url]);
        if (key === qrKey) return;
        qrKey = '';
        find('qr').replaceChildren();
        if (presentation.qrcode_image_url) {
            const url = new URL(presentation.qrcode_image_url, location.origin);
            if (url.origin !== location.origin || !url.pathname.startsWith('/storage/')) throw new Error('二维码图片地址无效');
            const image = document.createElement('img');
            image.src = url.href;
            image.alt = '收款二维码';
            image.addEventListener('error', () => { qrKey = ''; error('二维码图片暂时无法读取，请查询原订单或联系商户。'); });
            find('qr').append(image);
        } else {
            const encoding = presentation.qrcode_encoding;
            if (!encoding || encoding.mode !== 'Byte' || encoding.error_correction !== 'M'
                || !Number.isInteger(encoding.max_text_bytes) || encoding.max_text_bytes < 1
                || new TextEncoder().encode(presentation.qrcode_content).length > encoding.max_text_bytes) {
                throw new Error('收款二维码无法生成，请联系商户检查收款配置。');
            }
            const matrix = qrcode(0, encoding.error_correction);
            matrix.addData(presentation.qrcode_content, encoding.mode);
            matrix.make();
            const count = matrix.getModuleCount();
            const cell = Math.max(4, Math.floor(300 / (count + 8)));
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = (count + 8) * cell;
            const context = canvas.getContext('2d');
            if (!context) throw new Error('当前浏览器无法显示二维码');
            context.fillStyle = '#fff'; context.fillRect(0, 0, canvas.width, canvas.height);
            context.fillStyle = '#000';
            for (let row = 0; row < count; row++) {
                for (let column = 0; column < count; column++) {
                    if (matrix.isDark(row, column)) context.fillRect((column + 4) * cell, (row + 4) * cell, cell, cell);
                }
            }
            find('qr').append(canvas);
        }
        qrKey = key;
    }

    function apply(data) {
        if (!data || typeof data.status !== 'number' || typeof data.order_money !== 'string' || data.biz_no !== identity.biz_no) {
            throw new Error('订单响应格式异常，请稍后查询');
        }
        // 成功查询不等于此前选择/恢复成功。状态未变时保留操作拒绝，避免错误闪退后像按钮无反应。
        // 丢失写响应但已读到新尝试、状态或付款材料时，则以已核验的原单状态解除不确定提示。
        if (writeFailure && (data.pay_no !== writeFailure.payNo || data.status !== writeFailure.status || data.can_pay || data.expired)) {
            writeFailure = null;
        }
        error(writeFailure?.message || '');
        current = data;
        receivedAt = Date.now();
        expiryRefreshRequested = false;
        root.dataset.status = String(data.status);
        find('test').hidden = !data.test_mode;
        find('status').textContent = data.test_mode ? '测试：' + data.status_label : data.status_label;
        find('subject').textContent = data.subject;
        find('order-no').textContent = data.pay_no || data.biz_no;
        find('business-money').textContent = '¥ ' + data.order_money;
        // 已成功、关闭或过期后不再显示付款指令，避免用户把历史识别金额当成仍需付款。
        find('receipt-block').hidden = data.receipt_money === null || !data.can_pay;
        find('receipt-money').textContent = data.receipt_money === null ? '—' : '¥ ' + data.receipt_money;
        find('offset').hidden = !data.can_pay || data.offset_money === null || data.offset_money === '0.00';
        find('offset').textContent = '为识别本次付款，支付金额增加了 ¥ ' + data.offset_money + '。请按“请准确支付”显示的金额付款。';
        find('selection').hidden = !data.can_select;
        find('methods').replaceChildren();
        for (const method of data.payment_methods) {
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'layui-btn layui-btn-primary'; button.dataset.type = method.code;
            const methodIcon = MpayBrand.paymentImage(method.code); if (methodIcon) { methodIcon.alt = ''; button.append(methodIcon); }
            button.append(document.createTextNode(method.name));
            button.disabled = writing;
            button.addEventListener('click', () => write('/api/cashier/select', {type: method.code}));
            find('methods').append(button);
        }
        find('payment').hidden = !data.can_pay;
        if (data.can_pay) {
            try {
                if (data.presentation.pay_page !== 'page' || data.presentation.pay_params?._page !== 'receiptQrcode') {
                    throw new Error('付款页面类型不受支持，请查询原订单');
                }
                drawQr(data.presentation.pay_params);
            } catch (failure) { find('payment').hidden = true; error(failure.message); }
        } else {
            find('qr').replaceChildren(); qrKey = '';
        }
        if (Array.isArray(data.payment_brands)) MpayBrand.setPayments(data.payment_brands);
        find('method-brand').replaceChildren();
        const paymentBrand = MpayBrand.paymentImage(data.pay_type, true); if (paymentBrand) find('method-brand').append(paymentBrand);
        const paymentName = MpayBrand.paymentAsset(data.pay_type)?.name || '对应付款应用';
        find('pay-hint').textContent = '请使用' + paymentName + '扫码，并填写页面要求的准确金额。';
        find('remark-block').hidden = data.match_mode !== 'remark';
        find('remark').textContent = data.remark || '';
        find('prepare').hidden = !data.can_prepare;
        find('prepare').disabled = writing;
        find('return').hidden = !data.return_allowed;
        find('return').href = '/cashier/return?' + query;
        let notice;
        if (data.status === 2) {
            notice = data.test_mode ? '测试订单已模拟收款 ¥ ' + data.received_money + '，不代表实际收款结果。' : '已核实收款 ¥ ' + data.received_money + '。';
            notice += data.notification_status === 'success' ? '商户已确认接收付款通知。'
                : data.notification_status === 'held' ? '收款方暂未发送商户通知，如需处理请联系商户。'
                : data.notification_status === 'exhausted' ? '商户通知需要核对，请保存订单号并联系商户。' : '正在向商户发送付款通知，请勿重复支付。';
            if (!data.return_allowed) notice += '商户未设置返回页面，请保存订单号。';
        } else if (data.status === 3) {
            notice = '支付未能完成，请联系商户。若已付款，请提供订单号核对。';
        } else if (data.expired || data.status === 4 || data.status === 5) {
            notice = '此订单已停止收款，请勿继续付款。若已付款，请刷新支付结果或联系商户核对。';
        } else if (data.can_select) {
            notice = data.payment_methods.length ? '请选择支付方式，选定后此订单的支付方式不可更改。' : '暂时没有可用的支付方式，请联系商户。';
        } else if (data.processing) {
            notice = '正在准备付款信息，请稍候。若已付款，请勿重复支付。';
        } else {
            notice = '等待系统核实到账。请勿自行修改金额或遗漏付款备注。';
        }
        find('notice').textContent = notice;
        find('footer').textContent = data.status === 2 ? '付款已完成。如需帮助，请联系商户并提供订单号。'
            : data.expired || [3, 4, 5].includes(data.status) ? '请勿继续付款。若已付款，请联系商户并提供订单号核对。'
            : '付款后请等待支付结果，切勿重复付款。如需帮助，请联系商户并提供订单号。';
        tick(); schedule();
    }

    /** 写请求前后各推进一次代次，避免较早的轮询响应覆盖已经返回的新执行状态。 */
    async function write(path, extra) {
        if (writing) return;
        const before = {payNo: current?.pay_no ?? null, status: current?.status ?? null};
        writeFailure = null;
        writing = true; generation++;
        for (const button of find('methods').querySelectorAll('button')) button.disabled = true;
        find('prepare').disabled = true; error('');
        try {
            const response = await MpayHttp.request(path, {method: 'POST', data: {...identity, ...extra}});
            generation++; apply(response.data);
        } catch (failure) {
            writeFailure = {...before, message: failure.message || '操作尚未完成，请查询原订单。'};
            generation++; error(writeFailure.message);
            await refresh();
        } finally {
            writing = false;
            for (const button of find('methods').querySelectorAll('button')) button.disabled = false;
            find('prepare').disabled = false;
        }
    }

    async function refresh() {
        if (fetching) { queued = true; return; }
        fetching = true;
        const requestGeneration = generation;
        try {
            const response = await MpayHttp.request('/api/cashier/order?' + query);
            if (requestGeneration === generation) {
                // apply 区分可清除的读取故障和仍未解决的写拒绝，付款材料还可报告自己的渲染失败。
                apply(response.data);
            }
        } catch (failure) {
            // 写动作已推进代次时，旧轮询失败也不能覆盖新执行的展示状态。
            if (requestGeneration === generation) error(failure.message || '暂时无法读取订单，请稍后查询。');
        } finally {
            fetching = false;
            if (queued && !document.hidden) { queued = false; refresh(); } else schedule();
        }
    }

    function schedule() {
        clearTimeout(timer);
        if (document.hidden || !current || (current.status === 2 && ['success','held'].includes(current.notification_status))) return;
        timer = setTimeout(refresh, current.expired || [3, 4, 5].includes(current.status) ? 15000 : 3000);
    }

    /** 倒计时只影响展示；到零后查询服务器，不自行把订单写成失败、超时或成功。 */
    function tick() {
        if (!current) return;
        const elapsed = Math.floor((Date.now() - receivedAt) / 1000);
        const remaining = Math.max(0, current.expires_at - current.server_time - elapsed);
        find('countdown').textContent = Math.floor(remaining / 60) + ':' + String(remaining % 60).padStart(2, '0');
        if (remaining === 0 && current.can_pay) {
            find('payment').hidden = true;
            if (!expiryRefreshRequested) { expiryRefreshRequested = true; refresh(); }
        }
    }

    find('refresh').addEventListener('click', () => { error(''); refresh(); });
    find('prepare').addEventListener('click', () => write('/api/cashier/prepare', {}));
    find('copy').addEventListener('click', async () => {
        if (!current?.remark) return;
        try { await navigator.clipboard.writeText(current.remark); layui.layer.msg('备注已复制'); }
        catch (_) { layui.layer.msg('请长按或选中备注手动复制'); }
    });
    document.addEventListener('visibilitychange', () => { if (document.hidden) clearTimeout(timer); else refresh(); });
    window.addEventListener('pageshow', event => { if (event.persisted) refresh(); });
    setInterval(tick, 1000);
    try { apply(JSON.parse(document.getElementById('cashier-initial').textContent)); }
    catch (failure) { error(failure.message || '页面数据无法读取'); refresh(); }
});
