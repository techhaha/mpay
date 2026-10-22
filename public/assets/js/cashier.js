/**
 * 收银台公共流程：详情只在首屏或状态版本变化时读取，付款和商户确认分阶段轮询。
 * 仅服务器完成判定可以导航；刷新/恢复不重下单，旧响应不能覆盖新的写操作或已停止的确认轮次。
 */
layui.use('layer', function () {
    'use strict';
    const root = document.getElementById('cashier'); if (!root) return;
    const identity = {biz_no:root.dataset.bizNo, token:root.dataset.token};
    const query = new URLSearchParams(identity).toString(), find = name => document.getElementById('cashier-' + name);
    let current = null, phase = 'payment', page = null, timer = null, deadline = null;
    let fetching = false, writing = false, pending = null, generation = 0, failures = 0, initialPrepareRequested = false;
    let writeFailure = null, leaving = false;
    let readWaitUntil = 0, writeWaitUntil = 0;
    const error = message => { find('error').textContent = message || ''; find('error').hidden = !message; };
    const notice = message => { find('notice').textContent = message; find('notice').hidden = !message; };
    const stoppedViews = {
        expired:{label:'付款已超时',icon:'time',title:'付款时间已结束',message:'请勿继续向此收款码付款。',
            detail:'如果已经付款，请检查支付结果，或联系商户核对。'},
        closed:{label:'已关闭',icon:'close',title:'订单已关闭',message:'此订单已停止收款，请勿继续付款。',
            detail:'如果已经付款，请检查支付结果，或联系商户核对。'},
        failed:{label:'支付未完成',icon:'about',title:'支付未完成',message:'本次支付未能完成。',
            detail:'如果已经扣款，请检查支付结果，或联系商户核对，切勿重复付款。'}
    };
    /** 页面状态与持久交易状态分开：超时只表示停止付款，不推断未到账，也不停止原单查询。 */
    function showResult(view, content) {
        root.dataset.view = view; notice('');
        find('status').textContent = (current?.test_mode ? '测试：' : '') + content.label;
        find('result-icon').className = 'layui-icon layui-icon-' + content.icon;
        for (const key of ['title','message','detail']) {
            // 稳定的确认轮询不重复更新播报区域。
            if (find('result-' + key).textContent !== content[key]) find('result-' + key).textContent = content[key];
        }
        find('result').hidden = false;
    }
    function showConfirmation() {
        showResult('paid', {label:'已支付',icon:'ok-circle',title:'已支付，等待商户确认',
            message:'付款已完成，请勿重复付款。',detail:'商户确认后将自动显示支付结果或返回商户页面。'});
    }
    function disposePage() { page?.destroy(); page = null; find('payment').hidden = true; }
    function timeout() {
        phase = 'attention'; clearTimeout(timer); deadline = null; pending = null; generation++;
        showResult('paid', {label:'已支付',icon:'ok-circle',title:'已支付，商户尚未确认',
            message:'付款已完成，请勿重复付款。',detail:'暂未收到商户确认，请重新检查通知或联系商户。'});
        find('refresh').textContent = '重新检查通知';
    }
    function enterConfirmation() {
        if (phase !== 'notification') { phase = 'notification'; deadline = performance.now() + 60000; generation++; }
        disposePage(); find('selection').hidden = true; find('prepare').hidden = true;
        find('amount-label').textContent = '已支付金额';
        find('refresh').textContent = '检查商户确认';
        showConfirmation();
        find('footer').textContent = '如需帮助，请联系商户并提供订单号。';
    }
    function apply(data) {
        if (!data || data.biz_no !== identity.biz_no || typeof data.status !== 'number' || typeof data.revision !== 'string') throw new Error('订单响应格式异常');
        if (writeFailure && (data.pay_no !== writeFailure.payNo || data.status !== writeFailure.status || data.can_pay || data.expired)) writeFailure = null;
        current = data; error(writeFailure?.message || ''); disposePage();
        root.dataset.status = String(data.status);
        root.dataset.view = 'payment'; find('result').hidden = true;
        find('test').hidden = !data.test_mode;
        find('subject').textContent = data.subject; find('order-no').textContent = data.pay_no || data.biz_no;
        find('amount-block').hidden = data.amount == null;
        find('amount').textContent = data.amount == null ? '—' : data.amount;
        find('amount-label').textContent = data.status === 2 ? '已支付金额' : '应付金额';
        if (data.status === 2) { enterConfirmation(); pending = 'completion'; return; }
        phase = 'payment'; deadline = null;
        find('status').textContent = (data.test_mode ? '测试：' : '') + data.status_label;
        find('refresh').textContent = '刷新支付结果';
        if (Array.isArray(data.payment_brands)) MpayBrand.setPayments(data.payment_brands);
        find('selection').hidden = !data.can_select; find('methods').replaceChildren();
        for (const method of data.payment_methods) {
            const button = document.createElement('button'); button.type = 'button'; button.className = 'layui-btn layui-btn-primary';
            const icon = MpayBrand.paymentImage(method.code); if (icon) { icon.alt = ''; button.append(icon); }
            button.append(document.createTextNode(method.name)); button.disabled = writing;
            button.addEventListener('click', () => write('/api/cashier/select', {type:method.code})); find('methods').append(button);
        }
        find('prepare').hidden = !data.can_prepare; find('prepare').disabled = writing;
        if (data.can_pay) {
            try {
                page = MpayCashierPages.mount(find('payment'), data, {error, expired:() => request('status')});
                find('payment').hidden = false; page.tick?.();
            } catch (failure) { disposePage(); error(failure.message); }
        }
        // 明确关闭/失败优先于截止时间；已支付已在上方进入确认，不能被 expired 覆盖。
        const stopped = data.status === 3 ? 'failed' : data.status === 4 ? 'closed' : data.status === 5 || data.expired ? 'expired' : null;
        if (stopped) {
            disposePage(); find('selection').hidden = true; find('prepare').hidden = true; find('amount-block').hidden = true;
            showResult(stopped, stoppedViews[stopped]); find('refresh').textContent = '检查支付结果';
        }
        else if (data.can_select) notice(data.payment_methods.length ? '请选择支付方式，选定后此订单的支付方式不可更改。' : '暂时没有可用的支付方式，请联系商户。');
        else if (data.processing) notice('正在准备付款信息，请稍候。若已付款，请勿重复支付。');
        // 付款要求由展示模块呈现；公共提示区只保留准备、异常和完成状态，避免重复指引。
        else notice('');
        find('footer').textContent = data.can_pay
            ? '付款后请等待系统确认，切勿重复付款。\n如需帮助，请联系商户并提供订单号。'
            : '如需帮助，请联系商户并提供订单号。';
        if (data.needs_initial_prepare && !initialPrepareRequested) {
            initialPrepareRequested = true; setTimeout(() => write('/api/cashier/prepare', {}), 0);
        }
    }
    function complete(data) {
        if (!data || data.biz_no !== identity.biz_no || typeof data.status !== 'number') throw new Error('商户确认响应异常');
        if (data.action === 'payment') { phase = 'payment'; deadline = null; pending = 'order'; return; }
        if (['return','success'].includes(data.action)) {
            // 完成动作与站内地址由服务端给出；目标入口仍核对订单、凭证及商户确认。
            phase = 'done'; clearTimeout(timer); disposePage();
            showResult('paid', {label:'已支付',icon:'ok-circle',title:'支付已完成',message:'付款已完成，请勿重复付款。',
                detail:data.action === 'return' ? '商户已确认，正在返回商户页面。' : '正在查看支付结果。'});
            location.replace(data.next_url); return;
        }
        if (data.action === 'attention') {
            phase = 'attention'; deadline = null;
            showResult('paid', {label:'已支付',icon:'ok-circle',
                title:data.notification_status === 'held' ? '已支付，商户确认暂缓' : '已支付，商户尚未确认',
                message:'付款已完成，请勿重复付款。',
                detail:data.notification_status === 'held' ? '商户通知暂缓处理，请联系商户。' : '请重新检查通知，或联系商户核对。'});
            find('refresh').textContent = '重新检查通知'; return;
        }
        if (data.action !== 'wait') throw new Error('商户确认状态异常');
        // 恢复可见后的单次补查不重启等待；仍未确认时保留手动检查提示。
        if (phase === 'attention') { timeout(); return; }
        showConfirmation();
    }
    function schedule() {
        clearTimeout(timer);
        if (leaving || document.hidden || writing || fetching || phase === 'done') return;
        // 停止自动等待后，只放行恢复页面时明确排入的单次完成查询。
        if (phase === 'attention' && pending !== 'completion') return;
        const wait = readWaitUntil - performance.now();
        if (wait > 0) {
            timer = setTimeout(() => request(pending || (phase === 'notification' ? 'completion' : current ? 'status' : 'order')), wait);
            return;
        }
        if (pending) { const next = pending; pending = null; timer = setTimeout(() => request(next), 0); return; }
        if (phase === 'notification' && deadline !== null && performance.now() >= deadline) { timeout(); return; }
        const base = phase === 'notification' ? 2000 : current?.expired || [3,4,5].includes(current?.status) ? 15000 : 3000;
        const delay = Math.min(base * (2 ** Math.min(failures, 3)), 15000);
        timer = setTimeout(() => request(phase === 'notification' ? 'completion' : current ? 'status' : 'order'),
            phase === 'notification' ? Math.min(delay, Math.max(0, deadline - performance.now())) : delay);
    }
    async function request(kind) {
        if (leaving || phase === 'done') return;
        if (performance.now() < readWaitUntil) { pending = kind; schedule(); return; }
        if (fetching || writing) { if (pending !== 'order') pending = kind; return; }
        if (pending === kind) pending = null;
        if (phase === 'notification' && deadline !== null && performance.now() >= deadline) { timeout(); return; }
        fetching = true; clearTimeout(timer); const revision = generation;
        try {
            const response = await MpayHttp.request('/api/cashier/' + kind + '?' + query);
            if (revision !== generation || leaving) return;
            if (phase === 'notification' && deadline !== null && performance.now() >= deadline) { timeout(); return; }
            failures = 0;
            if (!writeFailure) error('');
            if (kind === 'order') apply(response.data);
            else if (kind === 'completion') { error(''); complete(response.data); }
            else {
                const data = response.data;
                if (data.biz_no !== identity.biz_no || typeof data.revision !== 'string') throw new Error('订单状态响应异常');
                if (data.revision !== current?.revision) {
                    if (data.status === 2) enterConfirmation();
                    pending = 'order';
                }
            }
        } catch (failure) {
            if (revision === generation && !leaving) {
                if (failure.retryAfter > 0) readWaitUntil = performance.now() + failure.retryAfter * 1000;
                failures++; error(failure.message || '暂时无法查询，请稍后重试。');
            }
        } finally { fetching = false; schedule(); }
    }
    async function write(path, extra) {
        if (writing || leaving || phase !== 'payment') return;
        if (performance.now() < writeWaitUntil) { error(`操作过于频繁，请 ${Math.ceil((writeWaitUntil - performance.now()) / 1000)} 秒后重试`); return; }
        const before = {payNo:current?.pay_no ?? null, status:current?.status ?? null};
        writing = true; generation++; pending = null; writeFailure = null; clearTimeout(timer); error('');
        find('methods').querySelectorAll('button').forEach(button => button.disabled = true); find('prepare').disabled = true;
        try {
            const response = await MpayHttp.request(path, {method:'POST', data:{...identity, ...extra}});
            generation++; if (!leaving) apply(response.data);
        } catch (failure) {
            if (failure.retryAfter > 0) writeWaitUntil = performance.now() + failure.retryAfter * 1000;
            generation++; writeFailure = {...before, message:failure.message || '操作尚未完成，请查询原订单。'};
            error(writeFailure.message); pending = 'order';
        } finally {
            writing = false; find('methods').querySelectorAll('button').forEach(button => button.disabled = false);
            find('prepare').disabled = false; schedule();
        }
    }
    find('refresh').addEventListener('click', () => {
        if (phase === 'done' || writing) return;
        error(''); failures = 0;
        if (phase === 'attention') { generation++; enterConfirmation(); pending = null; }
        request(phase === 'notification' ? 'completion' : 'order');
    });
    find('prepare').addEventListener('click', () => write('/api/cashier/prepare', {}));
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) clearTimeout(timer);
        else if (!leaving && phase !== 'done') {
            page?.tick?.();
            if (phase === 'notification' && deadline !== null && performance.now() >= deadline) timeout();
            // 隐藏期间耗尽等待时限也补查一次；复用队列和限流，不延长本轮 60 秒。
            if (phase === 'attention') pending = 'completion';
            request(phase === 'attention' ? 'completion' : phase === 'notification' ? (pending || 'completion') : 'status');
        }
    });
    window.addEventListener('pagehide', () => { leaving = true; generation++; clearTimeout(timer); disposePage(); });
    window.addEventListener('pageshow', event => { if (event.persisted) { leaving = false; phase = 'payment'; deadline = null; pending = null; generation++; request('order'); } });
    setInterval(() => { if (!leaving) { if (!document.hidden) page?.tick?.(); if (phase === 'notification' && deadline !== null && performance.now() >= deadline) timeout(); } }, 1000);
    try { apply(JSON.parse(document.getElementById('cashier-initial').textContent)); }
    catch (failure) { error(failure.message || '页面数据无法读取'); pending = 'order'; }
    schedule();
});
