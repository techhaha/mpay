/** 支付方式图片由同源配置目录提供；未配置时统一使用通用图标。 */
(function () {
    'use strict';
    const fallbackImage = '/assets/brand/payment-default.png';
    let payments = Object.create(null);
    window.MpayBrand = {
        fallbackImage,
        /** 目录由同源接口提供；只接受本地品牌/上传图片路径，不允许拼接外站资源。 */
        setPayments(items) {
            const next = Object.create(null);
            const path = value => typeof value === 'string' && /^\/(?:assets\/brand|storage)\/[a-zA-Z0-9_./-]+$/.test(value) && !value.includes('..') ? value : null;
            items.forEach(item => { if (/^[a-z][a-z0-9_]{0,31}$/.test(item.code)) next[item.code] = {name:item.name,icon:path(item.icon)||fallbackImage,logo:path(item.logo)||fallbackImage}; });
            payments = next;
        },
        paymentAsset(code) { return Object.prototype.hasOwnProperty.call(payments, code) ? payments[code] : null; },
        /** 列表/方式选择用图形小标；收银台品牌区用带文字的完整标识，禁止把横向字标缩进方形图标格。 */
        paymentImage(code, full = false) {
            const item = Object.prototype.hasOwnProperty.call(payments, code) ? payments[code] : null;
            const image = document.createElement('img'); image.src = (full ? item?.logo : item?.icon) || fallbackImage;
            image.alt = item?.name || code; image.className = full ? 'payment-logo' : 'payment-icon';
            if (!full) { image.width = 24; image.height = 24; }
            return image;
        }
    };
})();
