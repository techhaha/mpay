/** 收款码展示组件；消费插件公开快照及公共订单时间，不读取私有配置，不生成平台付款地址。 */
(function () {
    'use strict';
    const mount = function (host, order, params, context, autoTransfer = false) {
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
            '<div class="cashier-scan-guide"><p class="cashier-scan-title"></p><p class="cashier-hint">手机端可长按或保存图片后识别。</p></div>' +
            '<div class="cashier-receipt-guide"><div class="cashier-payment-tip"><span>付款提示</span><p>请按上方金额付款</p></div>' +
            '<div class="cashier-remark" hidden><span>付款备注（必填）</span><div class="cashier-remark-value"><strong></strong>' +
            '<button type="button" class="layui-btn layui-btn-sm layui-btn-primary">复制备注</button></div><p>付款时请填写此备注。</p></div>' +
            '<div class="cashier-countdown"><span>剩余时间</span><strong></strong></div></div>';
        const brand = MpayBrand.paymentImage(order.pay_type, true);
        if (brand) host.querySelector('.cashier-payment-brand').append(brand);
        const name = MpayBrand.paymentAsset(order.pay_type)?.name || '对应付款应用';
        host.querySelector('.cashier-scan-title').textContent = '请使用' + name + '扫码付款';
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
        if (autoTransfer) {
            host.querySelector('.cashier-payment-tip p').textContent =
                '金额和备注已自动填写，请核对后付款';
            host.querySelector('.cashier-scan-guide .cashier-hint').textContent =
                '手机端也可直接打开支付宝付款页面。';
            if (typeof params.transfer_url !== 'string') throw new Error('支付宝付款地址无效');
            const url = new URL(params.transfer_url);
            // 链接由插件生成；展示端只拒绝可执行脚本等非网页协议，不复制平台域名和路径规则。
            if (!['https:', 'http:'].includes(url.protocol)) {
                throw new Error('支付宝付款地址无效');
            }
            const link = document.createElement('a');
            link.className = 'layui-btn';
            link.href = url.href;
            link.textContent = '打开支付宝付款';
            host.querySelector('.cashier-scan-guide').append(link);
        }
        const copy = async () => {
            const copied = await MpayClipboard.writeText(params.remark);
            if (!disposed) layui.layer.msg(copied ? '备注已复制' : '请长按或选中备注手动复制');
        };
        remark.querySelector('button').addEventListener('click', copy);
        const started = performance.now();
        const remaining = () =>
            Math.max(
                0,
                order.expires_at -
                    order.server_time -
                    Math.floor((performance.now() - started) / 1000),
            );
        return {
            active() {
                return !disposed && remaining() > 0;
            },
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
                host.replaceChildren();
            },
        };
    };
    MpayCashierPages.register('page', 'receiptQrcode', (host, order, params, context) => {
        const page = mount(host, order, params, context);
        // 二维码文本只用于生成和复制；不把 HTTP(S) 或客户端协议内容自行改成跳转。
        if (typeof params.qrcode_content !== 'string' || !params.qrcode_content) return page;
        const actions = document.createElement('div');
        actions.className = 'cashier-dynamic-actions';
        const copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'layui-btn layui-btn-primary';
        copy.textContent = '复制二维码内容';
        const field = document.createElement('input');
        field.type = 'text';
        field.className = 'layui-input cashier-payment-link';
        field.readOnly = true;
        field.hidden = true;
        field.value = params.qrcode_content;
        field.setAttribute('aria-label', '二维码内容，可选中手动复制');
        actions.append(copy);
        host.querySelector('.cashier-scan-guide').append(actions, field);
        const copying = async () => {
            if (!page.active()) {
                page.tick();
                return;
            }
            const copied = await MpayClipboard.writeText(params.qrcode_content);
            if (!page.active()) return;
            if (!copied) {
                field.hidden = false;
                field.focus();
                field.select();
            }
            layui.layer.msg(copied ? '二维码内容已复制' : '请长按或选中内容手动复制');
        };
        copy.addEventListener('click', copying);
        return {
            tick: page.tick,
            destroy() {
                copy.removeEventListener('click', copying);
                page.destroy();
            },
        };
    });
    MpayCashierPages.register('page', 'alipayTransfer', (host, order, params, context) =>
        mount(host, order, params, context, true),
    );

    /** 动态码只消费一个公开付款链接；环境识别控制展示，不改变订单方式或确认付款。 */
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
        const guide = host.querySelector('.cashier-scan-guide');
        guide.classList.add('cashier-dynamic-guide');
        const title = guide.querySelector('.cashier-scan-title'),
            hint = guide.querySelector('.cashier-hint');
        const name = MpayBrand.paymentAsset(order.pay_type)?.name || '对应付款应用';
        host.querySelector('.cashier-payment-tip p').textContent = '金额已自动填写，请核对后付款';
        const agent = navigator.userAgent;
        const environment = /AlipayClient/i.test(agent)
            ? 'alipay'
            : /MicroMessenger/i.test(agent)
              ? 'wechat'
              : /Mobile|Android|iPhone|iPad/i.test(agent)
                ? 'mobile'
                : 'pc';
        const inside = environment === 'wechat' || environment === 'alipay';
        const matching =
            (environment === 'wechat' && order.pay_type === 'wxpay') ||
            (environment === 'alipay' && order.pay_type === 'alipay');
        hint.textContent =
            environment === 'pc'
                ? '金额已填写，无需输入金额或备注。'
                : '可长按识别二维码，或复制付款链接到' + name + '打开。';
        if (inside && !matching) title.textContent = '请使用' + name + '完成付款';
        if (matching) {
            title.textContent = '请在' + name + '中完成付款';
            hint.textContent = '如未自动打开，请点击下方按钮；付款后可返回查看结果。';
        }
        const actions = document.createElement('div');
        actions.className = 'cashier-dynamic-actions';
        const open = document.createElement('a');
        open.className = 'layui-btn';
        open.href = params.payment_url;
        open.textContent = matching ? '前往' + name + '付款' : '打开付款页面';
        open.hidden = !matching && environment !== 'mobile';
        const copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'layui-btn layui-btn-primary';
        copy.textContent = '复制付款链接';
        const field = document.createElement('input');
        field.type = 'text';
        field.className = 'layui-input cashier-payment-link';
        field.readOnly = true;
        field.hidden = true;
        field.value = params.payment_url;
        field.setAttribute('aria-label', '付款链接，可选中手动复制');
        actions.append(open, copy);
        guide.append(actions, field);
        let disposed = false;
        const opening = (event) => {
            if (!page.active()) {
                event.preventDefault();
                page.tick();
            }
        };
        const copying = async () => {
            if (!page.active()) {
                page.tick();
                return;
            }
            const copied = await MpayClipboard.writeText(params.payment_url);
            if (disposed || !page.active()) return;
            if (!copied) {
                field.hidden = false;
                field.focus();
                field.select();
            }
            layui.layer.msg(
                copied ? '付款链接已复制，请在对应支付应用中打开' : '请长按或选中链接手动复制',
            );
        };
        open.addEventListener('click', opening);
        copy.addEventListener('click', copying);
        // 与网页跳转组件共用同页签原单标记；返回、刷新或重新挂载不再自动跳走。
        const redirect = matching
            ? setTimeout(() => {
                  if (!page.active()) return;
                  try {
                      const key = 'mpay.cashier.jump.' + order.pay_no;
                      if (sessionStorage.getItem(key)) return;
                      sessionStorage.setItem(key, '1');
                  } catch {
                      return;
                  }
                  title.textContent = '正在前往' + name + '付款页面';
                  location.assign(params.payment_url);
              }, 0)
            : null;
        return {
            tick() {
                page.tick();
            },
            destroy() {
                disposed = true;
                clearTimeout(redirect);
                open.removeEventListener('click', opening);
                copy.removeEventListener('click', copying);
                page.destroy();
            },
        };
    });
})();
