/** 平台付款链接展示；保持本地状态查询，返回页面不会再次自动跳走或据此确认付款。 */
(function () {
    'use strict';
    MpayCashierPages.register('jump', '', function (host, order, params, context) {
        const url = new URL(params.url);
        // 页面只限制可执行协议，不重复插件的平台规则；移动端唤起须由用户点击。
        if (!['http:', 'https:', 'alipay:', 'alipays:', 'weixin:'].includes(url.protocol)) {
            throw new Error('付款地址无效，请联系商户。');
        }
        const guide = document.createElement('p');
        guide.className = 'cashier-hint';
        guide.textContent = '请前往支付页面完成付款，付款后返回此页查看结果。';
        const link = document.createElement('a');
        link.className = 'layui-btn';
        link.href = url.href;
        link.textContent = '前往支付';
        const countdown = document.createElement('p');
        countdown.className = 'cashier-hint';
        host.append(guide, link, countdown);
        const started = performance.now();
        let expired = false;
        return {
            tick() {
                const seconds = Math.max(0, order.expires_at - order.server_time - Math.floor((performance.now() - started) / 1000));
                countdown.textContent = '剩余时间 ' + String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
                if (!seconds && !expired) { expired = true; host.hidden = true; context.expired(); }
            },
            destroy() { host.replaceChildren(); }
        };
    });
})();
