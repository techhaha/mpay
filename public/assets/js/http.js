/**
 * 后台/收银台同源 JSON 请求边界。易支付、设备 ACK 和文件下载使用各自客户端合同。
 * 请求失败不代表交易失败；本模块不自动重试写操作，也不判断支付终态。
 */
(function (global) {
    'use strict';
    let sessionExpired = false;
    // 只抑制当前页面同一方法/URL 的并行提交，不承担刷新后或跨进程的业务幂等。
    const pending = new Set();
    /** 保存稳定应用码、字段错误和关联号，页面不应通过解析 message 文案判断业务分支。 */
    class ApiError extends Error {
        constructor(message, code, data, requestId) {
            super(message); this.code = code; this.data = data; this.requestId = requestId;
        }
    }
    /**
     * 发送一次请求。data 为写请求 JSON 或 FormData，timeout 单位为毫秒，默认 15000。
     * 成功返回原响应对象；202 增加 processing 标志，调用方应查询原执行而非再次下单。
     *
     * @param {string} path 本站相对路径或同源绝对 URL。
     * @param {{method?: string, headers?: Object<string, string>, data?: Object|FormData, timeout?: number}} options
     * @returns {Promise<Object>} 具体接口载荷由调用页面识别，通用层不改写 data。
     * @throws {ApiError} 协议、HTTP、字段、超时或重复提交错误；网络异常保留原错误。
     */
    async function request(path, options = {}) {
        const url = new URL(path, location.origin);
        if (url.origin !== location.origin) throw new ApiError('仅允许同源请求', 40000);
        const method = (options.method || 'GET').toUpperCase();
        const write = !['GET', 'HEAD'].includes(method);
        const key = method + ':' + url.href;
        if (write && pending.has(key)) throw new ApiError('请求正在提交，请勿重复操作', 40900);
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), options.timeout || 15000);
        if (write) pending.add(key);
        try {
            const headers = {Accept: 'application/json', ...options.headers};
            const multipart = write && options.data instanceof FormData;
            if (write) {
                headers['X-Csrf-Token'] = document.querySelector('meta[name="csrf-token"]')?.content || '';
                // multipart 的 boundary 由浏览器生成；上传仍复用同源、超时、登录过期及重复提交处理。
                if (multipart) {
                    Object.keys(headers).forEach(key => { if (key.toLowerCase() === 'content-type') delete headers[key]; });
                } else headers['Content-Type'] = 'application/json';
            }
            const response = await fetch(url, {method, headers, credentials: 'same-origin', signal: controller.signal,
                body: write ? (multipart ? options.data : JSON.stringify(options.data || {})) : undefined});
            let body;
            // 非 2xx 也先读取统一错误体；登录 HTML 或代理错误页不能作为 JSON 成功继续。
            try { body = await response.json(); } catch (_) { throw new ApiError('服务响应格式异常', 50000, null, response.headers.get('X-Request-Id')); }
            // 多个并行请求同时过期只发一次事件，由页面统一提示/跳转，不在请求模块反复操作 UI。
            if (body.code === 40100 && !sessionExpired) {
                sessionExpired = true;
                global.dispatchEvent(new CustomEvent('mpay:session-expired', {detail: body}));
            }
            // HTTP 状态和应用码同时满足才认定处理中，不能把任意成功解析的响应当作操作完成。
            if (response.status === 202 && body.code === 20200) return {...body, processing: true};
            if (!response.ok || body.code !== 200) throw new ApiError(body.message || '请求未完成', body.code, body.data, body.request_id);
            return body;
        } catch (error) {
            // Abort 只停止浏览器等待，服务端可能已执行，不能提示“支付失败”或自动重发。
            if (error.name === 'AbortError') throw new ApiError('请求超时，请查询原操作结果', 0);
            throw error;
        } finally { clearTimeout(timer); if (write) pending.delete(key); }
    }
    /** 仅把 code=200 的分页结果适配为 Layui 格式；错误和处理中不能伪装成空表成功。 */
    function tableData(body) {
        if (body.code !== 200 || !body.data || !Array.isArray(body.data.items)) throw new ApiError(body.message || '列表加载失败', body.code, body.data, body.request_id);
        return {code: 0, msg: body.message, count: body.data.total, data: body.data.items};
    }
    /**
     * 有界后台文件下载；错误仍按统一 JSON 解释，不能把登录页或错误 JSON 保存成 CSV。
     * @returns {Promise<number>} 浏览器已读取的文件字节数；不承诺用户已经完成磁盘保存。
     */
    async function download(path, filename) {
        const url = new URL(path, location.origin);
        if (url.origin !== location.origin || !/^[a-zA-Z0-9_.-]{1,80}$/.test(filename)) throw new ApiError('下载参数无效', 40000);
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 60000);
        try {
            const response = await fetch(url, {credentials:'same-origin', signal:controller.signal, headers:{Accept:'text/csv'}});
            if (!response.ok || !response.headers.get('Content-Type')?.startsWith('text/csv')) {
                let body;
                try { body = await response.json(); } catch (_) { throw new ApiError('下载响应格式异常', 50000); }
                if (body.code === 40100 && !sessionExpired) {
                    sessionExpired = true; global.dispatchEvent(new CustomEvent('mpay:session-expired', {detail:body}));
                }
                throw new ApiError(body.message || '下载未完成', body.code, body.data, body.request_id);
            }
            const file = await response.blob();
            if (file.size === 0) throw new ApiError('导出内容为空，请重新导出或联系管理员检查日志', 50000);
            const objectUrl = URL.createObjectURL(file);
            const link = document.createElement('a'); link.href = objectUrl; link.download = filename;
            document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
            return file.size;
        } catch (error) {
            if (error.name === 'AbortError') throw new ApiError('下载超时，请缩小范围后重试', 0);
            throw error;
        } finally { clearTimeout(timer); }
    }
    global.MpayHttp = {request, tableData, download, ApiError};
})(window);
