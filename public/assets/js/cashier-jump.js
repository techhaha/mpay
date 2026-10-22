/** 网页付款首次自动前往；同页签原单返回后保留查询，导航本身不确认付款。 */
(function () {
    'use strict';
    MpayCashierPages.register('jump', '', function (host, order, params, context) {
        const url = new URL(params.url);
        // 页面只限制可执行协议，不重复插件的平台规则；移动端唤起须由用户点击。
        if (!['http:', 'https:', 'alipay:', 'alipays:', 'weixin:'].includes(url.protocol)) {
            throw new Error('付款地址无效，请联系商户。');
        }
        const panel = document.createElement('div');
        panel.className = 'cashier-jump';
        const guide = document.createElement('p');
        guide.className = 'cashier-jump-title';
        guide.textContent = '请前往支付页面完成付款';
        const detail = document.createElement('p');
        detail.className = 'cashier-hint';
        detail.textContent = '付款后返回此页，查看支付结果。';
        const link = document.createElement('a');
        link.className = 'layui-btn cashier-jump-action';
        link.href = url.href;
        link.textContent = '前往支付';
        const countdown = document.createElement('p');
        countdown.className = 'cashier-hint cashier-jump-countdown';
        panel.append(guide, detail, link, countdown);
        host.append(panel);
        const started = performance.now();
        let expired = false;
        // 每笔原单在当前页签只自动前往一次，避免付款返回、刷新或重新挂载时循环跳走。
        // 存储不可用时保留按钮；客户端协议仍由用户点击唤起。
        const redirect = ['http:', 'https:'].includes(url.protocol) ? setTimeout(() => {
            if (expired) return;
            try {
                const key = 'mpay.cashier.jump.' + order.pay_no;
                if (sessionStorage.getItem(key)) return;
                sessionStorage.setItem(key, '1');
            } catch { return; }
            guide.textContent = '正在前往支付页面';
            detail.textContent = '如未自动跳转，请点击下方按钮。';
            location.assign(url.href);
        }, 0) : null;
        return {
            tick() {
                const seconds = Math.max(0, order.expires_at - order.server_time - Math.floor((performance.now() - started) / 1000));
                countdown.textContent = '剩余时间 ' + String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
                if (!seconds && !expired) { expired = true; host.hidden = true; context.expired(); }
            },
            destroy() { clearTimeout(redirect); host.replaceChildren(); }
        };
    });
})();
