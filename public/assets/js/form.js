/**
 * 登录、安装及后台共用的表单验证适配；提示、聚焦和错误样式由 Layui form 负责。
 * HTML 约束仍是输入规则的单一声明，服务端验证决定业务是否可执行。
 */
(function (global) {
    'use strict';
    let configured = false;

    function prepare(root = document) {
        if (root.matches?.('form')) root.noValidate = true;
        root.querySelectorAll('form').forEach(form => form.noValidate = true);
        root.querySelectorAll('input:not([type=hidden]):not([type=file]),select,textarea').forEach(input => {
            if (['button','submit','reset'].includes(input.type) || input.closest('.layui-form-select')) return;
            const rules = (input.getAttribute('lay-verify') || '').split('|').filter(Boolean);
            if (!rules.includes('mpayConstraint')) rules.push('mpayConstraint');
            input.setAttribute('lay-verify', rules.join('|'));
        });
    }

    function configure() {
        if (configured) return;
        configured = true;
        layui.form.verify({
            mpayConstraint(value, input) {
                // 禁用、只读字段沿用 HTML 校验语义；只读取约束结果，不调用浏览器验证气泡。
                if (!input.willValidate || input.validity.valid) return;
                const label = input.labels?.[0]?.textContent.replace('ⓘ','').trim() || input.getAttribute('aria-label');
                return layui.util.escape((label ? label + '：' : '') + input.validationMessage);
            }
        });
    }

    function valid(root) {
        configure();
        prepare(root);
        return layui.form.validate(layui.$(root));
    }

    function ready(callback) {
        layui.use(['form','util'], () => { configure(); prepare(); callback(); });
    }

    global.MpayForm = {prepare, valid, ready};
})(window);
