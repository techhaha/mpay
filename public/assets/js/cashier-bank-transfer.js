/** 银行卡转账只展示插件公开资料；应付金额、倒计时与成功判定继续使用公共收银台流程。 */
(function () {
    'use strict';
    MpayCashierPages.register('page', 'bankTransfer', function (host, order, params, context) {
        if (!Number.isInteger(order.expires_at) || !Number.isInteger(order.server_time)
            || typeof order.amount !== 'string' || !order.amount
            || typeof params.bank_name !== 'string' || !params.bank_name
            || typeof params.account_name !== 'string' || !params.account_name
            || typeof params.card_number !== 'string' || !/^[0-9]{12,19}$/.test(params.card_number)) {
            throw new Error('银行卡收款资料无效，请联系商户。');
        }
        host.innerHTML = '<div class="cashier-payment-brand"></div>'
            + '<div class="cashier-bank-intro"><p class="cashier-scan-title">银行卡转账</p>'
            + '<p class="cashier-hint">请向以下银行卡转入上方应付金额，到账后会自动更新订单。</p>'
            + '<button type="button" class="layui-btn layui-btn-sm layui-btn-primary" data-bank-copy="amount">复制应付金额</button></div>'
            + '<dl class="cashier-bank-details"><div><dt>收款银行</dt><dd class="cashier-bank-name"></dd></div>'
            + '<div><dt>收款户名</dt><dd class="cashier-bank-account"></dd>'
            + '<button type="button" class="layui-btn layui-btn-sm layui-btn-primary" data-bank-copy="account">复制户名</button></div>'
            + '<div><dt>银行卡号</dt><dd class="cashier-bank-number"></dd>'
            + '<button type="button" class="layui-btn layui-btn-sm layui-btn-primary" data-bank-copy="card">复制卡号</button></div></dl>'
            + '<div class="cashier-receipt-guide"><div class="cashier-payment-tip"><span>转账提示</span>'
            + '<p>请严格按上方应付金额转账，无需填写付款备注。</p></div>'
            + '<div class="cashier-countdown"><span>剩余时间</span><strong></strong></div></div>';
        const brand = MpayBrand.paymentImage(order.pay_type, true);
        if (brand) host.querySelector('.cashier-payment-brand').append(brand);
        host.querySelector('.cashier-bank-name').textContent = params.bank_name;
        host.querySelector('.cashier-bank-account').textContent = params.account_name;
        host.querySelector('.cashier-bank-number').textContent = params.card_number.replace(/([0-9]{4})(?=[0-9])/g, '$1 ');
        let disposed = false, expired = false;
        const copy = async event => {
            const button = event.target.closest('[data-bank-copy]');
            if (!button || !host.contains(button)) return;
            const values = {amount:order.amount, card:params.card_number, account:params.account_name};
            const text = values[button.dataset.bankCopy];
            const copied = await MpayClipboard.writeText(text);
            if (!disposed) layui.layer.msg(copied ? '已复制' : '请长按或选中文字手动复制');
        };
        host.addEventListener('click', copy);
        const started = performance.now();
        return {
            tick() {
                const seconds = Math.max(0, order.expires_at - order.server_time - Math.floor((performance.now() - started) / 1000));
                host.querySelector('.cashier-countdown strong').textContent = String(Math.floor(seconds / 60)).padStart(2, '0')
                    + ':' + String(seconds % 60).padStart(2, '0');
                if (seconds === 0 && !expired) { expired = true; host.hidden = true; context.expired(); }
            },
            destroy() { disposed = true; host.removeEventListener('click', copy); host.replaceChildren(); }
        };
    });
})();
