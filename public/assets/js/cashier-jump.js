/** 网页付款及移动端唤起首次自动前往；同页签原单返回后保留查询，导航本身不确认付款。 */
(function () {
    'use strict';
    MpayCashierPages.register('jump', '', function (host, order, params, context) {
        const url = new URL(params.url);
        // 页面只限制可执行协议，不重复插件的平台规则；客户端协议在移动端首次尝试唤起。
        if (!['http:', 'https:', 'alipay:', 'alipays:', 'weixin:'].includes(url.protocol)) {
            throw new Error('付款地址无效，请联系商户。');
        }
        const webpage = ['http:', 'https:'].includes(url.protocol);
        const autoOpen = webpage || layui.device().mobile;
        const panel = document.createElement('div');
        panel.className = 'cashier-jump';
        const guide = document.createElement('p');
        guide.className = 'cashier-jump-title';
        guide.textContent = webpage ? '请前往支付页面完成付款' : '请打开支付应用完成付款';
        const detail = document.createElement('p');
        detail.className = 'cashier-hint';
        detail.textContent =
            !webpage && !autoOpen
                ? '请在手机或对应支付应用中打开，也可点击按钮手动尝试。'
                : '付款后返回此页，查看支付结果。';
        const link = document.createElement('a');
        link.className = 'layui-btn cashier-jump-action';
        link.href = url.href;
        link.textContent = '前往支付';
        const copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'layui-btn layui-btn-primary cashier-jump-copy';
        copy.textContent = webpage ? '复制付款地址' : '复制唤起地址';
        const field = document.createElement('input');
        field.type = 'text';
        field.className = 'layui-input cashier-payment-link';
        field.readOnly = true;
        field.hidden = true;
        field.value = params.url;
        field.setAttribute('aria-label', '付款地址，可选中手动复制');
        const countdown = document.createElement('p');
        countdown.className = 'cashier-hint cashier-jump-countdown';
        panel.append(guide, detail, link, copy, field, countdown);
        host.append(panel);
        const started = performance.now();
        let expired = false,
            disposed = false;
        const remaining = () =>
            Math.max(
                0,
                order.expires_at -
                    order.server_time -
                    Math.floor((performance.now() - started) / 1000),
            );
        const active = () => !disposed && remaining() > 0;
        const opening = (event) => {
            if (!active()) event.preventDefault();
        };
        const copying = async () => {
            if (!active()) return;
            const copied = await MpayClipboard.writeText(params.url);
            if (!active()) return;
            if (!copied) {
                field.hidden = false;
                field.focus();
                field.select();
            }
            layui.layer.msg(copied ? '付款地址已复制' : '请长按或选中地址手动复制');
        };
        link.addEventListener('click', opening);
        copy.addEventListener('click', copying);
        // 每笔原单在当前页签只自动前往一次，避免付款返回、刷新或重新挂载时循环跳走。
        // 存储不可用或自动唤起受限时保留按钮，不另开定时重试。
        const redirect = autoOpen
            ? setTimeout(() => {
                  if (!active()) return;
                  try {
                      const key = 'mpay.cashier.jump.' + order.pay_no;
                      if (sessionStorage.getItem(key)) return;
                      sessionStorage.setItem(key, '1');
                  } catch {
                      return;
                  }
                  guide.textContent = webpage ? '正在前往支付页面' : '正在尝试打开支付应用';
                  detail.textContent =
                      '如未自动打开，请点击按钮重试，或复制地址到对应支付应用中打开。';
                  location.assign(url.href);
              }, 0)
            : null;
        return {
            tick() {
                const seconds = remaining();
                countdown.textContent =
                    '剩余时间 ' +
                    String(Math.floor(seconds / 60)).padStart(2, '0') +
                    ':' +
                    String(seconds % 60).padStart(2, '0');
                if (!seconds && !expired) {
                    expired = true;
                    host.hidden = true;
                    context.expired();
                }
            },
            destroy() {
                disposed = true;
                clearTimeout(redirect);
                link.removeEventListener('click', opening);
                copy.removeEventListener('click', copying);
                host.replaceChildren();
            },
        };
    });
})();
