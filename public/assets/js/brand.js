/** 共用品牌素材：图标只从固定本地映射选取，不把接口字段拼成资源路径。 */
(function () {
    'use strict';
    let payments = {wxpay:{name:'微信支付',icon:'/assets/brand/wechat-pay-icon.svg',logo:'/assets/brand/wechat-pay-logo.svg'},
        alipay:{name:'支付宝',icon:'/assets/brand/alipay-icon.svg',logo:'/assets/brand/alipay-logo.svg'},
        unionpay:{name:'云闪付',icon:'/assets/brand/cloudpay-icon.svg',logo:'/assets/brand/cloudpay-logo.svg'}};
    window.MpayBrand = {
        /** 目录由同源接口提供；只接受本地品牌/上传图片路径，不允许拼接外站资源。 */
        setPayments(items) {
            const next = Object.create(null);
            const path = value => typeof value === 'string' && /^\/(?:assets\/brand|storage)\/[a-zA-Z0-9_./-]+$/.test(value) && !value.includes('..') ? value : null;
            items.forEach(item => { if (/^[a-z][a-z0-9_]{0,31}$/.test(item.code)) next[item.code] = {name:item.name,icon:path(item.icon),logo:path(item.logo)}; });
            payments = next;
        },
        paymentAsset(code) { return Object.prototype.hasOwnProperty.call(payments, code) ? payments[code] : null; },
        /** 列表/方式选择用图形小标；收银台品牌区用带文字的完整标识，禁止把横向字标缩进方形图标格。 */
        paymentImage(code, full = false) {
            const item = Object.prototype.hasOwnProperty.call(payments, code) ? payments[code] : null; if (!item) return null;
            if (!(full ? item.logo : item.icon)) return null;
            const image = document.createElement('img'); image.src = full ? item.logo : item.icon;
            image.alt = item.name; image.className = full ? 'payment-logo' : 'payment-icon';
            if (!full) { image.width = 24; image.height = 24; }
            return image;
        }
    };
})();
