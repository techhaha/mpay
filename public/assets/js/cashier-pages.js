/** 收银台支付展示注册表；模块只展示付款材料，订单查询和完成导航由 cashier.js 统一管理。 */
(function (global) {
    'use strict';
    const pages = new Map();
    global.MpayCashierPages = {
        register(page, name, mount) {
            const key = page + ':' + name;
            if (pages.has(key) || typeof mount !== 'function') throw new Error('支付页面注册无效');
            pages.set(key, mount);
        },
        mount(host, order, context) {
            const presentation = order.presentation;
            const key = presentation?.pay_page + ':' + (presentation?.pay_page === 'page' ? presentation.pay_params?._page : '');
            const mount = pages.get(key);
            if (!mount) throw new Error('当前版本不支持该付款页面，请联系商户。');
            return mount(host, order, presentation.pay_params, context);
        }
    };
})(window);
