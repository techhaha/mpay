/** 收款码展示组件；消费插件公开快照及公共订单时间，不读取私有配置，不生成平台付款地址。 */
(function () {
    'use strict';
    const mount = function (host, order, params, context) {
        const instruction = params.instruction ?? 'scan';
        if (
            !Number.isInteger(order.expires_at) ||
            !Number.isInteger(order.server_time) ||
            !['scan', 'manual_remark'].includes(instruction) ||
            (instruction === 'manual_remark' &&
                (typeof params.remark !== 'string' ||
                    !params.remark ||
                    new TextEncoder().encode(params.remark).length > 256)) ||
            (instruction === 'scan' && params.remark != null)
        ) {
            throw new Error('付款指引数据无效，请联系商户检查插件配置。');
        }
        // 两种识别模式共用付款区；只给二维码加框，付款要求与倒计时用浅底分区。
        host.innerHTML =
            '<div class="cashier-payment-brand"></div><div class="cashier-qr"></div>' +
            '<div class="cashier-scan-guide"><p class="cashier-scan-title"></p><p class="cashier-hint"></p></div>' +
            '<div class="cashier-receipt-guide"><div class="cashier-payment-tip"><span>付款提示</span><p>请按上方金额付款</p></div>' +
            '<div class="cashier-remark" hidden><span>付款备注（必填）</span><div class="cashier-remark-value"><strong></strong>' +
            '<button type="button" class="layui-btn layui-btn-sm layui-btn-primary">复制备注</button></div><p>付款时请填写此备注。</p></div>' +
            '<div class="cashier-countdown"><span>剩余时间</span><strong></strong></div></div>';
        const brand = MpayBrand.paymentImage(order.pay_type, true);
        if (brand) host.querySelector('.cashier-payment-brand').append(brand);
        const name = MpayBrand.paymentAsset(order.pay_type)?.name || '对应付款应用';
        const guide = host.querySelector('.cashier-scan-guide');
        const title = guide.querySelector('.cashier-scan-title');
        const hint = guide.querySelector('.cashier-hint');
        const mobile = !!layui.device().mobile;
        const agent = navigator.userAgent;
        const matching =
            (order.pay_type === 'wxpay' && /MicroMessenger/i.test(agent)) ||
            (order.pay_type === 'alipay' && /AlipayClient/i.test(agent));
        if (!mobile) {
            title.textContent = '请使用' + name + '扫码付款';
            hint.textContent = '请使用手机上的' + name + '扫描二维码。';
        } else if (matching) {
            title.textContent = '请长按识别二维码付款';
            hint.textContent = '如无法识别，可保存图片后在' + name + '中扫一扫。';
        } else {
            title.textContent = '请在' + name + '中打开后付款';
            hint.textContent = '请复制当前收银台地址，到' + name + '中打开后付款。';
        }
        const image = document.createElement('img');
        image.alt = '收款二维码';
        if (params.qrcode_image_url) {
            // 上传的码牌可能是长方形，样式保留原图比例，不裁切或重新装饰其内部内容。
            host.querySelector('.cashier-qr').classList.add('cashier-qr-upload');
            if (typeof params.qrcode_image_url !== 'string') throw new Error('二维码图片地址无效');
            image.src = params.qrcode_image_url;
        } else {
            // 编码规格属于页面实现，不由业务服务随每笔快照重复注入。
            const encoding = { mode: 'Byte', error_correction: 'M', max_text_bytes: 2331 };
            if (
                typeof params.qrcode_content !== 'string' ||
                !params.qrcode_content ||
                new TextEncoder().encode(params.qrcode_content).length > encoding.max_text_bytes
            ) {
                throw new Error('收款二维码无法生成，请联系商户检查配置。');
            }
            const matrix = qrcode(0, encoding.error_correction);
            matrix.addData(params.qrcode_content, encoding.mode);
            matrix.make();
            const count = matrix.getModuleCount(),
                cell = Math.max(4, Math.floor(300 / (count + 8)));
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = (count + 8) * cell;
            const painter = canvas.getContext('2d');
            if (!painter) throw new Error('当前浏览器无法显示二维码');
            painter.fillStyle = '#fff';
            painter.fillRect(0, 0, canvas.width, canvas.height);
            painter.fillStyle = '#000';
            for (let row = 0; row < count; row++)
                for (let col = 0; col < count; col++) {
                    if (matrix.isDark(row, col))
                        painter.fillRect((col + 4) * cell, (row + 4) * cell, cell, cell);
                }
            // 最终使用 img，保留移动端长按/保存图片的浏览器能力；不把付款内容作为 HTML。
            image.src = canvas.toDataURL('image/png');
        }
        let disposed = false,
            expired = false;
        const failed = () => {
            if (!disposed) context.error('二维码图片暂时无法读取，请重新读取订单或联系商户。');
        };
        image.addEventListener('error', failed);
        host.querySelector('.cashier-qr').append(image);
        const remark = host.querySelector('.cashier-remark');
        if (instruction === 'manual_remark') {
            host.querySelector('.cashier-payment-tip').hidden = true;
            remark.hidden = false;
            remark.querySelector('strong').textContent = params.remark;
        }
        const started = performance.now();
        const remaining = () =>
            Math.max(
                0,
                order.expires_at -
                    order.server_time -
                    Math.floor((performance.now() - started) / 1000),
            );
        const active = () => !disposed && remaining() > 0;
        const copy = async () => {
            if (!active()) return;
            const copied = await MpayClipboard.writeText(params.remark);
            if (active()) layui.layer.msg(copied ? '备注已复制' : '请长按或选中备注手动复制');
        };
        remark.querySelector('button').addEventListener('click', copy);
        // 复制当前订单的本站页面，保留原访问凭证；二维码材料不作为导航或复制目标。
        const address = location.href;
        let addressButton = null,
            addressField = null;
        if (mobile && !matching) {
            const actions = document.createElement('div');
            actions.className = 'cashier-dynamic-actions';
            addressButton = document.createElement('button');
            addressButton.type = 'button';
            addressButton.className = 'layui-btn layui-btn-primary';
            addressButton.textContent = '复制地址';
            addressField = document.createElement('input');
            addressField.type = 'text';
            addressField.className = 'layui-input cashier-payment-link';
            addressField.readOnly = true;
            addressField.hidden = true;
            addressField.value = address;
            addressField.setAttribute('aria-label', '收银台地址，可选中手动复制');
            actions.append(addressButton);
            guide.append(actions, addressField);
        }
        const copyAddress = async () => {
            if (!active()) return;
            const copied = await MpayClipboard.writeText(address);
            if (!active()) return;
            if (!copied) {
                addressField.hidden = false;
                addressField.focus();
                addressField.select();
            }
            layui.layer.msg(
                copied
                    ? '收银台地址已复制，请在' + name + '中打开'
                    : '请长按或选中收银台地址手动复制',
            );
        };
        addressButton?.addEventListener('click', copyAddress);
        return {
            tick() {
                const seconds = remaining();
                host.querySelector('.cashier-countdown strong').textContent =
                    String(Math.floor(seconds / 60)).padStart(2, '0') +
                    ':' +
                    String(seconds % 60).padStart(2, '0');
                if (seconds === 0 && !expired) {
                    expired = true;
                    host.hidden = true;
                    context.expired();
                }
            },
            destroy() {
                disposed = true;
                image.removeEventListener('error', failed);
                remark.querySelector('button').removeEventListener('click', copy);
                addressButton?.removeEventListener('click', copyAddress);
                host.replaceChildren();
            },
        };
    };
    MpayCashierPages.register('page', 'receiptQrcode', mount);
    MpayCashierPages.register('page', 'alipayTransfer', (host, order, params, context) => {
        const page = mount(host, order, params, context);
        host.querySelector('.cashier-payment-tip p').textContent =
            '金额和备注已自动填写，请核对后付款';
        return page;
    });

    /** 付款链接只用于生成二维码；展示及复制与其他二维码页面保持一致。 */
    MpayCashierPages.register('page', 'dynamicReceipt', (host, order, params, context) => {
        if (typeof params.payment_url !== 'string' || !params.payment_url)
            throw new Error('付款链接无效，请联系商户。');
        const url = new URL(params.payment_url);
        if (!['http:', 'https:'].includes(url.protocol))
            throw new Error('付款链接无效，请联系商户。');
        const page = mount(
            host,
            order,
            { qrcode_content: params.payment_url, instruction: 'scan' },
            context,
        );
        host.querySelector('.cashier-payment-tip p').textContent = '金额已自动填写，请核对后付款';
        return page;
    });
})();
